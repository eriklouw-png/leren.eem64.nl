<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/ai_source_archive.php';
require_manager();
ai_source_archive_tables($pdo);
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT);
if(!$id){http_response_code(400);exit('Ongeldige samenvatting.');}
$collectionQuery=$pdo->prepare("SELECT id,title FROM ai_source_collections WHERE id=?");
$collectionQuery->execute([$id]);
$collection=$collectionQuery->fetch(PDO::FETCH_ASSOC);
if(!$collection){http_response_code(404);exit('Samenvatting niet gevonden.');}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals((string)($_SESSION['region_edit_token']??''),(string)($_POST['token']??''))){http_response_code(403);exit('Ongeldige sessie.');}
    $regionId=filter_var($_POST['region_id']??0,FILTER_VALIDATE_INT);
    $check=$pdo->prepare("SELECT r.id FROM ai_source_regions r JOIN ai_source_pages p ON p.id=r.page_id WHERE r.id=? AND p.collection_id=?");
    $check->execute([$regionId,$id]);
    if(!$check->fetchColumn()){http_response_code(404);exit('Brongebied niet gevonden.');}
    $action=(string)($_POST['action']??'save');
    if($action==='delete'){
        $pdo->beginTransaction();
        try{
            $pdo->prepare("DELETE FROM ai_source_links WHERE collection_id=? AND region_id=?")->execute([$id,$regionId]);
            $pdo->prepare("DELETE FROM ai_source_regions WHERE id=?")->execute([$regionId]);
            $pdo->commit();
        }catch(Throwable $e){$pdo->rollBack();throw $e;}
    }else{
        $title=trim((string)($_POST['title']??''));
        $rotation=(int)($_POST['rotation']??0);
        $sectionId=(int)($_POST['section_id']??0);
        $coords=[];
        foreach(['x','y','width','height'] as $key)$coords[$key]=filter_var($_POST[$key]??null,FILTER_VALIDATE_FLOAT);
        $valid=$coords['x']!==false && $coords['y']!==false && $coords['width']!==false && $coords['height']!==false
            && $coords['x']>=0 && $coords['y']>=0 && $coords['width']>0 && $coords['height']>0
            && $coords['x']+$coords['width']<=1.00001 && $coords['y']+$coords['height']<=1.00001;
        if($sectionId){
            $checkSection=$pdo->prepare("SELECT id FROM ai_source_sections WHERE id=? AND collection_id=?");
            $checkSection->execute([$sectionId,$id]);
            $valid=$valid && (bool)$checkSection->fetchColumn();
        }
        if(!$valid || !in_array($rotation,[0,90,180,270],true))$error='Controleer de uitsnede, draaihoek en het onderdeel.';
        else{
            $pdo->beginTransaction();
            try{
                $pdo->prepare("UPDATE ai_source_regions SET title=?,rotation_degrees=?,x=?,y=?,width=?,height=? WHERE id=?")
                    ->execute([$title?:'Afbeelding',$rotation,$coords['x'],$coords['y'],$coords['width'],$coords['height'],$regionId]);
                $pdo->prepare("DELETE FROM ai_source_links WHERE collection_id=? AND region_id=? AND link_type='section_region'")->execute([$id,$regionId]);
                if($sectionId)$pdo->prepare("INSERT INTO ai_source_links(collection_id,section_id,region_id,link_type) VALUES(?,?,?,'section_region')")->execute([$id,$sectionId,$regionId]);
                $pdo->commit();
            }catch(Throwable $e){$pdo->rollBack();throw $e;}
        }
    }
    if($error==='')redirect('source_region_manage.php?id='.$id);
}
$_SESSION['region_edit_token']=$_SESSION['region_edit_token']??bin2hex(random_bytes(16));
$sectionsQuery=$pdo->prepare("SELECT id,title FROM ai_source_sections WHERE collection_id=? ORDER BY sort_order,id");
$sectionsQuery->execute([$id]);$sections=$sectionsQuery->fetchAll(PDO::FETCH_ASSOC);
$regionsQuery=$pdo->prepare("SELECT r.*,p.page_number,(SELECT l.section_id FROM ai_source_links l WHERE l.region_id=r.id AND l.collection_id=? AND l.link_type='section_region' ORDER BY l.id LIMIT 1) AS section_id FROM ai_source_regions r JOIN ai_source_pages p ON p.id=r.page_id WHERE p.collection_id=? AND r.region_type IN ('image','diagram','table') ORDER BY p.page_number,r.id");
$regionsQuery->execute([$id,$id]);$regions=$regionsQuery->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bronafbeeldingen beheren</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4" style="max-width:900px">
<a href="summary_edit.php?id=<?=$id?>">&larr; Terug naar samenvatting bewerken</a>
<h1 class="h3 mt-3">Bronafbeeldingen beheren</h1><p><?=e($collection['title'])?></p>
<?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
<p class="text-secondary">Pas de uitsnede aan met waarden van 0 tot 1, draai de afbeelding of koppel haar aan een ander leerstofonderdeel. Wijzigingen worden direct in de samenvatting getoond.</p>
<?php foreach($regions as $region):?>
<div class="card mb-3"><div class="card-body">
<h2 class="h6">Pagina <?=(int)$region['page_number']?> · <?=e($region['title'])?></h2>
<img class="img-fluid rounded border mb-3" style="max-height:340px" src="source_region_image.php?collection=<?=$id?>&amp;id=<?=(int)$region['id']?>&amp;v=<?=time()?>" alt="<?=e($region['title'])?>">
<form method="post">
<input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="region_id" value="<?=(int)$region['id']?>"><input type="hidden" name="token" value="<?=e($_SESSION['region_edit_token'])?>">
<label class="form-label">Naam</label><input class="form-control mb-2" name="title" value="<?=e($region['title'])?>" required>
<div class="row g-2 mb-2">
<?php foreach(['x','y','width','height'] as $key):?>
<div class="col-6 col-md-3"><label class="form-label"><?=e($key)?></label><input class="form-control" type="number" min="0" max="1" step="0.001" name="<?=$key?>" value="<?=e((string)$region[$key])?>" required></div>
<?php endforeach;?>
</div>
<div class="row g-2 mb-3"><div class="col-md-5"><label class="form-label">Draaien</label><select class="form-select" name="rotation">
<?php foreach([0=>'Automatisch',90=>'90° links',180=>'180°',270=>'90° rechts'] as $angle=>$label):?>
<option value="<?=$angle?>" <?=((int)$region['rotation_degrees']===$angle)?'selected':''?>><?=e($label)?></option>
<?php endforeach;?></select></div>
<div class="col-md-7"><label class="form-label">Leerstofonderdeel</label><select class="form-select" name="section_id"><option value="0">Geen koppeling</option>
<?php foreach($sections as $section):?><option value="<?=(int)$section['id']?>" <?=((int)$region['section_id']===(int)$section['id'])?'selected':''?>><?=e($section['title'])?></option><?php endforeach;?></select></div></div>
<div class="d-flex gap-2"><button class="btn btn-primary" name="action" value="save">Opslaan</button><button class="btn btn-outline-danger ms-auto" name="action" value="delete" onclick="return confirm('Deze uitsnede verwijderen?')">Verwijderen</button></div>
</form></div></div>
<?php endforeach;?>
<?php if(!$regions):?><p>Er zijn nog geen herkende afbeeldingen. Gebruik eerst Brongebieden herkennen.</p><?php endif;?>
</main></body></html>
