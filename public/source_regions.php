<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/ai_source_archive.php';
require_admin();
ai_source_archive_tables($pdo);
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT);
if(!$id){http_response_code(400);exit('Ongeldige broncollectie.');}
$stmt=$pdo->prepare("SELECT c.id,c.title,c.topic_id FROM ai_source_collections c JOIN topics t ON t.id=c.topic_id WHERE c.id=? AND t.is_active=1");
$stmt->execute([$id]);$collection=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$collection){http_response_code(404);exit('Broncollectie niet gevonden.');}
$stmt=$pdo->prepare("SELECT id,title FROM ai_source_sections WHERE collection_id=? ORDER BY sort_order,id");
$stmt->execute([$id]);$sections=$stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt=$pdo->prepare("SELECT id,page_number,image_path FROM ai_source_pages WHERE collection_id=? ORDER BY page_number");
$stmt->execute([$id]);$pages=$stmt->fetchAll(PDO::FETCH_ASSOC);
$message='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals((string)($_SESSION['source_regions_token']??''),(string)($_POST['token']??''))){http_response_code(403);exit('Ongeldige sessie.');}
    $detected=[];
    foreach($pages as $page){
        $relative=(string)$page['image_path'];
        $base=realpath(__DIR__.'/uploads/ai_sources');
        $path=realpath(__DIR__.'/'.$relative);
        if(!$base||!$path||!str_starts_with($path,$base.DIRECTORY_SEPARATOR))continue;
        $titles=array_column($sections,'title');
        $prompt='Bekijk de foto in de juiste leesrichting. Identificeer uitsluitend zelfstandige VISUELE illustraties, foto\'s, tabellen of diagrammen die een leerstofonderdeel verduidelijken; geen tekstblokken, leerdoelen of paginatitels. Retourneer uitsluitend een JSON-object met regions. Elk gebied bevat title, type (image/diagram/table), x, y, width, height als fracties 0..1 van de originele ongedraaide foto en section_indices als 0-gebaseerde indices. Neem alleen grote, duidelijke rechthoeken met breedte en hoogte van minimaal 0.12 op. Bij twijfel geen gebied. Leerstofonderdelen: '.json_encode($titles,JSON_UNESCAPED_UNICODE);
        $response=openai_generate_topic_summary($prompt,[$path]);
        $data=is_array($response)&&!isset($response['_leren_error'])?openai_output_json($response):null;
        $raw=trim((string)($data['summary']??''));
        if(str_starts_with($raw,'```'))$raw=preg_replace('/^\x60{3}(?:json)?\s*|\s*\x60{3}$/u','',$raw);
        $parsed=json_decode($raw,true);
        foreach(array_slice((array)($parsed['regions']??[]),0,12) as $region){
            if(!is_array($region)||!in_array(($region['type']??''),['image','diagram','table'],true))continue;
            $x=(float)($region['x']??-1);$y=(float)($region['y']??-1);$w=(float)($region['width']??-1);$h=(float)($region['height']??-1);
            if(!is_finite($x)||!is_finite($y)||!is_finite($w)||!is_finite($h)||$x<0||$y<0||$w<0.12||$h<0.12||$x+$w>1||$y+$h>1)continue;
            $linked=[];
            foreach((array)($region['section_indices']??[]) as $index)if(is_int($index)&&isset($sections[$index]))$linked[]=(int)$sections[$index]['id'];
            if(!$linked)continue;
            $detected[]=['page_id'=>(int)$page['id'],'title'=>mb_substr(trim((string)($region['title']??'Gebied')),0,255),'type'=>$region['type'],'x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h,'sections'=>$linked];
        }
    }
    if($detected){
        $pdo->beginTransaction();
        try{
            $pdo->prepare("DELETE FROM ai_source_links WHERE collection_id=? AND link_type='section_region'")->execute([$id]);
            $pdo->prepare("DELETE r FROM ai_source_regions r JOIN ai_source_pages p ON p.id=r.page_id WHERE p.collection_id=? AND r.region_type<>'page'")->execute([$id]);
            foreach($detected as $region){
                $regionId=ai_source_region_add($pdo,$region['page_id'],$region['title'],$region['type'],$region['x'],$region['y'],$region['w'],$region['h']);
                foreach($region['sections'] as $sectionId)ai_source_region_link_section($pdo,$id,$sectionId,$regionId);
            }
            $pdo->commit();$message=count($detected).' brongebieden opgeslagen.';
        }catch(Throwable $e){$pdo->rollBack();$message='Opslaan mislukt: '.$e->getMessage();}
    }else{$message='Geen bruikbare brongebieden herkend. Bestaande gegevens zijn behouden.';}
}
$_SESSION['source_regions_token']=$_SESSION['source_regions_token']??bin2hex(random_bytes(16));
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Brongebieden herkennen</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-4" style="max-width:760px"><a href="summary.php?source=archive&amp;id=<?=$id?>">← Terug naar samenvatting</a><h1 class="h3 mt-3">Brongebieden herkennen</h1><p><?=e($collection['title'])?> · <?=count($pages)?> pagina's · <?=count($sections)?> onderdelen</p><?php if($message):?><div class="alert alert-info"><?=e($message)?></div><?php endif;?><p>Herken tekstblokken en afbeeldingen opnieuw. Bestaande vragen, samenvattingen en originele foto's blijven behouden. Herkenning kan onvolledig zijn.</p><form method="post"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="token" value="<?=e($_SESSION['source_regions_token'])?>"><button class="btn btn-primary" type="submit">Brongebieden herkennen</button></form></main></body></html>
