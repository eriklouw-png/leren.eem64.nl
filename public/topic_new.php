<?php
require __DIR__.'/../app/bootstrap.php';require_admin();

$subjectId=filter_input(INPUT_GET,'subject_id',FILTER_VALIDATE_INT);
if(!$subjectId && $_SERVER['REQUEST_METHOD']==='POST'){
    $subjectId=filter_var($_POST['subject_id']??null,FILTER_VALIDATE_INT);
}
if(!$subjectId){redirect('admin.php');}

$x=$pdo->prepare("SELECT id,name FROM subjects WHERE id=?");
$x->execute([$subjectId]);$subject=$x->fetch();
if(!$subject){http_response_code(404);exit('Vak niet gevonden.');}

$errors=[];
$name=trim((string)($_POST['name']??''));
$testDate=trim((string)($_POST['test_date']??''));
$useSummary=!empty($_POST['use_summary']);

if($_SERVER['REQUEST_METHOD']==='POST'){
    if($name==='')$errors[]='Naam is verplicht.';
    if($testDate!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$testDate))$errors[]='Ongeldige datum.';
    if(!$errors){
        $check=$pdo->prepare("SELECT id FROM topics WHERE subject_id=? AND name=? LIMIT 1");
        $check->execute([$subjectId,$name]);
        if($check->fetchColumn()){
            $errors[]='Er bestaat al een overhoring met deze naam voor dit vak.';
        }else{
            $ins=$pdo->prepare("INSERT INTO topics(subject_id,name,test_date,is_active,use_summary) VALUES(?,?,?,1,?)");
            $ins->execute([$subjectId,$name,$testDate!==''?$testDate:null,$useSummary?1:0]);
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
<p class="text-secondary mb-4">Maak eerst de overhoring aan. Daarna kun je er testen of een AI-toets aan toevoegen.</p>
<?php foreach($errors as $error):?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach;?>
<form method="post" action="topic_new.php?subject_id=<?=$subjectId?>">
<input type="hidden" name="subject_id" value="<?=$subjectId?>">
<div class="mb-3"><label class="form-label">Naam van de overhoring</label><input class="form-control" name="name" value="<?=e($name)?>" placeholder="Bijvoorbeeld: Hoofdstuk 3 – Cellen" required autofocus></div>
<div class="mb-3"><label class="form-label">Overhoringsdatum</label><input class="form-control" type="date" name="test_date" value="<?=e($testDate)?>"><div class="form-text">Na deze datum wordt de overhoring automatisch gearchiveerd. Laat leeg als er geen vaste datum is.</div></div>
<div class="form-check mb-4">
<input class="form-check-input" type="checkbox" name="use_summary" value="1" id="useSummary" <?=$useSummary?'checked':''?>>
<label class="form-check-label" for="useSummary"><strong>Samenvatting gebruiken</strong><br><span class="text-secondary">Je kunt voor deze overhoring meerdere afzonderlijke samenvattingen maken. Elke samenvatting kan bijvoorbeeld de naam 1.3 Samenvatting krijgen en wordt op de overhoringpagina getoond.</span></label>
</div>
<div class="d-flex justify-content-end gap-2"><a class="btn btn-outline-secondary" href="subject_manage.php?id=<?=$subjectId?>">Annuleren</a><button class="btn btn-primary" type="submit">Overhoring aanmaken</button></div>
</form>
</div></div></main></body></html>
