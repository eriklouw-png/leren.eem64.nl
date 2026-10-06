<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';
require_login();
$currentUser=current_user();
$studentId=(int)$currentUser['id'];

$topicId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$topicId)redirect('index.php');

$x=$pdo->prepare("SELECT tp.id,tp.name,tp.test_date,tp.is_active,tp.use_summary,tp.summary,tp.summary_updated_at,s.id subject_id,s.name subject_name,s.description subject_description,s.image_mime FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
$x->execute([$topicId]);
$topic=$x->fetch();
if(!$topic || !(int)$topic['is_active']){http_response_code(404);exit('Overhoring niet gevonden.');}

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
ORDER BY t.created_at,t.title
");
$x->execute([$studentId,$topicId]);
$tests=$x->fetchAll();

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

<?php if(!empty($topic['use_summary']) && trim((string)($topic['summary']??''))!==''):?>
<div class="card shadow-sm mt-4 border-0">
<div class="card-body p-4">
<div class="d-flex justify-content-between align-items-start gap-3">
<div><h2 class="h3 mb-1">Samenvatting</h2><div class="small text-secondary">Gebaseerd op de geüploade boekpagina’s voor deze overhoring.</div></div>
<?php if(!empty($topic['summary_updated_at'])):?><span class="small text-secondary text-nowrap">Bijgewerkt <?=e(date('d-m-Y',strtotime((string)$topic['summary_updated_at'])))?></span><?php endif;?>
</div>
<div class="mt-3 lh-lg"><?=nl2br(e((string)$topic['summary']))?></div>
</div>
</div>
<?php endif;?>

<h2 class="h3 mt-4 mb-3">Sub-Testen</h2>
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