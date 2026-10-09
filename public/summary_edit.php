<?php
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/ai_source_archive.php';
require_admin();
ai_source_archive_tables($pdo);
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:0;
$topicId=filter_input(INPUT_GET,'topic_id',FILTER_VALIDATE_INT)?:0;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT)?:0;
    $topicId=filter_var($_POST['topic_id']??0,FILTER_VALIDATE_INT)?:0;
    $action=(string)($_POST['action']??'save');
    if($id){
        $stmt=$pdo->prepare("SELECT id,topic_id,subject_id FROM ai_source_collections WHERE id=?");
        $stmt->execute([$id]);$collection=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$collection){http_response_code(404);exit('Samenvatting niet gevonden.');}
        $topicId=(int)$collection['topic_id'];$subjectId=(int)$collection['subject_id'];
    }else{
        $stmt=$pdo->prepare("SELECT subject_id FROM topics WHERE id=? AND is_active=1");
        $stmt->execute([$topicId]);$subjectId=(int)$stmt->fetchColumn();
        if(!$subjectId){http_response_code(404);exit('Overhoring niet gevonden.');}
    }
    if($action==='delete' && $id){
        $pdo->prepare("DELETE FROM ai_source_sections WHERE collection_id=?")->execute([$id]);
        // Keep provenance, images and question links intact.
    }elseif($action==='save'){
        $name=trim((string)($_POST['name']??''));
        if($name===''){http_response_code(400);exit('Naam is verplicht.');}
        $sectionTitles=(array)($_POST['section_title']??[]);
        $sectionBodies=(array)($_POST['section_summary']??[]);
        $sectionIds=(array)($_POST['section_id']??[]);
        $items=[];
        foreach($sectionBodies as $i=>$body){
            $body=trim((string)$body);
            if($body==='')continue;
            $items[]=['id'=>(int)($sectionIds[$i]??0),'title'=>trim((string)($sectionTitles[$i]??''))?:$name,'body'=>$body];
        }
        if(!$items){http_response_code(400);exit('Vul minimaal één onderdeel in.');}
        $pdo->beginTransaction();
        try{
            $isNew=$id===0;
            if($isNew)$id=ai_source_manual_summary_create($pdo,$subjectId,$topicId,$name,$items[0]['body']);
            $pdo->prepare("UPDATE ai_source_collections SET title=? WHERE id=?")->execute([$name,$id]);
            $valid=$pdo->prepare("SELECT id FROM ai_source_sections WHERE id=? AND collection_id=?");
            $update=$pdo->prepare("UPDATE ai_source_sections SET title=?,summary=?,sort_order=? WHERE id=? AND collection_id=?");
            $insert=$pdo->prepare("INSERT INTO ai_source_sections(collection_id,title,summary,sort_order) VALUES(?,?,?,?)");
            foreach($items as $i=>$item){
                if($item['id']){
                    $valid->execute([$item['id'],$id]);
                    if(!$valid->fetchColumn())throw new RuntimeException('Ongeldig onderdeel.');
                    $update->execute([$item['title'],$item['body'],$i+1,$item['id'],$id]);
                }elseif($i===0 && $isNew){
                    $pdo->prepare("UPDATE ai_source_sections SET title=?,sort_order=1 WHERE collection_id=?")->execute([$item['title'],$id]);
                }else{
                    $insert->execute([$id,$item['title'],$item['body'],$i+1]);
                }
            }
            $pdo->commit();
        }catch(Throwable $e){$pdo->rollBack();throw $e;}
    }
    redirect('subject_manage.php?id='.$subjectId);
}
if($id){
    $stmt=$pdo->prepare("SELECT c.id,c.topic_id,c.title name,c.subject_id,t.name topic_name,s.name subject_name FROM ai_source_collections c JOIN topics t ON t.id=c.topic_id JOIN subjects s ON s.id=c.subject_id WHERE c.id=?");
    $stmt->execute([$id]);$summary=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$summary){http_response_code(404);exit('Samenvatting niet gevonden.');}
    $stmt=$pdo->prepare("SELECT id,title,summary FROM ai_source_sections WHERE collection_id=? AND NULLIF(TRIM(summary),'') IS NOT NULL ORDER BY sort_order,id");
    $stmt->execute([$id]);$sections=$stmt->fetchAll(PDO::FETCH_ASSOC);
}else{
    $stmt=$pdo->prepare("SELECT t.id topic_id,t.name topic_name,t.subject_id,s.name subject_name FROM topics t JOIN subjects s ON s.id=t.subject_id WHERE t.id=? AND t.is_active=1");
    $stmt->execute([$topicId]);$summary=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$summary){http_response_code(404);exit('Overhoring niet gevonden.');}
    $summary['id']=0;$summary['name']='';$sections=[['id'=>0,'title'=>'','summary'=>'']];
}
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Samenvatting bewerken</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4" style="max-width:900px">
<a href="subject_manage.php?id=<?=(int)$summary['subject_id']?>">&larr; Terug naar <?=e($summary['subject_name'])?></a>
<div class="card mt-3"><div class="card-body p-4">
<h1 class="h3"><?= $id?'Samenvatting bewerken':'Nieuwe samenvatting' ?></h1>
<p class="text-secondary"><?=e($summary['topic_name'])?></p>
<?php if($id):?>
<div class="d-flex flex-wrap gap-2 mb-3">
<a class="btn btn-outline-secondary btn-sm" href="summary.php?source=archive&amp;id=<?=$id?>">Samenvatting bekijken</a>
<a class="btn btn-outline-primary btn-sm" href="source_regions.php?id=<?=$id?>">Brongebieden herkennen</a>
</div>
<?php endif;?>
<form method="post">
<input type="hidden" name="id" value="<?=$id?>">
<input type="hidden" name="topic_id" value="<?=(int)$summary['topic_id']?>">
<div class="mb-3"><label class="form-label">Naam</label><input class="form-control" name="name" value="<?=e($summary['name'])?>" required></div>
<div id="summaryParts">
<?php foreach($sections as $section):?>
<div class="border rounded p-3 mb-3">
<input type="hidden" name="section_id[]" value="<?=(int)$section['id']?>">
<label class="form-label">Onderdeel</label><input class="form-control mb-2" name="section_title[]" value="<?=e($section['title'])?>">
<label class="form-label">Samenvatting</label><textarea class="form-control" name="section_summary[]" rows="12"><?=e($section['summary'])?></textarea>
</div>
<?php endforeach;?>
</div>
<button class="btn btn-outline-primary mb-3" type="button" id="addSection">Onderdeel toevoegen</button>
<div class="d-flex justify-content-between gap-2">
<?php if($id):?><button class="btn btn-danger" type="submit" name="action" value="delete" onclick="return confirm('Samenvatting verwijderen? De originele bronnen blijven bewaard.')">Verwijderen</button><?php endif;?>
<div class="ms-auto"><a class="btn btn-outline-secondary" href="subject_manage.php?id=<?=(int)$summary['subject_id']?>">Annuleren</a> <button class="btn btn-primary" type="submit" name="action" value="save">Opslaan</button></div>
</div>
</form></div></div></main>
<script>
document.getElementById('addSection').addEventListener('click',()=>{
 const box=document.createElement('div');
 box.className='border rounded p-3 mb-3';
 box.innerHTML='<input type="hidden" name="section_id[]" value="0"><label class="form-label">Onderdeel</label><input class="form-control mb-2" name="section_title[]" required><label class="form-label">Samenvatting</label><textarea class="form-control" name="section_summary[]" rows="12" required></textarea>';
 document.getElementById('summaryParts').appendChild(box);
 box.querySelector('input[name="section_title[]"]').focus();
});
</script></body></html>
