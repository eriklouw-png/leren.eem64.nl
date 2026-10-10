<?php
require __DIR__.'/../app/bootstrap.php';
require_admin();

$studentId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$studentId){redirect('admin.php');}
require_manage_student((int)$studentId);

$x=$pdo->prepare("SELECT id,name,email,image_mime FROM users WHERE id=? AND role='student'");
$x->execute([$studentId]);
$student=$x->fetch();
if(!$student){http_response_code(404);exit('Student niet gevonden.');}

$x=$pdo->prepare("SELECT COALESCE(SUM(active_seconds),0) FROM study_sessions WHERE student_id=?");
$x->execute([$studentId]);
$totalSeconds=(int)$x->fetchColumn();

$x=$pdo->prepare("SELECT COALESCE(SUM(active_seconds),0) FROM study_sessions WHERE student_id=? AND activity_type='test'");
$x->execute([$studentId]);
$testSeconds=(int)$x->fetchColumn();

$x=$pdo->prepare("SELECT COALESCE(SUM(active_seconds),0) FROM study_sessions WHERE student_id=? AND activity_type='summary'");
$x->execute([$studentId]);
$summarySeconds=(int)$x->fetchColumn();

$x=$pdo->prepare("
    SELECT
        ts.id,
        ts.topic_id,
        ts.name,
        ts.is_active,
        tp.name AS topic_name,
        s.name AS subject_name,
        COALESCE(SUM(ss.active_seconds),0) AS active_seconds
    FROM topic_summaries ts
    JOIN topics tp ON tp.id=ts.topic_id
    JOIN subjects s ON s.id=tp.subject_id
    LEFT JOIN study_sessions ss
        ON ss.summary_id=ts.id
       AND ss.student_id=?
       AND ss.activity_type='summary'
    GROUP BY ts.id,ts.name,ts.is_active,tp.name,s.name
    ORDER BY s.name,tp.name,ts.name
");
$x->execute([$studentId]);
$summaryRows=$x->fetchAll();
$summaryByTopic=[];
foreach($summaryRows as $summary){
    $summaryByTopic[(int)$summary['topic_id']][]=$summary;
}

$x=$pdo->prepare("
    SELECT
        tp.id topic_id,tp.name topic_name,tp.test_date,
        s.id subject_id,s.name subject_name,
        t.id test_id,t.title,t.test_type,
        COUNT(q.id) question_count,
        la.score,la.finished_at
    FROM topics tp
    JOIN subjects s ON s.id=tp.subject_id
    JOIN tests t ON t.topic_id=tp.id AND t.is_active=1
    LEFT JOIN questions q ON q.test_id=t.id
    LEFT JOIN (
        SELECT a1.id,a1.test_id,a1.score,a1.finished_at
        FROM attempts a1
        WHERE a1.student_id=?
          AND a1.status='finished'
          AND a1.mode='normal'
          AND NOT EXISTS (
              SELECT 1
              FROM attempts a2
              WHERE a2.student_id=a1.student_id
                AND a2.test_id=a1.test_id
                AND a2.status='finished'
                AND a2.mode='normal'
                AND (
                    a2.finished_at>a1.finished_at
                    OR (a2.finished_at=a1.finished_at AND a2.id>a1.id)
                )
          )
    ) la ON la.test_id=t.id
    WHERE tp.is_active=1 AND s.user_id=$studentId
      AND (tp.test_date IS NULL OR tp.test_date>=CURDATE())
    GROUP BY tp.id,tp.name,tp.test_date,s.id,s.name,t.id,t.title,t.test_type,la.score,la.finished_at
    ORDER BY s.name,tp.name,t.title
");
$x->execute([$studentId]);
$rows=$x->fetchAll();

$topics=[];
foreach($rows as $row){
    $topicKey=(int)$row['topic_id'];
    if(!isset($topics[$topicKey])){
        $topics[$topicKey]=[
            'id'=>$topicKey,
            'name'=>$row['topic_name'],
            'subject_id'=>(int)$row['subject_id'],
            'test_date'=>$row['test_date'],
            'subject_name'=>$row['subject_name'],
            'tests'=>[],
            'completed'=>0,
            'total'=>0,
            'score_total'=>0,
            'score_count'=>0
        ];
    }
    $topics[$topicKey]['tests'][]=$row;
    $topics[$topicKey]['total']++;
    if($row['score']!==null){
        $topics[$topicKey]['completed']++;
        $topics[$topicKey]['score_total']+=(float)$row['score'];
        $topics[$topicKey]['score_count']++;
    }
}

function format_duration_student(int $seconds):string{
    $hours=intdiv($seconds,3600);
    $minutes=intdiv($seconds%3600,60);
    if($hours>0)return $hours.' uur '.str_pad((string)$minutes,2,'0',STR_PAD_LEFT).' min';
    return $minutes.' min '.str_pad((string)($seconds%60),2,'0',STR_PAD_LEFT).' sec';
}
function mastery_class(?float $score):string{
    if($score===null)return 'secondary';
    if($score>=70)return 'success';
    if($score>=50)return 'warning';
    return 'danger';
}
function mastery_label(?float $score):string{
    if($score===null)return 'Nog niet geoefend';
    if($score>=70)return 'Goed';
    if($score>=50)return 'Aandacht nodig';
    return 'Onvoldoende';
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($student['name'])?> - Beheer</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.student-progress{height:10px;border-radius:999px}
.mastery-card{padding:0}
.mastery-score{font-size:1.45rem;font-weight:700;line-height:1}

.student-subject-row{display:flex;align-items:center;text-decoration:none;color:inherit}
.student-subject-row .leren-list-item-main{flex:1;min-width:0}
.student-subject-grade{min-width:5rem;align-self:stretch;display:flex;align-items:center;justify-content:flex-end;text-align:right;padding:0 .6rem;white-space:nowrap}
.student-subject-grade strong{font-size:1.7rem;font-weight:700;font-variant-numeric:tabular-nums}
.student-subject-chevron{display:flex;align-items:center;justify-content:center;flex:0 0 2.5rem;color:inherit;padding-right:.8rem}
.student-subject-row:hover{color:inherit}
.student-subject-progress{height:7px;background:rgba(150,160,165,.25);border-radius:999px;overflow:hidden;margin:.65rem 0 .4rem}
.student-subject-progress span{display:block;height:100%;border-radius:inherit}
.student-subject-count{font-size:.85rem;color:#aeb9b3}
.student-subject-row .leren-list-item-content{width:100%}
.student-subject-row .leren-list-item-main{padding-top:1rem;padding-bottom:1rem}
</style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
<div class="container">
<a class="navbar-brand" href="admin.php">Leren beheer</a>
<div>
<span class="text-white me-3"><?=e($_SESSION['user']['name'])?></span>
<a class="btn btn-outline-light btn-sm me-2" href="index.php">Website</a>
<a class="btn btn-outline-light btn-sm" href="logout.php">Uitloggen</a>
</div>
</div>
</nav>
<main class="container py-4" style="max-width:1100px">
<a href="admin.php">&larr; Beheer</a>
<div class="card shadow-sm mt-3 mb-4 overflow-hidden">
<?php if($student['image_mime']):?>
<img src="student_image.php?id=<?=(int)$student['id']?>" style="height:220px;width:100%;object-fit:cover" alt="<?=e($student['name'])?>">
<?php endif;?>
<div class="card-body p-4">
<div class="d-flex align-items-center gap-3">
<div class="flex-grow-1 min-w-0">
<h1 class="h2 mb-1"><?=e($student['name'])?></h1>
<div class="text-secondary text-truncate"><?=e($student['email'])?></div>
</div>
<a class="btn btn-outline-primary flex-shrink-0" href="user_edit.php?id=<?=(int)$student['id']?>">Bewerken</a>
</div>
</div>
</div>

<div class="row g-2 mb-4">
<div class="col-12 col-md-4">
<div class="card shadow-sm h-100"><div class="card-body py-2 px-3 d-flex justify-content-between align-items-center gap-3">
<div class="small text-secondary">Totale leertijd</div>
<div class="fw-semibold"><?=e(format_duration_student($totalSeconds))?></div>
</div></div>
</div>
<div class="col-12 col-md-4">
<div class="card shadow-sm h-100"><div class="card-body py-2 px-3 d-flex justify-content-between align-items-center gap-3">
<div class="small text-secondary">Testen</div>
<div class="fw-semibold"><?=e(format_duration_student($testSeconds))?></div>
</div></div>
</div>
<div class="col-12 col-md-4">
<div class="card shadow-sm h-100"><div class="card-body py-2 px-3 d-flex justify-content-between align-items-center gap-3">
<div class="small text-secondary">Samenvattingen</div>
<div class="fw-semibold"><?=e(format_duration_student($summarySeconds))?></div>
</div></div>
</div>
</div>

<?php
$subjectListQuery=$pdo->prepare('SELECT id,name FROM subjects WHERE user_id=? ORDER BY name');
$subjectListQuery->execute([$studentId]);
$subjectList=$subjectListQuery->fetchAll(PDO::FETCH_ASSOC);
$gradeQuery=$pdo->prepare("
    SELECT tp.subject_id,
           SUM(g.grade*g.weight)/NULLIF(SUM(g.weight),0) AS average_grade
    FROM topic_grades g
    JOIN topics tp ON tp.id=g.topic_id
    WHERE g.student_id=?
    GROUP BY tp.subject_id
");
$gradeQuery->execute([$studentId]);
$gradeAverages=[];
foreach($gradeQuery->fetchAll(PDO::FETCH_ASSOC) as $gradeRow){
    $gradeAverages[(int)$gradeRow['subject_id']]=(float)$gradeRow['average_grade'];
}
$subjectProgress=[];
foreach($topics as $topic){
    $sid=(int)$topic['subject_id'];
    if(!isset($subjectProgress[$sid]))$subjectProgress[$sid]=['completed'=>0,'total'=>0];
    $subjectProgress[$sid]['completed']+=(int)$topic['completed'];
    $subjectProgress[$sid]['total']+=(int)$topic['total'];
}
?>
<h2 class="h4 mb-3">Alle vakken</h2>
<?php if(!$subjectList):?>
<div class="alert alert-secondary">Er zijn nog geen vakken.</div>
<?php else:?>
<div class="leren-list mb-4">
<?php foreach($subjectList as $subject):
    $subjectId=(int)$subject['id'];
    $average=$gradeAverages[$subjectId]??null;
    $progress=$subjectProgress[$subjectId]??['completed'=>0,'total'=>0];
    $progressPercent=$progress['total']>0?round(100*$progress['completed']/$progress['total']):0;
    $progressColor=$progressPercent<25?'#dc3545':($progressPercent<75?'#f59e0b':'#198754');
?>
<a class="leren-list-item student-subject-row" href="student_subject.php?id=<?=$studentId?>&amp;subject_id=<?=$subjectId?>">
<div class="leren-list-item-main">
<div class="leren-list-item-content">
<div class="leren-list-item-heading"><strong class="leren-list-item-title"><?=e($subject['name'])?></strong></div>
<div class="student-subject-progress" role="progressbar" aria-valuenow="<?=$progressPercent?>" aria-valuemin="0" aria-valuemax="100" aria-label="Voortgang <?=$progressPercent?>%">
<span style="width:<?=$progressPercent?>%;background:<?=$progressColor?>"></span>
</div>
<div class="student-subject-count"><?=$progress['completed']?> van <?=$progress['total']?> testen afgerond</div>
</div>
</div>
<div class="student-subject-grade" aria-label="Gemiddeld cijfer">
<?php if($average!==null):?>
<strong><?=e(number_format($average,1,',','.'))?></strong>
<?php else:?>
<strong class="text-secondary">—</strong>
<?php endif;?>
</div>
<span class="student-subject-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></span>
</a>
<?php endforeach;?>
</div>
<?php endif;?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</main>
</body>
</html>
