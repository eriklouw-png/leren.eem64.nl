<?php
require __DIR__.'/../app/bootstrap.php';
require_admin();

$topicId=filter_input(INPUT_GET,'topic_id',FILTER_VALIDATE_INT);
$subjectId=filter_input(INPUT_GET,'subject_id',FILTER_VALIDATE_INT);
if(!$topicId && !$subjectId){redirect('admin.php');}

if($topicId){
    $x=$pdo->prepare("SELECT tp.id topic_id,tp.name topic_name,s.id subject_id,s.name subject_name FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
    $x->execute([$topicId]);$context=$x->fetch();
    if(!$context){http_response_code(404);exit('Overhoring niet gevonden.');}
    $topicId=(int)$context['topic_id'];$subjectId=(int)$context['subject_id'];
    $topicName=$context['topic_name'];$subjectName=$context['subject_name'];
}else{
    $x=$pdo->prepare("SELECT id,name FROM subjects WHERE id=?");
    $x->execute([$subjectId]);$subject=$x->fetch();
    if(!$subject){http_response_code(404);exit('Vak niet gevonden.');}
    $subjectName=$subject['name'];$topicName='Algemeen';
}

$errors=[];$analysis=null;
if(isset($_SESSION['ai_test_analysis'])&&is_array($_SESSION['ai_test_analysis'])){
    $saved=$_SESSION['ai_test_analysis'];
    if(($saved['subject_id']??null)===$subjectId && ($saved['topic_id']??null)===$topicId){
        $analysis=$saved['analysis']??null;
    }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    if($action==='clear'){
        unset($_SESSION['ai_test_analysis']);
        redirect('ai_test_generator.php?'.($topicId?'topic_id='.$topicId:'subject_id='.$subjectId));
    }

    if($action==='generate'){
        $saved=$_SESSION['ai_test_analysis']??null;
        $selected=$_POST['subtests']??[];
        if(!is_array($saved)||($saved['subject_id']??null)!==$subjectId||($saved['topic_id']??null)!==$topicId){
            $errors[]='De eerdere AI-analyse is verlopen. Analyseer de pagina’s opnieuw.';
        }elseif(!is_array($selected)||!$selected){
            $errors[]='Selecteer minimaal één sub-test.';
        }else{
            $available=$saved['analysis']['subtests']??[];
            $chosen=[];
            foreach($selected as $index){
                if(!ctype_digit((string)$index))continue;
                $index=(int)$index;
                if(isset($available[$index]))$chosen[]=$available[$index];
            }
            if(!$chosen)$errors[]='De geselecteerde sub-tests zijn ongeldig.';
            if(count($chosen)>3)$errors[]='Je kunt maximaal drie sub-tests tegelijk genereren.';
            if(!$errors){
                $requested=[];
                foreach($chosen as $sub){
                    $count=max(1,min(15,(int)($sub['question_count']??10)));
                    $types=array_values(array_intersect((array)($sub['recommended_types']??[]),['mc','open']));
                    if(!$types)$types=['mc','open'];
                    $requested[]=['title'=>(string)$sub['title'],'description'=>(string)$sub['description'],'question_count'=>$count,'types'=>$types];
                }
                $prompt='Maak nu concrete oefentoetsvragen voor de geselecteerde sub-tests. Gebruik uitsluitend de informatie uit de schoolboekpagina’s. De voorgestelde leerstof en sub-tests zijn: '.json_encode($requested,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'. Maak per sub-test precies het gevraagde aantal vragen. Verdeel MC en open zo logisch mogelijk binnen de voorgestelde types. Bij mc moeten options exact vier antwoorden bevatten en correct_option de index van het juiste antwoord zijn; correct_answer moet exact gelijk zijn aan die optie. Bij open moet options leeg zijn, correct_option 0 zijn en accepted_answers minimaal één geldig antwoord bevatten. source_page is de pagina uit de geüploade set waarop de vraag het duidelijkst gebaseerd is. Zet use_image alleen op true als een afbeelding, kaart, schema of foto op die pagina echt relevant is voor het beantwoorden van de vraag.';
                $data=openai_generate_test_questions($prompt,(array)($saved['images']??[]));
                if(isset($data['_leren_error']))$errors[]=$data['_leren_error'];
                else{
                    $generated=openai_output_json($data);
                    if(!$generated||!isset($generated['subtests']))$errors[]='De AI gaf geen bruikbare vragen terug.';
                    else $_SESSION['ai_test_analysis']['generated']=$generated;
                }
            }
        }
        $analysis=$_SESSION['ai_test_analysis']['analysis']??$analysis;
    }

    if($action==='analyze'){
        if(!warm_ai())$errors[]='AI is niet beschikbaar. Controleer OPENAI_API_KEY en de AI-instellingen.';
        $files=$_FILES['pages']??null;
        $valid=[];
        $base=__DIR__.'/../storage/ai_test_pages';
        if(!is_dir($base)&&!@mkdir($base,0700,true))$errors[]='De tijdelijke opslagmap voor AI-pagina’s kon niet worden aangemaakt.';

        if(!$errors && $files && isset($files['name']) && is_array($files['name'])){
            $allowed=['image/jpeg','image/png','image/webp'];
            foreach($files['name'] as $i=>$name){
                if(count($valid)>=10)break;
                $error=(int)($files['error'][$i]??UPLOAD_ERR_NO_FILE);
                if($error===UPLOAD_ERR_NO_FILE)continue;
                if($error!==UPLOAD_ERR_OK){$errors[]='Een van de afbeeldingen kon niet worden geüpload.';continue;}
                $size=(int)($files['size'][$i]??0);
                if($size<1||$size>5*1024*1024){$errors[]='Elke afbeelding mag maximaal 5 MB zijn.';continue;}
                $tmp=(string)($files['tmp_name'][$i]??'');
                $info=@getimagesize($tmp);
                $mime=(string)($info['mime']??'');
                if(!$info||!in_array($mime,$allowed,true)){$errors[]='Alleen JPG, PNG en WebP-afbeeldingen zijn toegestaan.';continue;}
                $ext=$mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
                $path=$base.'/'.bin2hex(random_bytes(16)).'.'.$ext;
                if(!@move_uploaded_file($tmp,$path)){$errors[]='Een afbeelding kon niet veilig worden opgeslagen.';continue;}
                $valid[]=$path;
            }
            if(!$valid&&!$errors)$errors[]='Selecteer minimaal één afbeelding.';
        }elseif(!$errors){
            $errors[]='Selecteer minimaal één afbeelding.';
        }

        if(!$errors){
            $prompt='Analyseer de geüploade schoolboekpagina’s en maak een voorstel voor oefentoetsen. Identificeer het vak en onderwerp, vat de stof kort samen, geef de belangrijkste leerpunten en stel logische afzonderlijke sub-tests voor. Gebruik uitsluitend informatie uit de pagina’s. Houd de voorstellen geschikt voor een leerling van ongeveer 12-15 jaar. Een sub-test bevat idealiter 8-15 vragen. Gebruik "mc" voor multiple choice en "open" voor open vragen. Geef alleen JSON volgens het opgegeven schema.';
            $data=openai_generate_with_images($prompt,$valid);
            if(isset($data['_leren_error'])){
                foreach($valid as $path)@unlink($path);
                $errors[]=$data['_leren_error'];
            }else{
                $analysis=openai_output_json($data);
                if(!$analysis||!isset($analysis['subtests'])){
                    foreach($valid as $path)@unlink($path);
                    $errors[]='De AI gaf geen bruikbaar analyse-resultaat terug.';
                    $analysis=null;
                }else{
                    $_SESSION['ai_test_analysis']=[
                        'subject_id'=>$subjectId,
                        'topic_id'=>$topicId,
                        'analysis'=>$analysis,
                        'images'=>$valid,
                        'created_at'=>time()
                    ];
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI toets maken</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.dropzone{border:2px dashed #adb5bd;border-radius:.75rem;padding:2rem;text-align:center;background:#fff;cursor:pointer}.dropzone:hover{border-color:#2f7d4a;background:#f8fbf9}.analysis-card{border-left:4px solid #2f7d4a}</style>
</head><body class="bg-light"><main class="container py-4" style="max-width:1000px">
<a href="subject_manage.php?id=<?=$subjectId?>">&larr; <?=e($subjectName)?></a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<h1 class="h3 mb-1">AI toets maken</h1>
<div class="text-secondary mb-4">Vak: <strong><?=e($subjectName)?></strong> · Onderwerp: <strong><?=e($topicName)?></strong></div>
<?php foreach($errors as $error):?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach;?>

<?php if(!$analysis):?>
<div class="alert alert-info">Upload foto’s van de relevante pagina’s uit het boek. De AI leest de pagina’s en maakt eerst een voorstel. Er wordt nog niets in de database opgeslagen.</div>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="analyze">
<label class="dropzone d-block mb-3" for="pages">
<div class="fs-1">📷</div><strong>Foto’s van boekpagina’s kiezen</strong>
<div class="text-secondary small mt-1">JPG, PNG of WebP · maximaal 10 pagina’s · maximaal 5 MB per afbeelding</div>
<input class="d-none" id="pages" type="file" name="pages[]" accept="image/jpeg,image/png,image/webp" multiple required>
</label>
<div id="fileList" class="small text-secondary mb-3"></div>
<button class="btn btn-primary" type="submit">Analyseer met AI</button>
<a class="btn btn-outline-secondary" href="subject_manage.php?id=<?=$subjectId?>">Annuleren</a>
</form>
<?php else:?>
<div class="d-flex justify-content-between align-items-start gap-3 mb-3">
<div><h2 class="h4 mb-1"><?=e($analysis['subject'])?> — <?=e($analysis['topic'])?></h2><p class="mb-0 text-secondary"><?=e($analysis['summary'])?></p></div>
<form method="post"><input type="hidden" name="action" value="clear"><button class="btn btn-outline-secondary btn-sm">Nieuwe analyse</button></form>
</div>
<h3 class="h5 mt-4">Belangrijkste leerpunten</h3>
<ul><?php foreach(($analysis['learning_points']??[]) as $point):?><li><?=e($point)?></li><?php endforeach;?></ul>
<h3 class="h5 mt-4">Voorgestelde sub-tests</h3>
<form method="post">
<input type="hidden" name="action" value="generate">
<?php foreach(($analysis['subtests']??[]) as $i=>$sub):?>
<label class="card analysis-card mb-3"><div class="card-body">
<div class="form-check">
<input class="form-check-input" type="checkbox" name="subtests[]" value="<?=$i?>" id="subtest<?=$i?>" checked>
<span class="form-check-label d-block" for="subtest<?=$i?>">
<span class="d-flex justify-content-between gap-3"><span><strong><?=e($sub['title'])?></strong><br><span class="text-secondary"><?=e($sub['description'])?></span></span><span class="badge text-bg-light align-self-start"><?=e((string)$sub['question_count'])?> vragen</span></span>
<span class="small text-secondary">Voorgestelde vraagtypes: <?=e(implode(', ',(array)($sub['recommended_types']??[])))?></span>
</span>
</div>
</div></label>
<?php endforeach;?>
<button class="btn btn-primary" type="submit">Genereer geselecteerde vragen met AI</button>
</form>
<?php if(isset($_SESSION['ai_test_analysis']['generated']['subtests'])):?>
<hr class="my-4">
<h3 class="h5">Gegenereerde vragen</h3>
<div class="alert alert-warning">Controleer deze vragen eerst. Er is nog niets in de database opgeslagen.</div>
<?php foreach($_SESSION['ai_test_analysis']['generated']['subtests'] as $generatedSub):?>
<div class="card mb-3"><div class="card-body">
<h4 class="h6"><?=e($generatedSub['title'])?></h4>
<?php foreach(($generatedSub['questions']??[]) as $qi=>$q):?>
<div class="border-top pt-3 mt-3">
<div><strong><?=($qi+1)?>. <?=e($q['question'])?></strong> <span class="badge text-bg-light"><?=e(strtoupper($q['type']))?></span></div>
<?php if($q['type']==='mc'):?>
<ol class="mb-1" type="A"><?php foreach(($q['options']??[]) as $oi=>$option):?><li class="<?=$oi===(int)$q['correct_option']?'fw-bold':''?>"><?=e($option)?><?=$oi===(int)$q['correct_option']?' ✓':''?></li><?php endforeach;?></ol>
<?php else:?><div class="small text-secondary mt-1">Juiste antwoord: <?=e($q['correct_answer'])?></div><?php endif;?>
<div class="small text-secondary">Uitleg: <?=e($q['explanation'])?> · Bronpagina: <?=e((string)$q['source_page'])?><?=!empty($q['use_image'])?' · afbeelding gebruiken':''?></div>
</div>
<?php endforeach;?>
</div></div>
<?php endforeach;?>
<?php endif;?>
<div class="alert alert-success mt-4 mb-0"><strong>Veilige tussenstap:</strong> de analyse en gegenereerde vragen staan alleen in deze sessie. De volgende stap kan de geselecteerde vragen laten aanpassen en pas daarna een nieuwe sub-test in de database aanmaken.</div>
<?php endif;?>
</div></div></main>
<script>
const input=document.getElementById('pages'),list=document.getElementById('fileList');
if(input)input.addEventListener('change',()=>{list.textContent=[...input.files].map(f=>f.name+' ('+Math.round(f.size/1024)+' KB)').join(' · ')});
</script></body></html>