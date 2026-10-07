<?php
require __DIR__.'/../app/bootstrap.php';
require_admin();

$days=(int)($_GET['days']??30);

// Upgrade bestaande AI-log automatisch. Oude installaties hebben nog geen user_id.
try{
    $hasUserId=$pdo->query("SHOW COLUMNS FROM ai_usage LIKE 'user_id'")->fetch();
    if(!$hasUserId){
        $pdo->exec("ALTER TABLE ai_usage ADD COLUMN user_id INT UNSIGNED NULL AFTER created_at");
    }
    try{$pdo->exec("ALTER TABLE ai_usage ADD KEY idx_ai_usage_user(created_at,user_id)");}catch(Throwable $ignored){}
}catch(Throwable $e){
    // De pagina kan ook zonder gebruikerskoppeling de historische totalen tonen.
}

$days=(int)($_GET['days']??30);
if(!in_array($days,[7,30,90,365],true))$days=30;

function ai_usage_cost(array $row):float{
    $model=strtolower((string)($row['model']??''));
    $input=(float)($row['input_tokens']??0);
    $cached=(float)($row['cached_input_tokens']??0);
    $output=(float)($row['output_tokens']??0);
    $callType=(string)($row['call_type']??'');

    // Current OpenAI standard token prices in USD per 1M tokens.
    $rates=[
        'gpt-6-luna'=>[0.10,0.01,0.50],
        'gpt-6-sol'=>[2.00,0.20,10.00],
        'gpt-6-astra'=>[10.00,1.00,50.00],
        'gpt-6.1-sol'=>[2.00,0.10,10.00],
        'gpt-5.6-luna'=>[0.20,0.02,1.20],
        'gpt-5.6-terra'=>[2.00,0.20,12.00],
        'gpt-5.6-sol'=>[4.00,0.40,20.00],
    ];
    $cost=0.0;
    if(isset($rates[$model])){
        [$inputRate,$cachedRate,$outputRate]=$rates[$model];
        $uncached=max(0,$input-$cached);
        $cost+=($uncached/1000000)*$inputRate;
        $cost+=($cached/1000000)*$cachedRate;
        $cost+=($output/1000000)*$outputRate;
    }
    // Web search has a separate current tool charge of $10 per 1,000 calls.
    if($callType==='image_web_search')$cost+=0.01;
    // Direct GPT Image 2 low-quality 1024x1024 generation has a published
    // base image-generation estimate of $0.006 per image. Token usage, when
    // returned by the API, is added above.
    if($callType==='image_generation' && $input===0.0 && $output===0.0)$cost+=0.006;
    return $cost;
}

$since=(new DateTimeImmutable('now'))->modify('-'.$days.' days')->format('Y-m-d H:i:s');
$previousSince=(new DateTimeImmutable($since))->modify('-'.$days.' days')->format('Y-m-d H:i:s');

$rows=$pdo->prepare("
    SELECT au.*,u.name AS user_name,u.role AS user_role
    FROM ai_usage au
    LEFT JOIN users u ON u.id=au.user_id
    WHERE au.created_at>=?
    ORDER BY au.created_at DESC
");
$rows->execute([$since]);
$rows=$rows->fetchAll();

$allCost=0.0;$successful=0;$failed=0;$totalTokens=0;
$byType=[];$byUser=[];$byModel=[];$byPage=[];$recent=[];
foreach($rows as $row){
    $cost=ai_usage_cost($row);
    $allCost+=$cost;
    $totalTokens+=(int)($row['total_tokens']??0);
    if((int)$row['success']===1)$successful++;else$failed++;
    $type=$row['call_type'];
    if(!isset($byType[$type]))$byType[$type]=['calls'=>0,'tokens'=>0,'cost'=>0.0,'dur'=>0,'success'=>0];
    $byType[$type]['calls']++;
    $byType[$type]['tokens']+=(int)($row['total_tokens']??0);
    $byType[$type]['cost']+=$cost;
    $byType[$type]['dur']+=(int)($row['duration_ms']??0);
    $byType[$type]['success']+=(int)$row['success'];
    $userKey=$row['user_name']?:'Niet gekoppeld (oude log)';
    if(!isset($byUser[$userKey]))$byUser[$userKey]=['calls'=>0,'tokens'=>0,'cost'=>0.0];
    $byUser[$userKey]['calls']++;
    $byUser[$userKey]['tokens']+=(int)($row['total_tokens']??0);
    $byUser[$userKey]['cost']+=$cost;
    $page=$row['page_name']?:'Onbekende locatie';
    if(!isset($byPage[$page]))$byPage[$page]=['calls'=>0,'tokens'=>0,'cost'=>0.0];
    $byPage[$page]['calls']++;
    $byPage[$page]['tokens']+=(int)($row['total_tokens']??0);
    $byPage[$page]['cost']+=$cost;
    $model=$row['model'];
    if(!isset($byModel[$model]))$byModel[$model]=['calls'=>0,'tokens'=>0,'cost'=>0.0];
    $byModel[$model]['calls']++;
    $byModel[$model]['tokens']+=(int)($row['total_tokens']??0);
    $byModel[$model]['cost']+=$cost;
    if(count($recent)<50)$recent[]=['row'=>$row,'cost'=>$cost];
}
uasort($byType,fn($a,$b)=>$b['cost']<=>$a['cost']);
uasort($byUser,fn($a,$b)=>$b['cost']<=>$a['cost']);
uasort($byPage,fn($a,$b)=>$b['cost']<=>$a['cost']);

$imageStats=['web'=>0,'svg'=>0,'generate'=>0,'none'=>0];
try{
    $imageStmt=$pdo->prepare("SELECT image_path,COUNT(*) AS aantal FROM questions WHERE created_at>=? GROUP BY image_path");
    $imageStmt->execute([$since]);
    foreach($imageStmt->fetchAll() as $img){
        $path=(string)($img['image_path']??''); $n=(int)$img['aantal'];
        if($path==='')$imageStats['none']+=$n;
        elseif(str_starts_with($path,'web_'))$imageStats['web']+=$n;
        elseif(str_starts_with($path,'svg_'))$imageStats['svg']+=$n;
        elseif(str_starts_with($path,'ai_'))$imageStats['generate']+=$n;
        else $imageStats['none']+=$n;
    }
}catch(Throwable $ignored){}

$prev=$pdo->prepare("SELECT * FROM ai_usage WHERE created_at>=? AND created_at<? ORDER BY created_at");
$prev->execute([$previousSince,$since]);
$prevRows=$prev->fetchAll();
$previousByType=[];
foreach($prevRows as $row){
    $type=$row['call_type'];
    if(!isset($previousByType[$type]))$previousByType[$type]=['calls'=>0,'tokens'=>0,'cost'=>0.0];
    $previousByType[$type]['calls']++;
    $previousByType[$type]['tokens']+=(int)($row['total_tokens']??0);
    $previousByType[$type]['cost']+=ai_usage_cost($row);
}

$trend=[];
foreach($byType as $type=>$data){
    $prevData=$previousByType[$type]??['calls'=>0,'tokens'=>0,'cost'=>0.0];
    $avgNow=$data['calls']?$data['cost']/$data['calls']:0;
    $avgPrev=$prevData['calls']?$prevData['cost']/$prevData['calls']:0;
    $change=$avgPrev>0?(($avgNow-$avgPrev)/$avgPrev*100):null;
    $trend[$type]=['now'=>$avgNow,'previous'=>$avgPrev,'change'=>$change,'calls'=>$data['calls'],'previous_calls'=>$prevData['calls']];
}

function ai_type_name(string $type):string{
    return [
        'test_query_analysis'=>'Query analyseren',
        'test_source_analysis'=>'Boekpagina’s analyseren',
        'generated_test_questions'=>'Toetsvragen genereren',
        'topic_summary'=>'Samenvatting maken',
        'open_answer_grade'=>'Open antwoord beoordelen',
        'image_web_search'=>'Afbeelding zoeken',
        'image_generation'=>'Afbeelding genereren',
    ][$type]??$type;
}
function ai_money(float $amount):string{return '$'.number_format($amount,4,'.',',');}
function ai_tokens(int $n):string{
    if($n>=1000000)return number_format($n/1000000,2,',','.').' M';
    if($n>=1000)return number_format($n/1000,1,',','.').'k';
    return number_format($n,0,',','.');
}
function ai_pct(?float $n):string{return $n===null?'—':(($n>0?'+':'').number_format($n,1,',','.').'%');}
?><!doctype html>
<html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI-verbruik - Beheer</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.ai-dashboard{max-width:1280px}.ai-stat{min-height:130px}.ai-stat .value{font-size:clamp(1.45rem,3vw,2rem);font-weight:700;line-height:1.15;word-break:break-word}.ai-small{font-size:.82rem}.trend-up{color:#f39c9c!important}.trend-down{color:#8fd19e!important}.ai-table th{white-space:nowrap}@media(max-width:575.98px){.ai-dashboard{padding-left:12px;padding-right:12px}.ai-stat{min-height:110px}.ai-stat .value{font-size:1.45rem}.ai-table{font-size:.86rem}.ai-table th,.ai-table td{padding:.55rem .45rem}}
.ai-table td,.ai-table th{vertical-align:middle}.mini-bar{height:8px;border-radius:999px;background:#465149;overflow:hidden}.mini-bar>span{display:block;height:100%;background:#3d9660}
</style></head><body>
<main class="container py-4 ai-dashboard"><div class="mb-3"><a href="admin.php" class="text-decoration-none">← Terug naar Beheer</a></div>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
<div><h1 class="mb-1">AI-verbruik</h1><p class="text-secondary mb-0">Inzicht in AI-opdrachten, verbruik en geschatte kosten.</p></div>
<div class="btn-group ai-period" role="group">
<?php foreach([7,30,90,365] as $option):?><a class="btn <?=$days===$option?'btn-primary':'btn-outline-primary'?>" href="?days=<?=$option?>"><?=$option===365?'1 jaar':$option.' dagen'?></a><?php endforeach;?>
</div>
</div>

<div class="row g-3 mb-4">
<div class="col-6 col-lg-3"><div class="card ai-stat"><div class="card-body"><div class="text-secondary">Geschatte kosten</div><div class="value"><?=ai_money($allCost)?></div><div class="ai-small text-secondary">laatste <?=$days?> dagen</div></div></div></div>
<div class="col-6 col-lg-3"><div class="card ai-stat"><div class="card-body"><div class="text-secondary">AI-opdrachten</div><div class="value"><?=number_format(count($rows),0,',','.')?></div><div class="ai-small text-secondary"><?=$successful?> geslaagd · <?=$failed?> mislukt</div></div></div></div>
<div class="col-6 col-lg-3"><div class="card ai-stat"><div class="card-body"><div class="text-secondary">Tokens</div><div class="value"><?=ai_tokens($totalTokens)?></div><div class="ai-small text-secondary">geregistreerd</div></div></div></div>
<div class="col-6 col-lg-3"><div class="card ai-stat"><div class="card-body"><div class="text-secondary">Duurste opdracht</div><div class="value"><?=e($byType?ai_type_name(array_key_first($byType)):'—')?></div><div class="ai-small text-secondary"><?=ai_money($byType?current($byType)['cost']:0)?> totaal</div></div></div></div>
</div>

<div class="card mb-4"><div class="card-body">
<h2 class="h5">AI-opdrachten per locatie</h2>
<p class="text-secondary">Hier zie je afzonderlijk waar de AI wordt aangeroepen. De verschillende stappen van het maken van een AI-toets blijven daardoor van elkaar te onderscheiden.</p>
<div class="table-responsive"><table class="table table-sm ai-table mb-0"><thead><tr><th>Locatie</th><th class="text-end">Calls</th><th class="text-end">Tokens</th><th class="text-end">Kosten</th></tr></thead><tbody>
<?php foreach($byPage as $page=>$d): $pageLabel=['quiz.php'=>'Quiz / leerling','ai_test_generator.php'=>'AI-toets maken','ai_generate_image.php'=>'Afbeeldingen AI-toets'][$page]??$page;?><tr><td><?=e($pageLabel)?></td><td class="text-end"><?=$d['calls']?></td><td class="text-end"><?=ai_tokens($d['tokens'])?></td><td class="text-end fw-semibold"><?=ai_money($d['cost'])?></td></tr><?php endforeach;?>
<?php if(!$byPage):?><tr><td colspan="4" class="text-secondary">Nog geen locatiegegevens.</td></tr><?php endif;?>
</tbody></table></div>
</div></div>

<div class="card mb-4"><div class="card-body">
<h2 class="h5">Afbeeldingen per methode</h2>
<p class="text-secondary">Afzonderlijk zichtbaar hoeveel afbeeldingen als internetafbeelding, SVG of door GPT zijn gemaakt.</p>
<div class="row g-3">
<div class="col-6 col-lg-3"><div class="p-3 rounded" style="background:#252e28"><div class="text-secondary">Internet</div><div class="fs-3 fw-bold"><?=$imageStats['web']?></div></div></div>
<div class="col-6 col-lg-3"><div class="p-3 rounded" style="background:#252e28"><div class="text-secondary">SVG</div><div class="fs-3 fw-bold"><?=$imageStats['svg']?></div></div></div>
<div class="col-6 col-lg-3"><div class="p-3 rounded" style="background:#252e28"><div class="text-secondary">GPT gegenereerd</div><div class="fs-3 fw-bold"><?=$imageStats['generate']?></div></div></div>
<div class="col-6 col-lg-3"><div class="p-3 rounded" style="background:#252e28"><div class="text-secondary">Geen afbeelding</div><div class="fs-3 fw-bold"><?=$imageStats['none']?></div></div></div>
</div>
</div></div>

<div class="card mb-4"><div class="card-body">
<h2 class="h5">Welke opdrachten kosten het meest?</h2>
<p class="text-secondary">Gesorteerd op totale geschatte kosten. De berekening gebruikt de actuele OpenAI-tarieven voor het geregistreerde model; web search heeft daarnaast een aparte toolprijs.</p>
<div class="table-responsive"><table class="table table-sm ai-table mb-0"><thead><tr><th>Opdracht</th><th class="text-end">Calls</th><th class="text-end">Tokens</th><th class="text-end">Gem. / call</th><th class="text-end">Totaal</th></tr></thead><tbody>
<?php foreach($byType as $type=>$d):?><tr><td><?=e(ai_type_name($type))?></td><td class="text-end"><?=number_format($d['calls'],0,',','.')?></td><td class="text-end"><?=ai_tokens($d['tokens'])?></td><td class="text-end"><?=ai_money($d['calls']?$d['cost']/$d['calls']:0)?></td><td class="text-end fw-semibold"><?=ai_money($d['cost'])?></td></tr><?php endforeach;?>
<?php if(!$byType):?><tr><td colspan="5" class="text-secondary">Nog geen AI-verbruik geregistreerd.</td></tr><?php endif;?>
</tbody></table></div>
</div></div>

<div class="card mb-4"><div class="card-body">
<h2 class="h5">Worden dezelfde opdrachten goedkoper?</h2>
<p class="text-secondary">Vergelijking van de gemiddelde kosten per call met de voorgaande periode van <?=$days?> dagen.</p>
<div class="table-responsive"><table class="table table-sm ai-table mb-0"><thead><tr><th>Opdracht</th><th class="text-end">Nu / call</th><th class="text-end">Vorige / call</th><th class="text-end">Verschil</th><th>Beoordeling</th></tr></thead><tbody>
<?php foreach($trend as $type=>$d):?><tr>
<td><?=e(ai_type_name($type))?></td><td class="text-end"><?=ai_money($d['now'])?></td><td class="text-end"><?=ai_money($d['previous'])?></td>
<td class="text-end <?=$d['change']!==null&&$d['change']<0?'trend-down':'trend-up'?>"><?=ai_pct($d['change'])?></td>
<td><?php if($d['change']===null):?>Geen eerdere vergelijkingsdata<?php elseif($d['change']<-1):?>Goedkoper<?php elseif($d['change']>1):?>Duurder<?php else:?>Ongeveer gelijk<?php endif;?></td>
</tr><?php endforeach;?>
<?php if(!$trend):?><tr><td colspan="5" class="text-secondary">Nog onvoldoende gegevens voor een vergelijking.</td></tr><?php endif;?>
</tbody></table></div>
</div></div>

<div class="row g-4 mb-4">
<div class="col-lg-6"><div class="card h-100"><div class="card-body">
<h2 class="h5">Verdeling per student/gebruiker</h2>
<p class="text-secondary">AI-calls die vanaf nu aan de ingelogde gebruiker worden gekoppeld.</p>
<?php foreach($byUser as $name=>$d): $width=$allCost>0?min(100,$d['cost']/$allCost*100):0; ?>
<div class="mb-3"><div class="d-flex justify-content-between"><strong><?=e($name)?></strong><span><?=ai_money($d['cost'])?></span></div><div class="mini-bar mt-1"><span style="width:<?=$width?>%"></span></div><div class="ai-small text-secondary"><?=$d['calls']?> calls · <?=ai_tokens($d['tokens'])?> tokens</div></div>
<?php endforeach;?>
<?php if(!$byUser):?><div class="text-secondary">Nog geen gebruikersdata.</div><?php endif;?>
</div></div></div>
<div class="col-lg-6"><div class="card h-100"><div class="card-body">
<h2 class="h5">Per model</h2>
<div class="table-responsive"><table class="table table-sm ai-table"><thead><tr><th>Model</th><th class="text-end">Calls</th><th class="text-end">Tokens</th><th class="text-end">Kosten</th></tr></thead><tbody>
<?php foreach($byModel as $model=>$d):?><tr><td><?=e($model)?></td><td class="text-end"><?=$d['calls']?></td><td class="text-end"><?=ai_tokens($d['tokens'])?></td><td class="text-end"><?=ai_money($d['cost'])?></td></tr><?php endforeach;?>
</tbody></table></div>
</div></div></div>
</div>

<div class="card"><div class="card-body">
<h2 class="h5">Laatste AI-opdrachten</h2>
<div class="table-responsive"><table class="table table-sm ai-table mb-0"><thead><tr><th>Datum</th><th>Opdracht</th><th>Gebruiker</th><th>Model</th><th class="text-end">Tokens</th><th class="text-end">Kosten</th><th>Status</th></tr></thead><tbody>
<?php foreach($recent as $item):$row=$item['row'];?><tr>
<td><?=e(date('d-m H:i',strtotime($row['created_at'])))?></td><td><?=e(ai_type_name((string)$row['call_type']))?></td><td><?=e($row['user_name']?:'Onbekend')?></td><td><?=e($row['model'])?></td><td class="text-end"><?=ai_tokens((int)($row['total_tokens']??0))?></td><td class="text-end"><?=ai_money($item['cost'])?></td><td><?=((int)$row['success']===1)?'✓':'Mislukt'?></td>
</tr><?php endforeach;?>
<?php if(!$recent):?><tr><td colspan="7" class="text-secondary">Geen recente AI-opdrachten.</td></tr><?php endif;?>
</tbody></table></div>
</div></div>

<div class="small text-secondary mt-3">Kosten zijn schattingen in USD op basis van de actuele standaard API-tarieven. Historische logs zonder gebruiker blijven als “Niet gekoppeld” zichtbaar. Toolkosten en beeldgeneratie kunnen naast tokenverbruik een aparte prijscomponent hebben.</div>
</main></body></html>