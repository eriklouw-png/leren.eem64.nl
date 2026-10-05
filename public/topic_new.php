<?php
require __DIR__.'/../app/bootstrap.php';require_admin();

$subjectId=filter_input(INPUT_GET,'subject_id',FILTER_VALIDATE_INT);
if(!$subjectId){redirect('admin.php');}

$x=$pdo->prepare("SELECT id,name FROM subjects WHERE id=?");
$x->execute([$subjectId]);$subject=$x->fetch();
if(!$subject){http_response_code(404);exit('Vak niet gevonden.');}

$errors=[];
$name=trim((string)($_POST['name']??''));
$testDate=trim((string)($_POST['test_date']??''));

if($_SERVER['REQUEST_METHOD']==='POST'){
    if($name==='')$errors[]='Naam is verplicht.';
    if($testDate!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$testDate))$errors[]='Ongeldige datum.';
    if(!$errors){
        $check=$pdo->prepare("SELECT id FROM topics WHERE subject_id=? AND name=? LIMIT 1");
        $check->execute([$subjectId,$name]);
        if($check->fetchColumn()){
            $errors[]='Er bestaat al een overhoring met deze naam voor dit vak.';
        }else{
            $ins=$pdo->prepare("INSERT INTO topics(subject_id,name,test_date,is_active) VALUES(?,?,?,1)");
            $ins->execute([$subjectId,$name,$testDate!==''?$testDate:null]);
            $topicId=(int)$pdo->lastInsertId();
            redirect('subject_manage.php?id='.$subjectId);
        }
    }
}
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nieuwe overhoring</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4" style="max-width:700px">
<a href="subject_manage.php?id=<?=$subjectId?>">&larr; Terug naar <?=e($subject['name'])?></a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<h1 class="h3 mb-1">Nieuwe overhoring</h1>
<p class="text-secondary mb-4">Maak eerst de overhoring aan. Daarna kun je er sub-testen of een AI-toets aan toevoegen.</p>
<?php foreach($errors as $error):?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach;?>
<form method="post">
<div class="mb-3"><label class="form-label">Naam van de overhoring</label><input class="form-control" name="name" value="<?=e($name)?>" placeholder="Bijvoorbeeld: Hoofdstuk 3 – Cellen" required autofocus></div>
<div class="mb-4"><label class="form-label">Overhoringsdatum</label><input class="form-control" type="date" name="test_date" value="<?=e($testDate)?>"><div class="form-text">Na deze datum wordt de overhoring automatisch gearchiveerd. Laat leeg als er geen vaste datum is.</div></div>
<div class="d-flex justify-content-end gap-2"><a class="btn btn-outline-secondary" href="subject_manage.php?id=<?=$subjectId?>">Annuleren</a><button class="btn btn-primary" type="submit">Overhoring aanmaken</button></div>
</form>
</div></div></main></body></html>
