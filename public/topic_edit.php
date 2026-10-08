<?php
require __DIR__.'/../app/bootstrap.php';require_admin();

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('admin.php');

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'save';
    if($action==='delete'){
        $x=$pdo->prepare("UPDATE topics SET is_active=0 WHERE id=?");
        $x->execute([$id]);
        redirect('subject_manage.php?id='.$_POST['subject_id']);
    }
    if($action==='save'){
        $name=trim((string)($_POST['name']??''));
        $testDate=trim((string)($_POST['test_date']??''));
        $useSummary=!empty($_POST['use_summary']);
        if($name===''){http_response_code(400);exit('Naam is verplicht.');}
        if($testDate!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$testDate)){http_response_code(400);exit('Ongeldige datum.');}
        $x=$pdo->prepare("UPDATE topics SET name=?,test_date=?,use_summary=? WHERE id=?");
        $x->execute([$name,$testDate!==''?$testDate:null,$useSummary?1:0,$id]);
        redirect('subject_manage.php?id='.$_POST['subject_id']);
    }
}

$x=$pdo->prepare("SELECT tp.id,tp.name,tp.test_date,tp.is_active,tp.use_summary,tp.summary_updated_at,tp.subject_id,s.name subject_name FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
$x->execute([$id]);$topic=$x->fetch();
if(!$topic){http_response_code(404);exit('Overhoring niet gevonden.');}
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Overhoring bewerken</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4" style="max-width:700px">
<a href="subject_manage.php?id=<?=$topic['subject_id']?>">&larr; Terug naar <?=e($topic['subject_name'])?></a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<h1 class="h3 mb-4">Overhoring bewerken</h1>
<form method="post">
<input type="hidden" name="subject_id" value="<?=$topic['subject_id']?>">
<input type="hidden" name="action" value="save">
<div class="mb-3"><label class="form-label">Overhoring</label><input class="form-control" name="name" value="<?=e($topic['name'])?>" required></div>
<div class="mb-3"><label class="form-label">Overhoringsdatum</label><input class="form-control" type="date" name="test_date" value="<?=e($topic['test_date']??'')?>"><div class="form-text">Na deze datum wordt het overhoring automatisch gearchiveerd. Laat leeg als er geen overhoringsdatum is.</div></div>
<div class="form-check mb-4">
<input class="form-check-input" type="checkbox" name="use_summary" value="1" id="useSummary" <?=!empty($topic['use_summary'])?'checked':''?>>
<label class="form-check-label" for="useSummary"><strong>Samenvatting gebruiken</strong><br><span class="text-secondary">Je kunt voor deze overhoring meerdere afzonderlijke samenvattingen maken. Elke samenvatting kan bijvoorbeeld de naam 1.3 Samenvatting krijgen.</span></label>
</div>
<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
<div>
<button class="btn btn-danger" type="submit" name="action" value="delete" data-confirm="Weet u zeker dat u deze overhoring wilt verwijderen? De overhoring verdwijnt uit de website, maar blijft in de database bewaard.">Verwijderen</button>
</div>
<div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="subject_manage.php?id=<?=$topic['subject_id']?>">Annuleren</a><button class="btn btn-primary" type="submit" name="action" value="save">Opslaan</button></div>
</div>
</form>
</div></div></main></body></html>