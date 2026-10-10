<?php
require __DIR__.'/../app/bootstrap.php';require_manager();
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
    if(!$id){http_response_code(400);exit('Ongeldig ID.');}
    if(in_array($action,['upload_subject_image','delete_subject_image','delete_subject'],true))require_subject_management((int)$id);
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
        $owner=$pdo->prepare('SELECT tp.subject_id FROM tests t JOIN topics tp ON tp.id=t.topic_id WHERE t.id=?');
        $owner->execute([$id]);
        require_subject_management((int)$owner->fetchColumn());
        $x=$pdo->prepare("SELECT title FROM tests WHERE id=?");
        $x->execute([$id]);$item=$x->fetch();
        if(!$item){http_response_code(404);exit('Test niet gevonden.');}
        $x=$pdo->prepare("DELETE FROM tests WHERE id=?");$x->execute([$id]);
        redirect('admin.php?deleted=test');
    }
    if($action==='delete_session'){
        $owner=$pdo->prepare('SELECT student_id FROM study_sessions WHERE id=?');
        $owner->execute([$id]);
        require_manage_student((int)$owner->fetchColumn());
        $x=$pdo->prepare("SELECT id FROM study_sessions WHERE id=?");
        $x->execute([$id]);if(!$x->fetch()){http_response_code(404);exit('Oefensessie niet gevonden.');}
        $x=$pdo->prepare("DELETE FROM study_sessions WHERE id=?");$x->execute([$id]);
        redirect('admin.php?deleted=session');
    }
    if($action==='delete_attempt'){
        $owner=$pdo->prepare('SELECT student_id FROM attempts WHERE id=?');
        $owner->execute([$id]);
        require_manage_student((int)$owner->fetchColumn());
        $x=$pdo->prepare("SELECT id FROM attempts WHERE id=? AND finished_at IS NOT NULL");
        $x->execute([$id]);if(!$x->fetch()){http_response_code(404);exit('Resultaat niet gevonden.');}
        $x=$pdo->prepare("DELETE FROM attempts WHERE id=?");$x->execute([$id]);
        redirect('admin.php?deleted=attempt');
    }
    http_response_code(400);exit('Ongeldige actie.');
}
$allowedSubjects=is_admin()?null:array_unique(array_merge([(int)($_SESSION['user']['id']??0)],managed_student_ids()));
$subjectSql="SELECT s.id,s.name,s.image_mime,(SELECT COUNT(*) FROM topics tp WHERE tp.subject_id=s.id) AS overhoring_count,(SELECT COUNT(*) FROM tests t JOIN topics tp ON tp.id=t.topic_id WHERE tp.subject_id=s.id) AS test_count FROM subjects s";
if($allowedSubjects!==null)$subjectSql.=" WHERE s.user_id IN (".implode(',',array_fill(0,count($allowedSubjects),'?')).")";
$subjectSql.=" ORDER BY s.name";
$subjectStmt=$pdo->prepare($subjectSql);
$subjectStmt->execute($allowedSubjects===null?[]:array_values($allowedSubjects));
$subjects=$subjectStmt->fetchAll();
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
<?php if(is_admin()):?>
<section class="leren-section mb-4">
<h1 class="mb-2">Administrator</h1>
<div class="leren-actions">
<a class="btn btn-outline-primary" href="user_permissions.php">Gebruikers &amp; rechten</a>
<a class="btn btn-outline-primary" href="ai_usage.php">AI-verbruik &amp; kosten</a>
<a class="btn btn-outline-primary" href="ai_rules.php">AI-instructies per vak</a>
<a class="btn btn-outline-primary" href="trash.php">Prullenbak</a>
<a class="btn btn-outline-primary" href="system_update.php">Website bijwerken</a>
</div>
</section>
<?php endif;?>

<?php
$studentScopeSql=is_admin()
    ? '1=1'
    : 'EXISTS (SELECT 1 FROM manager_students ms WHERE ms.manager_id='.(int)($_SESSION['user']['id']??0).' AND ms.student_id=u.id)';
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
        FROM attempts a
        WHERE a.status='finished' AND a.mode='normal'
          AND EXISTS (
              SELECT 1 FROM attempt_answers az
              WHERE az.attempt_id=a.id
                AND (
                    (az.answer_text IS NOT NULL AND TRIM(az.answer_text)<>'')
                    OR az.selected_option_id IS NOT NULL
                )
          )
        GROUP BY a.student_id
    ) a ON a.student_id=u.id
    WHERE u.role='student' AND ($studentScopeSql)
    ORDER BY u.name
")->fetchAll();
?>
<?php if($students):?>
<section class="leren-section mt-4">
<div class="d-flex justify-content-between align-items-center mb-3">
<h1 class="mb-0">Studenten</h1>
<a class="btn btn-outline-primary" href="student_new.php">Nieuwe student</a>
</div>
<div class="leren-list">
<?php foreach($students as $student):?>
<?php
$studentId=(int)$student['id'];
$studentActions=[
 ['label'=>'Bekijken','href'=>'student.php?id='.$studentId,'primary'=>true],
 ['label'=>'Bewerken','href'=>'user_edit.php?id='.$studentId],
];
if(is_admin()){
 $studentActions[]=['type'=>'form','label'=>'Student verwijderen','action'=>'user_edit.php','fields'=>['action'=>'delete','id'=>$studentId],'confirm'=>'Weet je zeker dat je deze student wilt verwijderen?','danger'=>true];
}
?>
<div class="leren-list-item">
<?php if($student['image_mime']):?><div class="leren-list-item-thumbnail"><img src="student_image.php?id=<?=$studentId?>" alt="" loading="lazy"></div>
<?php else:?><div class="leren-list-item-thumbnail" aria-hidden="true"></div><?php endif;?>
<a class="leren-list-item-main" href="student.php?id=<?=$studentId?>">
<div class="leren-list-item-content">
<div class="leren-list-item-heading"><div class="leren-list-item-title"><?=e($student['name'])?></div></div>
<div class="leren-list-item-description"><?=e(number_format((float)$student['average_score'],0,',','.'))?>% gemiddeld</div>
<div class="leren-list-item-description"><?=e((string)intdiv((int)$student['active_seconds'],60))?> min. actief</div>
</div>
</a>
<button type="button" class="leren-list-item-menu" data-list-menu data-list-modal="studentOptionsModal" data-menu-title="<?=e($student['name'])?>" data-list-actions="<?=e(json_encode($studentActions,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>" aria-label="Opties voor <?=e($student['name'])?>">
<span></span><span></span><span></span>
</button>
</div>
<?php endforeach;?>
</div>
</section>
<?php endif;?>
<div class="leren-modal" id="studentOptionsModal" data-list-modal hidden aria-hidden="true">
<div class="leren-modal-backdrop" data-list-modal-close></div>
<div class="leren-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="studentOptionsTitle">
<button type="button" class="leren-modal-close" data-list-modal-close aria-label="Sluiten">&times;</button>
<h2 id="studentOptionsTitle" data-list-modal-title>Student</h2>
<div class="leren-modal-actions" data-list-modal-actions></div>
</div>
</div>


<section class="leren-section admin-subjects-section">
<div class="d-flex justify-content-between align-items-center mb-3">
<h1 class="mb-0">Vakken</h1>
<div><a class="btn btn-outline-primary" href="subject_edit.php">Nieuw vak</a></div>
</div>

<div class="leren-list mb-5">
<?php foreach($subjects as $subject):?>
<?php
$subjectId=(int)$subject['id'];
$subjectActions=[
 ['label'=>'Bekijken','href'=>'subject_manage.php?id='.$subjectId,'primary'=>true],
 ['label'=>'Bewerken','href'=>'subject_edit.php?id='.$subjectId],
];
if(is_admin()){
 $subjectActions[]=['type'=>'form','label'=>'Vak verwijderen','action'=>'admin.php','fields'=>['action'=>'delete_subject','id'=>$subjectId],'confirm'=>'Weet je zeker dat je dit vak wilt verwijderen? Ook gekoppelde overhoringen en toetsen kunnen worden verwijderd.','danger'=>true];
}
?>
<div class="leren-list-item">
<?php if($subject['image_mime']):?>
<div class="leren-list-item-thumbnail"><img src="subject_image.php?id=<?=$subjectId?>" alt="" loading="lazy"></div>
<?php else:?><div class="leren-list-item-thumbnail" aria-hidden="true"></div><?php endif;?>
<a class="leren-list-item-main" href="subject_manage.php?id=<?=$subjectId?>">
<div class="leren-list-item-content">
<div class="leren-list-item-heading"><div class="leren-list-item-title"><?=e($subject['name'])?></div></div>
<div class="leren-list-item-description"><?=(int)$subject['overhoring_count']?> <?=((int)$subject['overhoring_count']===1?'overhoring':'overhoringen')?> &middot; <?=(int)$subject['test_count']?> <?=((int)$subject['test_count']===1?'test':'testen')?></div>
</div>
</a>
<button type="button" class="leren-list-item-menu" data-list-menu data-list-modal="subjectOptionsModal" data-menu-title="<?=e($subject['name'])?>" data-list-actions="<?=e(json_encode($subjectActions,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>" aria-label="Opties voor <?=e($subject['name'])?>">
<span></span><span></span><span></span>
</button>
</div>
<?php endforeach;?>
</div>
<div class="leren-modal" id="subjectOptionsModal" data-list-modal hidden aria-hidden="true">
<div class="leren-modal-backdrop" data-list-modal-close></div>
<div class="leren-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="subjectOptionsTitle">
<button type="button" class="leren-modal-close" data-list-modal-close aria-label="Sluiten">&times;</button>
<h2 id="subjectOptionsTitle" data-list-modal-title>Vak</h2>
<div class="leren-modal-actions" data-list-modal-actions></div>
</div>
</div>
</section>



</main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>
