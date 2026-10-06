<?php
require __DIR__.'/../app/bootstrap.php';require_admin();

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:0;
$topicId=filter_input(INPUT_GET,'topic_id',FILTER_VALIDATE_INT)?:0;
$isNew=$id===0;
if(!$isNew && !$topicId) $topicId=0;

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'save';
    $postId=filter_var($_POST['id']??null,FILTER_VALIDATE_INT)?:0;
    $postTopicId=filter_var($_POST['topic_id']??null,FILTER_VALIDATE_INT)?:0;
    if($action==='save'){
        $name=trim((string)($_POST['name']??''));
        $summary=trim((string)($_POST['summary']??''));
        if($name===''){http_response_code(400);exit('Naam is verplicht.');}
        if($summary===''){http_response_code(400);exit('De samenvatting mag niet leeg zijn.');}
        if($postId){
            $x=$pdo->prepare("UPDATE topic_summaries SET name=?,summary=?,updated_at=NOW() WHERE id=? AND is_active=1");
            $x->execute([$name,$summary,$postId]);
            $id=$postId;
            $x=$pdo->prepare("SELECT topic_id FROM topic_summaries WHERE id=?");
            $x->execute([$id]); $topicId=(int)$x->fetchColumn();
        }else{
            if(!$postTopicId){http_response_code(400);exit('Overhoring ontbreekt.');}
            $x=$pdo->prepare("SELECT id FROM topics WHERE id=?");
            $x->execute([$postTopicId]);
            if(!$x->fetchColumn()){http_response_code(404);exit('Overhoring niet gevonden.');}
            $x=$pdo->prepare("INSERT INTO topic_summaries(topic_id,name,summary,is_active,created_at,updated_at) VALUES(?,?,?,1,NOW(),NOW())");
            $x->execute([$postTopicId,$name,$summary]);
            $id=(int)$pdo->lastInsertId();
            $topicId=$postTopicId;
        }
    }elseif($action==='delete' && $postId){
        $x=$pdo->prepare("UPDATE topic_summaries SET is_active=0,deleted_at=NOW(),updated_at=NOW() WHERE id=?");
        $x->execute([$postId]);
        $x=$pdo->prepare("SELECT topic_id FROM topic_summaries WHERE id=?"); $x->execute([$postId]); $topicId=(int)$x->fetchColumn();
    }
    if(!$topicId)redirect('admin.php');
    redirect('subject_manage.php?id='.(int)(filter_var($_POST['subject_id']??null,FILTER_VALIDATE_INT)?:0));
}

$x=$pdo->prepare("
    SELECT ts.id,ts.topic_id,ts.name,ts.summary,ts.is_active,ts.created_at,ts.updated_at,ts.deleted_at,
           tp.name topic_name,tp.subject_id,s.name subject_name
    FROM topic_summaries ts
    JOIN topics tp ON tp.id=ts.topic_id
    JOIN subjects s ON s.id=tp.subject_id
    WHERE ts.id=? AND ts.is_active=1
");
if(!$isNew){
    $x->execute([$id]);$summary=$x->fetch();
    if(!$summary){http_response_code(404);exit('Samenvatting niet gevonden.');}
    $topicId=(int)$summary['topic_id'];
}else{
    $x->execute([0]);$summary=null;
    $x=$pdo->prepare("SELECT tp.id topic_id,tp.name topic_name,tp.subject_id,s.name subject_name FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
    $x->execute([$topicId]);$topic=$x->fetch();
    if(!$topic){http_response_code(404);exit('Overhoring niet gevonden.');}
    $summary=['id'=>0,'topic_id'=>$topic['topic_id'],'name'=>'','summary'=>'','is_active'=>1,'subject_id'=>$topic['subject_id'],'topic_name'=>$topic['topic_name'],'subject_name'=>$topic['subject_name']];
}
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $isNew?'Nieuwe samenvatting':'Samenvatting bewerken' ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4" style="max-width:900px">
<a href="subject_manage.php?id=<?=$summary['subject_id']?>">&larr; Terug naar <?=e($summary['subject_name'])?></a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<div class="d-flex justify-content-between align-items-start gap-3 mb-4">
<div><h1 class="h3 mb-1"><?= $isNew?'Nieuwe samenvatting':'Samenvatting bewerken' ?></h1><div class="text-secondary">Overhoring: <?=e($summary['topic_name'])?></div></div>

</div>
<form method="post">
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" value="<?=$summary['id']?>">
<input type="hidden" name="topic_id" value="<?=$summary['topic_id']?>">
<input type="hidden" name="subject_id" value="<?=$summary['subject_id']?>">
<div class="mb-3"><label class="form-label">Naam</label><input class="form-control" name="name" value="<?=e($summary['name'])?>" required></div>
<div class="mb-4"><label class="form-label">Samenvatting</label><textarea class="form-control" name="summary" rows="24" required><?=e($summary['summary'])?></textarea><div class="form-text">Je kunt de door AI gemaakte tekst hier volledig aanpassen.</div></div>
<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
<div>
<?php if(!$isNew):?><button class="btn btn-outline-danger" type="submit" name="action" value="delete" onclick="return confirm('Weet u zeker dat u deze samenvatting wilt verwijderen? De samenvatting verdwijnt uit de website, maar blijft in de database bewaard.');">Verwijderen</button><?php endif;?>
</div>
<div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="subject_manage.php?id=<?=$summary['subject_id']?>">Annuleren</a><button class="btn btn-primary" type="submit" name="action" value="save">Opslaan</button></div>
</div>
</form>
</div></div></main></body></html>
