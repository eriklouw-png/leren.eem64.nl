<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';
require_login();
$currentUser=current_user();
$studentId=(int)$currentUser['id'];

$topicId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$topicId)redirect('index.php');

$x=$pdo->prepare("SELECT tp.id,tp.name,tp.test_date,tp.is_active,tp.use_summary,s.id subject_id,s.name subject_name,s.description subject_description,s.image_mime FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
$x->execute([$topicId]);
$topic=$x->fetch();
if(!$topic || !(int)$topic['is_active']){http_response_code(404);exit('Overhoring niet gevonden.');}

$summaryStmt=$pdo->prepare("SELECT id,name,summary,updated_at,created_at FROM topic_summaries WHERE topic_id=? AND is_active=1 ORDER BY created_at,id");
$summaryStmt->execute([$topicId]);
$topicSummaries=$summaryStmt->fetchAll();
usort($topicSummaries,function(array $a,array $b):int{
    return strnatcasecmp((string)$a['name'],(string)$b['name']);
});

$archived=!empty($topic['test_date']) && $topic['test_date'] < date('Y-m-d');

$x=$pdo->prepare("
SELECT
    t.id,t.title,t.description,t.test_type,t.vocab_direction,
    COUNT(DISTINCT q.id) question_count,
    ip.id in_progress_attempt_id,
    COALESCE(ip.answered_count,0) in_progress_answered_count,
    COALESCE(ip.total_count,COUNT(DISTINCT q.id)) in_progress_total_count
FROM tests t
LEFT JOIN questions q ON q.test_id=t.id
LEFT JOIN (
    SELECT
        a.id,a.test_id,
        COUNT(aq.question_id) total_count,
        COUNT(CASE
            WHEN aa.id IS NOT NULL
             AND ((aa.answer_text IS NOT NULL AND TRIM(aa.answer_text) <> '') OR aa.selected_option_id IS NOT NULL)
            THEN 1 END) answered_count
    FROM attempts a
    JOIN (
        SELECT test_id,MAX(id) id
        FROM attempts
        WHERE student_id=? AND status='in_progress' AND mode='normal'
        GROUP BY test_id
    ) latest ON latest.id=a.id
    JOIN attempt_questions aq ON aq.attempt_id=a.id
    LEFT JOIN attempt_answers aa
        ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id
    GROUP BY a.id,a.test_id
) ip ON ip.test_id=t.id
WHERE t.topic_id=? AND t.is_active=1
GROUP BY t.id,t.title,t.description,t.test_type,t.vocab_direction,ip.id,ip.answered_count,ip.total_count
");
$x->execute([$studentId,$topicId]);
$tests=$x->fetchAll();
usort($tests,function(array $a,array $b):int{
    return strnatcasecmp((string)$a['title'],(string)$b['title']);
});

/* Zodra alle gewone sub-testen minimaal één keer zijn afgerond, kan de leerling
 * alle resterende fouten uit de laatste pogingen gezamenlijk oefenen. */
$reviewAvailable=false;
$reviewWrongCount=0;
$reviewInProgressId=0;
$reviewProgressAnswered=0;
$reviewProgressTotal=0;
if($tests){
    $completedStmt=$pdo->prepare("
        SELECT COUNT(DISTINCT a.test_id)
        FROM attempts a
        JOIN tests t ON t.id=a.test_id
        WHERE a.student_id=? AND a.status='finished' AND a.mode='normal'
          AND t.topic_id=? AND t.is_active=1
    ");
    $completedStmt->execute([$studentId,$topicId]);
    $completedCount=(int)$completedStmt->fetchColumn();

    if($completedCount===count($tests)){
        $wrongStmt=$pdo->prepare("
            SELECT COUNT(DISTINCT aq.question_id)
            FROM tests t
            JOIN (
                SELECT a.test_id,MAX(a.id) attempt_id
                FROM attempts a
                JOIN tests lt ON lt.id=a.test_id
                WHERE a.student_id=? AND a.status='finished' AND a.mode='normal'
                  AND lt.topic_id=? AND lt.is_active=1
                GROUP BY a.test_id
            ) latest ON latest.test_id=t.id
            JOIN attempt_questions aq ON aq.attempt_id=latest.attempt_id
            JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id
            WHERE t.topic_id=? AND t.is_active=1 AND aa.is_correct=0
        ");
        $wrongStmt->execute([$studentId,$topicId,$topicId]);
        $reviewWrongCount=(int)$wrongStmt->fetchColumn();

        /* Alleen een lopende review hervatten als die exact de huidige
         * resterende fouten bevat. Oude/lege review-attempts met bijvoorbeeld
         * 32 vragen mogen na het afronden van 23 vragen niet meer als 0/32
         * worden gepresenteerd wanneer er nog maar 9 fouten over zijn. */
        if($reviewWrongCount>0){
            $reviewProgressStmt=$pdo->prepare("\n                SELECT a.id,\n                       COUNT(aq.question_id) total_count,\n                       COUNT(CASE WHEN aa.id IS NOT NULL\n                            AND ((aa.answer_text IS NOT NULL AND TRIM(aa.answer_text)<>'') OR aa.selected_option_id IS NOT NULL)\n                            THEN 1 END) answered_count\n                FROM attempts a\n                JOIN attempt_questions aq ON aq.attempt_id=a.id\n                LEFT JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id\n                JOIN tests rt ON rt.id=a.test_id\n                WHERE a.student_id=? AND a.status='in_progress' AND a.mode='mistakes'\n                  AND rt.topic_id=? AND rt.title='Fouten oefenen'\n                GROUP BY a.id\n                HAVING COUNT(aq.question_id)=?\n                ORDER BY answered_count DESC, a.id DESC\n                LIMIT 1\n            ");
            $reviewProgressStmt->execute([$studentId,$topicId,$reviewWrongCount]);
            if($reviewProgress=$reviewProgressStmt->fetch()){
                $reviewInProgressId=(int)$reviewProgress['id'];
                $reviewProgressTotal=(int)$reviewProgress['total_count'];
                $reviewProgressAnswered=(int)$reviewProgress['answered_count'];
            }
        }
        $reviewAvailable=$reviewWrongCount>0;
    }
}

$historyStmt=$pdo->prepare("
    SELECT id,score,finished_at
    FROM attempts
    WHERE test_id=? AND student_id=? AND status='finished' AND mode='normal'
    ORDER BY finished_at DESC,id DESC
");
$historyByTest=[];
foreach($tests as $testRow){
    $historyStmt->execute([(int)$testRow['id'],$studentId]);
    $historyByTest[(int)$testRow['id']]=$historyStmt->fetchAll();
}

$labels=['vocabulary'=>'Woordjes oefenen','multiple_choice'=>'Multiple choice','open'=>'Open vragen','mixed'=>'Combinatie'];
if(!isset($_SESSION['learner_token']))$_SESSION['learner_token']=bin2hex(random_bytes(32));
$browserToken=$_SESSION['learner_token'];
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($topic['name'])?> - Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.subject-header{position:relative;min-height:220px;border-radius:1rem;overflow:hidden;background:#6c757d}
.subject-header-image{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.subject-header-overlay{position:absolute;inset:0;background:linear-gradient(90deg,rgba(0,0,0,.68),rgba(0,0,0,.2))}
.subject-header-content{position:relative;z-index:1;min-height:220px;display:flex;flex-direction:column;justify-content:end;padding:2rem;color:#fff}
.subject-header-content h1{font-size:clamp(2rem,7vw,3.5rem);margin:0}
.subject-header-content p{margin:.35rem 0 0;color:rgba(255,255,255,.8)}
.subtest-history summary{cursor:pointer;list-style:none}
.subtest-history summary::-webkit-details-marker{display:none}
.history-chevron{width:10px;height:10px;border-right:2px solid #6c757d;border-bottom:2px solid #6c757d;transform:rotate(45deg);transition:transform .15s ease;margin-right:4px;margin-top:-5px}
.subtest-history[open] .history-chevron{transform:rotate(225deg);margin-top:5px}
</style>
</head>
<body class="bg-light">
<main class="container py-4">
<a href="subject.php?id=<?=(int)$topic['subject_id']?>">&larr; Terug naar <?=e($topic['subject_name'])?></a>

<div class="subject-header mt-3">
<?php if($topic['image_mime']):?><img class="subject-header-image" src="subject_image.php?id=<?=(int)$topic['subject_id']?>" alt=""><?php endif;?>
<div class="subject-header-overlay"></div>
<div class="subject-header-content">
<h1><?=e($topic['name'])?></h1>
<?php if($topic['test_date']):?>
<p>Overhoring: <?=e(date('d-m-Y',strtotime($topic['test_date'])))?><?=$archived?' · Gearchiveerd':''?></p>
<?php else:?>
<p>Overhoring</p>
<?php endif;?>
</div>
</div>

<?php if($topicSummaries):?>
<div class="mt-4 mb-4">
<div class="d-flex justify-content-between align-items-center mb-3">
<h2 class="h3 mb-0">Samenvattingen</h2>
<span class="small text-secondary"><?=count($topicSummaries)?> beschikbaar</span>
</div>
<div class="list-group shadow-sm">
<?php foreach($topicSummaries as $summary):?>
<div class="list-group-item p-3 p-md-4">
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
<div class="min-w-0">
<strong class="fs-5"><?=e($summary['name'])?></strong>
</div>
<div class="d-flex flex-wrap gap-2 flex-shrink-0">
<a class="btn btn-outline-primary btn-sm" href="summary.php?id=<?=(int)$summary['id']?>">Lees</a>
</div>
</div>
</div>
<?php endforeach;?>
</div>
</div>
<?php endif;?>

<h2 class="h3 mt-4 mb-3">Sub-Testen</h2>
<?php if($reviewAvailable):?>
<div class="card border-success shadow-sm mb-4">
<div class="card-body p-3 p-md-4">
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
<div class="min-w-0">
<h2 class="h4 mb-1">Fouten oefenen</h2>
<?php if($reviewInProgressId):?>
<p class="text-secondary mb-2">Ga verder met de fouten uit de sub-testen.</p>
<?php if($reviewProgressTotal>0):?><div class="small text-secondary">Voortgang: <?=$reviewProgressAnswered?> van <?=$reviewProgressTotal?> vragen</div><?php endif;?>
<?php else:?>
<p class="text-secondary mb-0">Alle <?=e((string)$reviewWrongCount)?> vragen die je in de laatste pogingen fout had, verzameld in één oefentoets.</p>
<?php endif;?>
</div>
<a class="btn btn-success flex-shrink-0" href="quiz.php?review=1&topic_id=<?=(int)$topicId?>&attempt=<?=(int)$reviewInProgressId?>"><?= $reviewInProgressId?'Ga verder':'Start fouten oefenen' ?></a>
</div>
</div>
</div>
<?php endif;?>
<?php if(!$tests):?>
<div class="alert alert-info">Er zijn nog geen sub-testen voor deze overhoring.</div>
<?php else:?>
<div class="list-group shadow-sm">
<?php foreach($tests as $t):?>
<div class="list-group-item p-3 p-md-4">
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
<div class="min-w-0">
<strong class="fs-5"><?=e($t['title'])?></strong>
<div class="small text-secondary"><?=e($labels[$t['test_type']??'mixed']??'Combinatie')?> · <?=((($t['test_type']??'mixed')==='vocabulary' && ($t['vocab_direction']??'both')==='both') ? (int)ceil(((int)$t['question_count'])/2) : (int)$t['question_count'])?> <?=($t['test_type']??'mixed')==='vocabulary'?'woorden':'vragen'?></div>
<?php if($t['description']):?><div class="text-secondary mt-1"><?=e($t['description'])?></div><?php endif;?>
</div>

<div class="d-flex flex-wrap gap-2 flex-shrink-0">
<?php if($t['in_progress_attempt_id']):?>
<a class="btn btn-success btn-sm" href="quiz.php?id=<?=(int)$t['id']?>">Ga Verder</a>
<a class="btn btn-outline-secondary btn-sm" href="quiz.php?id=<?=(int)$t['id']?>&new=1">Start Opnieuw</a>
<?php else:?>
<a class="btn btn-outline-primary btn-sm" href="quiz.php?id=<?=(int)$t['id']?>">Start</a>
<?php endif;?>
</div>
</div>

<?php if($t['in_progress_attempt_id']):
$progressTotal=max(1,(int)$t['in_progress_total_count']);
$progressAnswered=min($progressTotal,(int)$t['in_progress_answered_count']);
$progressPercent=(int)round($progressAnswered/$progressTotal*100);
?>
<div class="mt-4">
<div class="d-flex justify-content-between small text-secondary mb-1">
<span>Voortgang</span>
<span><?=$progressAnswered?> van <?=$progressTotal?> vragen</span>
</div>
<div class="progress" role="progressbar" aria-label="Voortgang van sub-test" aria-valuenow="<?=$progressPercent?>" aria-valuemin="0" aria-valuemax="100" style="height:10px">
<div class="progress-bar bg-success" style="width:<?=$progressPercent?>%"></div>
</div>
</div>
<?php endif;?>

<?php $history=$historyByTest[(int)$t['id']]??[]; ?>
<?php if($history):?>
<details class="mt-4 pt-3 border-top subtest-history">
<summary class="d-flex justify-content-between align-items-center list-unstyled">
<span class="small fw-semibold text-secondary">Eerdere resultaten</span>
<span class="history-chevron" aria-hidden="true"></span>
</summary>
<div class="d-flex flex-column gap-2 mt-2">
<?php foreach($history as $attempt):?>
<a href="result.php?id=<?=(int)$attempt['id']?>" class="text-decoration-none">
<div class="d-flex justify-content-between align-items-center py-1">
<span class="text-secondary small"><?=e(date('d-m-Y H:i',strtotime((string)$attempt['finished_at'])))?></span>
<strong class="<?=((float)$attempt['score']>=70?'text-success':((float)$attempt['score']>=50?'text-warning':'text-danger'))?>">
<?=e(rtrim(rtrim(number_format((float)$attempt['score'],2,',','.'),'0'),','))?>%
</strong>
</div>
</a>
<?php endforeach;?>
</div>
</details>
<?php endif;?>
</div>
<?php endforeach;?>
</div><?php endif;?>
</main>
</body>
</html>