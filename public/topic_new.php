<?php
require __DIR__.'/../app/bootstrap.php';require_admin();

$subjectId=filter_input(INPUT_GET,'subject_id',FILTER_VALIDATE_INT);
if(!$subjectId && $_SERVER['REQUEST_METHOD']==='POST'){
    $subjectId=filter_var($_POST['subject_id']??null,FILTER_VALIDATE_INT);
}
if(!$subjectId){redirect('admin.php');}

$x=$pdo->prepare("SELECT id,name,user_id FROM subjects WHERE id=?");
$x->execute([$subjectId]);$subject=$x->fetch();
if(!$subject){http_response_code(404);exit('Vak niet gevonden.');}

$gradeStudentId=(int)$subject['user_id'];
$errors=[];
$name=trim((string)($_POST['name']??''));
$testDate=trim((string)($_POST['test_date']??''));
$useSummary=!empty($_POST['use_summary']);
$archived=!empty($_POST['archived']);
$gradeText=str_replace(',','.',trim((string)($_POST['grade']??'')));
$gradeValue=null;
$gradeWeight=(int)($_POST['grade_weight']??1);

if($_SERVER['REQUEST_METHOD']==='POST'){
    if($name==='')$errors[]='Naam is verplicht.';
    if($testDate!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$testDate))$errors[]='Ongeldige datum.';
    if(!in_array($gradeWeight,[1,2,3],true))$errors[]='Ongeldige weging.';
    if($gradeText!==''){
        if(!preg_match('/^(?:[1-9](?:\\.[0-9])?|10(?:\\.0)?)$/',$gradeText))$errors[]='Vul een cijfer van 1,0 tot en met 10,0 in.';
        else $gradeValue=(float)$gradeText;
    }
    if($archived && ($testDate==='' || $testDate>=date('Y-m-d')))$errors[]='Een gearchiveerde overhoring heeft een datum in het verleden nodig.';
    if(!$archived && $testDate!=='' && $testDate<date('Y-m-d'))$errors[]='Een datum in het verleden betekent Gearchiveerd. Vink dit aan of kies een andere datum.';
    if($gradeValue!==null && !$archived)$errors[]='Vink Gearchiveerd aan om een cijfer vast te leggen.';
    if(!$errors){
        $check=$pdo->prepare("SELECT id FROM topics WHERE subject_id=? AND name=? LIMIT 1");
        $check->execute([$subjectId,$name]);
        if($check->fetchColumn()){
            $errors[]='Er bestaat al een overhoring met deze naam voor dit vak.';
        }else{
            $pdo->beginTransaction();
            $ins=$pdo->prepare("INSERT INTO topics(subject_id,name,test_date,is_active,use_summary) VALUES(?,?,?,1,?)");
            $ins->execute([$subjectId,$name,$testDate!==''?$testDate:null,$useSummary?1:0]);
            $topicId=(int)$pdo->lastInsertId();
            if($gradeValue!==null){
                $g=$pdo->prepare('INSERT INTO topic_grades(topic_id,student_id,grade,weight) VALUES(?,?,?,?)');
                $g->execute([$topicId,$gradeStudentId,$gradeValue,$gradeWeight]);
            }
            $pdo->commit();
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
<div class="form-check mb-3">
<input class="form-check-input" type="checkbox" id="archived" name="archived" value="1" <?=$archived?'checked':''?>>
<label class="form-check-label" for="archived"><strong>Gearchiveerd</strong></label>
<div class="form-text">Gebruik een overhoringsdatum in het verleden. Deze overhoring is dan niet meer toegankelijk voor leerlingen.</div>
</div>

<div class="mb-3"><label class="form-label" for="grade">Cijfer (optioneel)</label><input class="form-control" id="grade" name="grade" type="number" min="1" max="10" step="0.1" inputmode="decimal" value="<?=e($gradeText)?>" placeholder="Bijvoorbeeld 8,0"><div class="form-text">Het cijfer wordt gekoppeld aan de eigenaar van dit vak.</div></div>
<div class="mb-3"><label class="form-label" for="grade_weight">Weging van het cijfer</label><select class="form-select" name="grade_weight" id="grade_weight"><?php foreach([1,2,3] as $w):?><option value="<?=$w?>" <?=$gradeWeight===$w?'selected':''?>><?=$w?>x</option><?php endforeach;?></select></div>
<div class="form-check mb-4">
<input class="form-check-input" type="checkbox" name="use_summary" value="1" id="useSummary" <?=$useSummary?'checked':''?>>
<label class="form-check-label" for="useSummary"><strong>Samenvatting gebruiken</strong><br><span class="text-secondary">Je kunt voor deze overhoring meerdere afzonderlijke samenvattingen maken. Elke samenvatting kan bijvoorbeeld de naam 1.3 Samenvatting krijgen en wordt op de overhoringpagina getoond.</span></label>
</div>
<div class="d-flex justify-content-end gap-2"><a class="btn btn-outline-secondary" href="subject_manage.php?id=<?=$subjectId?>">Annuleren</a><button class="btn btn-primary" type="submit">Overhoring aanmaken</button></div>
</form>
</div></div></main></body></html>
