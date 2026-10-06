<?php
require __DIR__.'/../app/bootstrap.php';
require_admin();

$studentId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$studentId){redirect('admin.php');}

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
    WHERE tp.is_active=1
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
            'test_date'=>$row['test_date'],
            'subject_name'=>$row['subject_name'],
            'tests'=>[],
            'completed'=>0,
            'total'=>0,
            'weighted_score'=>0,
            'weighted_questions'=>0
        ];
    }
    $topics[$topicKey]['tests'][]=$row;
    $topics[$topicKey]['total']++;
    if($row['score']!==null){
        $topics[$topicKey]['completed']++;
        $questions=max(0,(int)$row['question_count']);
        $topics[$topicKey]['weighted_score']+=(float)$row['score']*$questions;
        $topics[$topicKey]['weighted_questions']+=$questions;
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
<?php if($student['image_mime']):?>
<img src="student_image.php?id=<?=(int)$student['id']?>" class="rounded-circle flex-shrink-0" style="width:76px;height:76px;object-fit:cover;margin-top:-58px;border:4px solid #fff" alt="">
<?php else:?>
<div class="rounded-circle bg-secondary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:76px;height:76px;font-size:2rem">👤</div>
<?php endif;?>
<div>
<h1 class="h2 mb-1"><?=e($student['name'])?></h1>
<div class="text-secondary"><?=e($student['email'])?></div>
</div>
</div>
</div>
</div>

<div class="row g-3 mb-5">
<div class="col-12 col-md-4">
<div class="card shadow-sm h-100"><div class="card-body">
<div class="small text-secondary">Totale leertijd</div>
<div class="display-6 fw-semibold"><?=e(format_duration_student($totalSeconds))?></div>
</div></div>
</div>
<div class="col-12 col-md-4">
<div class="card shadow-sm h-100"><div class="card-body">
<div class="small text-secondary">Tijd aan sub-testen</div>
<div class="display-6 fw-semibold"><?=e(format_duration_student($testSeconds))?></div>
</div></div>
</div>
<div class="col-12 col-md-4">
<div class="card shadow-sm h-100"><div class="card-body">
<div class="small text-secondary">Tijd aan samenvattingen</div>
<div class="display-6 fw-semibold"><?=e(format_duration_student($summarySeconds))?></div>
</div></div>
</div>
</div>

<h2 class="h4 mb-3">Actieve overhoringen</h2>
<?php if(!$topics):?>
<div class="alert alert-secondary">Er zijn geen actieve overhoringen.</div>
<?php else:
$subjects=[];
foreach($topics as $topic){
    $subjectKey=array_search($topic['subject_name'],array_column($subjects,'name'),true);
    if($subjectKey===false){
        $subjects[]=['name'=>$topic['subject_name'],'topics'=>[]];
        $subjectKey=count($subjects)-1;
    }
    $subjects[$subjectKey]['topics'][]=$topic;
}
?>
<div class="accordion shadow-sm mb-4" id="studentSubjects">
<?php foreach($subjects as $subjectIndex=>$subject):?>
<div class="accordion-item">
<h2 class="accordion-header" id="subjectHeading<?=$subjectIndex?>">
<button class="accordion-button <?=$subjectIndex===0?'':'collapsed'?>" type="button" data-bs-toggle="collapse" data-bs-target="#subjectCollapse<?=$subjectIndex?>" aria-expanded="<?=$subjectIndex===0?'true':'false'?>" aria-controls="subjectCollapse<?=$subjectIndex?>">
<strong><?=e($subject['name'])?></strong>
<span class="small text-secondary ms-2"><?=count($subject['topics'])?> <?=count($subject['topics'])===1?'overhoring':'overhoringen'?></span>
</button>
</h2>
<div id="subjectCollapse<?=$subjectIndex?>" class="accordion-collapse collapse <?=$subjectIndex===0?'show':''?>" aria-labelledby="subjectHeading<?=$subjectIndex?>" data-bs-parent="#studentSubjects">
<div class="accordion-body p-2 p-md-3">
<div class="accordion" id="subjectTopics<?=$subjectIndex?>">
<?php foreach($subject['topics'] as $topicIndex=>$topic):
    $mastery=$topic['weighted_questions']>0 ? round($topic['weighted_score']/$topic['weighted_questions'],1) : null;
    $barClass=mastery_class($mastery);
    $label=mastery_label($mastery);
    $completed=$topic['completed'];
    $total=$topic['total'];
    $topicPanelId='topicCollapse'.$subjectIndex.'_'.$topicIndex;
    $topicHeadingId='topicHeading'.$subjectIndex.'_'.$topicIndex;
?>
<div class="accordion-item">
<h3 class="accordion-header" id="<?=$topicHeadingId?>">
<button class="accordion-button <?=$topicIndex===0?'':'collapsed'?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?=$topicPanelId?>" aria-expanded="<?=$topicIndex===0?'true':'false'?>" aria-controls="<?=$topicPanelId?>">
<div class="w-100 d-flex justify-content-between align-items-center gap-3 pe-2">
<div>
<strong><?=e($topic['name'])?></strong>
<?php if($topic['test_date']):?><div class="small text-secondary">Overhoring: <?=e(date('d-m-Y',strtotime($topic['test_date'])))?></div><?php endif;?>
</div>
<div class="text-end">
<?php if($mastery===null):?>
<div class="fw-semibold text-secondary">—</div>
<?php else:?>
<div class="fw-semibold text-<?=$barClass?>"><?=e(rtrim(rtrim(number_format($mastery,1,',','.'),'0'),','))?>%</div>
<?php endif;?>
<div class="small text-secondary"><?=$completed?>/<?=$total?></div>
</div>
</div>
</button>
</h3>
<div id="<?=$topicPanelId?>" class="accordion-collapse collapse <?=$topicIndex===0?'show':''?>" aria-labelledby="<?=$topicHeadingId?>" data-bs-parent="#subjectTopics<?=$subjectIndex?>">
<div class="accordion-body">
<div class="mastery-card mb-4">
<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-2">
<div>
<div class="small text-secondary text-uppercase fw-semibold" style="letter-spacing:.04em">Kennisniveau</div>
<div class="small text-secondary"><?=$completed?> van <?=$total?> sub-testen afgerond · <?=e($label)?></div>
</div>
<?php if($mastery===null):?>
<div class="mastery-score text-secondary">—</div>
<?php else:?>
<div class="mastery-score text-<?=$barClass?>"><?=e(rtrim(rtrim(number_format($mastery,1,',','.'),'0'),','))?>%</div>
<?php endif;?>
</div>
<div class="progress student-progress" role="progressbar" aria-label="Kennisniveau" aria-valuenow="<?=e((string)($mastery??0))?>" aria-valuemin="0" aria-valuemax="100">
<div class="progress-bar bg-<?=$barClass?>" style="width:<?=e((string)($mastery??0))?>%"></div>
</div>
</div>

<?php $topicSummaries=$summaryByTopic[(int)$topic['id']]??[]; ?>
<?php if($topicSummaries):?>
<div class="mb-3">
<div class="small fw-semibold text-secondary mb-2">Samenvattingen</div>
<div class="list-group">
<?php foreach($topicSummaries as $summary):?>
<div class="list-group-item">
<div class="d-flex justify-content-between align-items-center gap-3">
<div>
<strong><?=e($summary['name'])?></strong>
<?php if(!(int)$summary['is_active']):?><span class="badge text-bg-secondary ms-2">Inactief</span><?php endif;?>
</div>
<div class="text-end fw-semibold"><?=e(format_duration_student((int)$summary['active_seconds']))?></div>
</div>
</div>
<?php endforeach;?>
</div>
</div>
<?php endif;?>

<div class="small fw-semibold text-secondary mb-2">Sub-testen</div>
<div class="list-group">
<?php foreach($topic['tests'] as $test):?>
<div class="list-group-item">
<div class="d-flex justify-content-between align-items-center gap-3">
<div>
<strong><?=e($test['title'])?></strong>
<div class="small text-secondary"><?=e($test['test_type']??'mixed')?> · <?=((int)$test['question_count'])?> vragen</div>
</div>
<div class="text-end">
<?php if($test['score']!==null):?>
<strong class="text-<?=mastery_class((float)$test['score'])?>"><?=e(rtrim(rtrim(number_format((float)$test['score'],1,',','.'),'0'),','))?>%</strong>
<?php else:?>
<span class="small text-secondary">Nog niet gedaan</span>
<?php endif;?>
</div>
</div>
</div>
<?php endforeach;?>
</div>
</div>
</div>
</div>
<?php endforeach;?>
</div>
</div>
</div>
<?php endforeach;?>
</div>
<?php endif;?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</main>
</body>
</html>
