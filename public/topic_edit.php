<?php
require __DIR__.'/../app/bootstrap.php';require_admin();

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('admin.php');

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'save';
    if($action==='hide'){
        $x=$pdo->prepare("UPDATE topics SET is_active=0 WHERE id=?");
        $x->execute([$id]);
        redirect('subject_manage.php?id='.$_POST['subject_id']);
    }
    if($action==='restore'){
        $x=$pdo->prepare("UPDATE topics SET is_active=1 WHERE id=?");
        $x->execute([$id]);
        redirect('subject_manage.php?id='.$_POST['subject_id']);
    }
    if($action==='save'){
        $name=trim((string)($_POST['name']??''));
        $testDate=trim((string)($_POST['test_date']??''));
        if($name===''){http_response_code(400);exit('Naam is verplicht.');}
        if($testDate!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$testDate)){http_response_code(400);exit('Ongeldige datum.');}
        $x=$pdo->prepare("UPDATE topics SET name=?,test_date=? WHERE id=?");
        $x->execute([$name,$testDate!==''?$testDate:null,$id]);
        redirect('subject_manage.php?id='.$_POST['subject_id']);
    }
}

$x=$pdo->prepare("SELECT tp.id,tp.name,tp.test_date,tp.is_active,tp.subject_id,s.name subject_name FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
$x->execute([$id]);$topic=$x->fetch();
if(!$topic){http_response_code(404);exit('Overhoring niet gevonden.');}
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Overhoring bewerken</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4" style="max-width:700px">
<a href="subject_manage.php?id=<?=$topic['subject_id']?>">&larr; Terug naar <?=e($topic['subject_name'])?></a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<h1 class="h3 mb-4">Overhoring bewerken</h1>
<?php if(!(int)$topic['is_active']):?><div class="alert alert-warning">Deze overhoring is verborgen voor leerlingen.</div><?php endif;?>
<form method="post">
<input type="hidden" name="subject_id" value="<?=$topic['subject_id']?>">
<input type="hidden" name="action" value="save">
<div class="mb-3"><label class="form-label">Overhoring</label><input class="form-control" name="name" value="<?=e($topic['name'])?>" required></div>
<div class="mb-4"><label class="form-label">Overhoringsdatum</label><input class="form-control" type="date" name="test_date" value="<?=e($topic['test_date']??'')?>"><div class="form-text">Na deze datum wordt het overhoring automatisch gearchiveerd. Laat leeg als er geen overhoringsdatum is.</div></div>
<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
<div>
<?php if((int)$topic['is_active']):?>
<button class="btn btn-outline-danger" type="submit" name="action" value="hide" onclick="return confirm('Deze overhoring verbergen voor leerlingen? De overhoring en alle sub-testen blijven bewaard.');">Verwijderen</button>
<?php else:?>
<button class="btn btn-outline-success" type="submit" name="action" value="restore">Herstellen</button>
<?php endif;?>
</div>
<div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="subject_manage.php?id=<?=$topic['subject_id']?>">Annuleren</a><button class="btn btn-primary" type="submit" name="action" value="save">Opslaan</button></div>
</div>
</form>
</div></div></main></body></html>