<?php
require __DIR__.'/../app/bootstrap.php';require_manager();
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
    if(!$id){http_response_code(400);exit('Ongeldig ID.');}
    if($action==='upload_subject_image'){
        if(!isset($_FILES['image']) || $_FILES['image']['error']!==UPLOAD_ERR_OK){
            redirect('admin.php?image_error=upload');
        }
        if((int)$_FILES['image']['size']>5*1024*1024){
            redirect('admin.php?image_error=size');
        }
        $tmp=$_FILES['image']['tmp_name'];
        $info=@getimagesize($tmp);
        $allowed=['image/jpeg','image/png','image/webp'];
        $mime=$info['mime']??'';
        if(!$info || !in_array($mime,$allowed,true)){
            redirect('admin.php?image_error=type');
        }
        $data=file_get_contents($tmp);
        if($data===false){
            redirect('admin.php?image_error=read');
        }
        $x=$pdo->prepare("UPDATE subjects SET image_mime=?,image_data=? WHERE id=?");
        $x->execute([$mime,$data,$id]);
        redirect('admin.php?image_saved=1');
    }
    if($action==='delete_subject_image'){
        $x=$pdo->prepare("UPDATE subjects SET image_mime=NULL,image_data=NULL WHERE id=?");
        $x->execute([$id]);
        redirect('admin.php?image_deleted=1');
    }
    if($action==='delete_subject'){
        $x=$pdo->prepare("SELECT id FROM subjects WHERE id=?");
        $x->execute([$id]);
        if(!$x->fetch()){http_response_code(404);exit('Vak niet gevonden.');}
        $pdo->beginTransaction();
        try{
            $x=$pdo->prepare("SELECT id FROM topics WHERE subject_id=?");
            $x->execute([$id]);
            $topicIds=array_map('intval',$x->fetchAll(PDO::FETCH_COLUMN));
            if($topicIds){
                $ph=implode(',',array_fill(0,count($topicIds),'?'));
                $x=$pdo->prepare("DELETE FROM tests WHERE topic_id IN ($ph)");
                $x->execute($topicIds);
                $x=$pdo->prepare("DELETE FROM topics WHERE id IN ($ph)");
                $x->execute($topicIds);
            }
            $x=$pdo->prepare("DELETE FROM subjects WHERE id=?");
            $x->execute([$id]);
            $pdo->commit();
            redirect('admin.php?deleted=subject');
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }
    if($action==='delete_test'){
        $x=$pdo->prepare("SELECT title FROM tests WHERE id=?");
        $x->execute([$id]);$item=$x->fetch();
        if(!$item){http_response_code(404);exit('Sub-Test niet gevonden.');}
        $x=$pdo->prepare("DELETE FROM tests WHERE id=?");$x->execute([$id]);
        redirect('admin.php?deleted=test');
    }
    if($action==='delete_session'){
        $x=$pdo->prepare("SELECT id FROM study_sessions WHERE id=?");
        $x->execute([$id]);if(!$x->fetch()){http_response_code(404);exit('Oefensessie niet gevonden.');}
        $x=$pdo->prepare("DELETE FROM study_sessions WHERE id=?");$x->execute([$id]);
        redirect('admin.php?deleted=session');
    }
    if($action==='delete_attempt'){
        $x=$pdo->prepare("SELECT id FROM attempts WHERE id=? AND finished_at IS NOT NULL");
        $x->execute([$id]);if(!$x->fetch()){http_response_code(404);exit('Resultaat niet gevonden.');}
        $x=$pdo->prepare("DELETE FROM attempts WHERE id=?");$x->execute([$id]);
        redirect('admin.php?deleted=attempt');
    }
    http_response_code(400);exit('Ongeldige actie.');
}
$subjects=$pdo->query("SELECT id,name,description,image_mime FROM subjects ORDER BY name")->fetchAll();
$sessions=$pdo->query("SELECT ss.id,ss.test_id,ss.attempt_id,ss.started_at,ss.ended_at,ss.active_seconds,t.title,a.score FROM study_sessions ss LEFT JOIN attempts a ON a.id=ss.attempt_id LEFT JOIN tests t ON t.id=ss.test_id ORDER BY ss.started_at DESC")->fetchAll();
$answeredRows=$pdo->query("SELECT ss.test_id,aa.question_id FROM study_sessions ss JOIN attempt_answers aa ON aa.attempt_id=ss.attempt_id GROUP BY ss.test_id,aa.question_id")->fetchAll();
$questionCounts=$pdo->query("SELECT test_id,COUNT(*) question_count FROM questions GROUP BY test_id")->fetchAll();
$answeredByTest=[];
foreach($answeredRows as $row){$answeredByTest[(int)$row['test_id']]=($answeredByTest[(int)$row['test_id']]??0)+1;}
$totalQuestionsByTest=[];
foreach($questionCounts as $row){$totalQuestionsByTest[(int)$row['test_id']]=(int)$row['question_count'];}
$sessionGroups=[];
foreach($sessions as $s){
    $testId=(int)$s['test_id'];
    if(!isset($sessionGroups[$testId])){
        $sessionGroups[$testId]=['title'=>$s['title']??'Vrij oefenen','total_seconds'=>0,'sessions'=>[]];
    }
    $sessionGroups[$testId]['total_seconds']+=(int)$s['active_seconds'];
    $sessionGroups[$testId]['sessions'][]=$s;
}
ksort($sessionGroups);
function format_duration(int $seconds):string{$m=intdiv($seconds,60);$s=$seconds%60;return $m.' min '.str_pad((string)$s,2,'0',STR_PAD_LEFT).' sec';}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Beheer - Leren</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><nav class="navbar navbar-dark bg-dark"><div class="container"><a class="navbar-brand" href="admin.php">Leren beheer</a><div><span class="text-white me-3"><?=e($_SESSION['user']['name'])?></span><a class="btn btn-outline-light btn-sm" href="index.php">Website</a> <a class="btn btn-outline-light btn-sm" href="logout.php">Uitloggen</a></div></div></nav><main class="container py-4">
<?php if(isset($_GET['saved'])):?><div class="alert alert-success">Het vak is opgeslagen.</div><?php endif;?>
<?php if(isset($_GET['ai_rules'])):?>
<div class="alert alert-<?=($_GET['ai_rules']==='created'?'success':'warning')?>">
<?=($_GET['ai_rules']==='created'?'De eerste AI-instructies voor dit vak zijn automatisch aangemaakt. Je kunt ze aanpassen via ‘AI-instructies per vak’.':'Het vak is aangemaakt, maar de AI-instructies konden niet automatisch worden aangemaakt. Je kunt ze handmatig toevoegen via ‘AI-instructies per vak’.')?>
</div>
<?php endif;?>
<div class="d-flex justify-content-between align-items-center mb-3">
<h1 class="mb-0">Vakken</h1>
<div><a class="btn btn-outline-primary" href="subject_edit.php">Nieuw vak</a></div>
</div>

<div class="row g-3 mb-5 admin-grid">
<?php foreach($subjects as $subject):?>
<div class="col-6 col-md-6 col-lg-4 admin-col">
<a href="subject_manage.php?id=<?=(int)$subject['id']?>" class="leren-tile text-decoration-none">
<div class="subject-card-image" style="background-image:<?=($subject['image_mime']?'url(\'subject_image.php?id='.(int)$subject['id'].'\')':'none')?>;">
<div class="subject-card-overlay"></div>
<div class="card-body position-relative d-flex flex-column justify-content-end subject-card-body">
<h2 class="h4 text-white mb-1 subject-card-title"><?=e($subject['name'])?></h2>
<?php if($subject['description']):?><p class="mb-0 subject-card-description"><?=e($subject['description'])?></p><?php endif;?>
</div>
</div>
</a>
</div>
<?php endforeach;?>
</div>

<h2 class="h4 mt-5 mb-3">Studenten</h2>
<?php
$students=$pdo->query("
    SELECT
        u.id,u.name,u.email,u.image_mime,
        COALESCE(ss.active_seconds,0) AS active_seconds,
        COALESCE(a.completed_tests,0) AS completed_tests,
        COALESCE(a.average_score,0) AS average_score
    FROM users u
    LEFT JOIN (
        SELECT student_id,SUM(active_seconds) AS active_seconds
        FROM study_sessions
        GROUP BY student_id
    ) ss ON ss.student_id=u.id
    LEFT JOIN (
        SELECT student_id,
               COUNT(*) AS completed_tests,
               AVG(score) AS average_score
        FROM attempts
        WHERE status='finished' AND mode='normal'
        GROUP BY student_id
    ) a ON a.student_id=u.id
    WHERE u.role='student'
    ORDER BY u.name
")->fetchAll();
?>
<?php if(!$students):?>
<div class="alert alert-secondary">Er zijn nog geen studenten.</div>
<?php else:?>
<div class="row g-3 student-grid">
<?php foreach($students as $student):?>
<div class="col-6 col-md-6 col-lg-4 student-col">
<a href="student.php?id=<?=(int)$student['id']?>" class="leren-tile text-decoration-none">
<div class="subject-card-image" style="background-image:<?=($student['image_mime']?'url(\'student_image.php?id='.(int)$student['id'].'\')':'none')?>;">
<div class="subject-card-overlay"></div>
<div class="card-body position-relative d-flex flex-column justify-content-end subject-card-body">
<h2 class="h4 text-white mb-1 subject-card-title"><?=e($student['name'])?></h2>
<div class="row g-1 mt-1 small student-stats">
<div class="col-4"><div class="bg-light bg-opacity-75 rounded p-2 text-center"><strong><?=e((string)$student['completed_tests'])?></strong><div class="text-secondary">toetsen</div></div></div>
<div class="col-4"><div class="bg-light bg-opacity-75 rounded p-2 text-center"><strong><?=e(rtrim(rtrim(number_format((float)$student['average_score'],0,',','.'),'0'),','))?>%</strong><div class="text-secondary">gem.</div></div></div>
<div class="col-4"><div class="bg-light bg-opacity-75 rounded p-2 text-center"><strong><?=e((string)intdiv((int)$student['active_seconds'],60))?></strong><div class="text-secondary">min.</div></div></div>
</div>
</div>
</div>
</a>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<div class="mt-5 pt-3 border-top d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="ai_usage.php">AI-verbruik &amp; kosten</a><a class="btn btn-outline-primary" href="ai_rules.php">AI-instructies per vak</a><a class="btn btn-outline-primary" href="system_update.php">Website bijwerken</a></div>
</main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>
