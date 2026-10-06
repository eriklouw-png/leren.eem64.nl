<?php
require __DIR__.'/../app/bootstrap.php';require_admin();

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('admin.php');

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'save';
    if($action==='save'){
        $name=trim((string)($_POST['name']??''));
        $summary=trim((string)($_POST['summary']??''));
        if($name===''){http_response_code(400);exit('Naam is verplicht.');}
        if($summary===''){http_response_code(400);exit('De samenvatting mag niet leeg zijn.');}
        $x=$pdo->prepare("UPDATE topic_summaries SET name=?,summary=?,updated_at=NOW() WHERE id=?");
        $x->execute([$name,$summary,$id]);
    }elseif($action==='delete'){
        $x=$pdo->prepare("UPDATE topic_summaries SET is_active=0,deleted_at=NOW(),updated_at=NOW() WHERE id=?");
        $x->execute([$id]);
    }
    $x=$pdo->prepare("SELECT topic_id FROM topic_summaries WHERE id=?");
    $x->execute([$id]);
    $topicId=(int)$x->fetchColumn();
    if(!$topicId)redirect('admin.php');
    redirect('subject_manage.php?summary='.$id);
}

$x=$pdo->prepare("
    SELECT ts.id,ts.topic_id,ts.name,ts.summary,ts.is_active,ts.created_at,ts.updated_at,ts.deleted_at,
           tp.name topic_name,tp.subject_id,s.name subject_name
    FROM topic_summaries ts
    JOIN topics tp ON tp.id=ts.topic_id
    JOIN subjects s ON s.id=tp.subject_id
    WHERE ts.id=? AND ts.is_active=1
");
$x->execute([$id]);$summary=$x->fetch();
if(!$summary){http_response_code(404);exit('Samenvatting niet gevonden.');}
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Samenvatting bewerken</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4" style="max-width:900px">
<a href="subject_manage.php?id=<?=$summary['subject_id']?>">&larr; Terug naar <?=e($summary['subject_name'])?></a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<div class="d-flex justify-content-between align-items-start gap-3 mb-4">
<div><h1 class="h3 mb-1">Samenvatting bewerken</h1><div class="text-secondary">Overhoring: <?=e($summary['topic_name'])?></div></div>
<?php if(!(int)$summary['is_active']):?><span class="badge text-bg-secondary">Verwijderd</span><?php endif;?>
</div>
<form method="post">
<input type="hidden" name="action" value="save">
<div class="mb-3"><label class="form-label">Naam</label><input class="form-control" name="name" value="<?=e($summary['name'])?>" required></div>
<div class="mb-4"><label class="form-label">Samenvatting</label><textarea class="form-control" name="summary" rows="24" required><?=e($summary['summary'])?></textarea><div class="form-text">Je kunt de door AI gemaakte tekst hier volledig aanpassen.</div></div>
<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
<div>
<button class="btn btn-outline-danger" type="submit" name="action" value="delete" onclick="return confirm('Weet u zeker dat u deze samenvatting wilt verwijderen? De samenvatting verdwijnt uit de website, maar blijft in de database bewaard.');">Verwijderen</button>
</div>
<div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="subject_manage.php?id=<?=$summary['subject_id']?>">Annuleren</a><button class="btn btn-primary" type="submit" name="action" value="save">Opslaan</button></div>
</div>
</form>
</div></div></main></body></html>
