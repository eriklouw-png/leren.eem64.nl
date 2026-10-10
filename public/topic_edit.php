<?php
require __DIR__.'/../app/bootstrap.php';require_admin();

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('admin.php');
$ownerStmt=$pdo->prepare('SELECT s.user_id FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?');
$ownerStmt->execute([$id]);
$gradeStudentId=(int)$ownerStmt->fetchColumn();
if(!$gradeStudentId){http_response_code(404);exit('Eigenaar niet gevonden.');}
$topicSubject=$pdo->prepare('SELECT subject_id FROM topics WHERE id=?');
$topicSubject->execute([$id]);
require_subject_management((int)$topicSubject->fetchColumn());
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
        $archived=!empty($_POST['archived']);
        $gradeText=str_replace(',','.',trim((string)($_POST['grade']??'')));
        $gradeWeight=(int)($_POST['grade_weight']??1);
        if(!in_array($gradeWeight,[1,2,3],true)){http_response_code(422);exit('Ongeldige weging.');}
        if($gradeText!=='' && !preg_match('/^(?:[1-9](?:\\.[0-9])?|10(?:\\.0)?)$/',$gradeText)){http_response_code(422);exit('Ongeldig cijfer (1,0 t/m 10,0).');}
        if($archived && ($testDate==='' || $testDate>=date('Y-m-d'))){http_response_code(422);exit('Kies voor archivering een datum in het verleden.');}
        if(!$archived && $testDate!=='' && $testDate<date('Y-m-d')){http_response_code(422);exit('Een datum in het verleden betekent Gearchiveerd.');}
        if($gradeText!=='' && !$archived){http_response_code(422);exit('Archiveer de overhoring om een cijfer op te slaan.');}
        if($name===''){http_response_code(400);exit('Naam is verplicht.');}
        if($testDate!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$testDate)){http_response_code(400);exit('Ongeldige datum.');}
        $pdo->beginTransaction();
        $x=$pdo->prepare("UPDATE topics SET name=?,test_date=?,use_summary=? WHERE id=?");
        $x->execute([$name,$testDate!==''?$testDate:null,$useSummary?1:0,$id]);
        $g=$pdo->prepare('DELETE FROM topic_grades WHERE topic_id=? AND student_id=?');
        if($gradeText==='')$g->execute([$id,$gradeStudentId]);
        else{
            $g=$pdo->prepare('INSERT INTO topic_grades(topic_id,student_id,grade,weight) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE grade=VALUES(grade),weight=VALUES(weight),recorded_at=CURRENT_TIMESTAMP');
            $g->execute([$id,$gradeStudentId,(float)$gradeText,$gradeWeight]);
        }
        $pdo->commit();
        redirect('subject_manage.php?id='.$_POST['subject_id']);
    }
}

$x=$pdo->prepare("SELECT tp.id,tp.name,tp.test_date,tp.is_active,tp.use_summary,tp.summary_updated_at,tp.subject_id,s.name subject_name FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
$x->execute([$id]);$topic=$x->fetch();
if(!$topic){http_response_code(404);exit('Overhoring niet gevonden.');}
$gradeQuery=$pdo->prepare('SELECT grade,weight FROM topic_grades WHERE topic_id=? AND student_id=?');
$gradeQuery->execute([$id,$gradeStudentId]);
$gradeRow=$gradeQuery->fetch(PDO::FETCH_ASSOC);
$currentGrade=$gradeRow['grade']??false;
$currentWeight=(int)($gradeRow['weight']??1);
$isArchived=!empty($topic['test_date']) && $topic['test_date']<date('Y-m-d');
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
<div class="form-check mb-3">
<input class="form-check-input" type="checkbox" name="archived" id="archived" value="1" <?=$isArchived?'checked':''?>>
<label class="form-check-label" for="archived"><strong>Gearchiveerd</strong></label>
<div class="form-text">Gearchiveerd betekent: de overhoringsdatum ligt in het verleden. Haal het vinkje weg en kies een toekomstige datum of maak de datum leeg om te heractiveren.</div>
</div>

<div class="mb-3"><label class="form-label" for="grade">Cijfer</label><input class="form-control" id="grade" type="number" name="grade" min="1" max="10" step="0.1" inputmode="decimal" value="<?=$currentGrade!==false?e((string)$currentGrade):''?>" placeholder="Nog geen cijfer"><div class="form-text">Cijfer voor de eigenaar van het vak; leeg laten verwijdert diens cijfer.</div></div>
<div class="mb-3"><label class="form-label" for="grade_weight">Weging van het cijfer</label><select class="form-select" name="grade_weight" id="grade_weight"><?php foreach([1,2,3] as $w):?><option value="<?=$w?>" <?=$currentWeight===$w?'selected':''?>><?=$w?>x</option><?php endforeach;?></select></div>
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