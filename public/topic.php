<?php
require __DIR__.'/../app/bootstrap.php';

$topicId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$topicId)redirect('index.php');

$x=$pdo->prepare("SELECT tp.id,tp.name,tp.test_date,tp.is_active,s.id subject_id,s.name subject_name,s.description subject_description,s.image_mime FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
$x->execute([$topicId]);
$topic=$x->fetch();
if(!$topic || !(int)$topic['is_active']){http_response_code(404);exit('Overhoring niet gevonden.');}

$archived=!empty($topic['test_date']) && $topic['test_date'] < date('Y-m-d');

$x=$pdo->prepare("SELECT t.id,t.title,t.description,t.test_type,t.vocab_direction,COUNT(q.id) question_count
FROM tests t LEFT JOIN questions q ON q.test_id=t.id
WHERE t.topic_id=? AND t.is_active=1
GROUP BY t.id
ORDER BY t.created_at,t.title");
$x->execute([$topicId]);
$tests=$x->fetchAll();

$labels=['vocabulary'=>'Woordjes oefenen','multiple_choice'=>'Multiple choice','mixed'=>'Combinatie'];
if(!isset($_SESSION['learner_token']))$_SESSION['learner_token']=bin2hex(random_bytes(32));
$browserToken=$_SESSION['learner_token'];
$inProgress=[];
$rx=$pdo->prepare("SELECT id FROM attempts WHERE test_id=? AND browser_token=? AND status='in_progress' AND mode='normal' ORDER BY started_at DESC LIMIT 1");
foreach($tests as &$testRow){
    $rx->execute([(int)$testRow['id'],$browserToken]);
    $testRow['in_progress_attempt_id']=$rx->fetchColumn()?:null;
}
unset($testRow);
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

<h2 class="h3 mt-4 mb-3">Sub-Testen</h2>
<?php if(!$tests):?>
<div class="alert alert-info">Er zijn nog geen sub-testen voor deze overhoring.</div>
<?php else:?>
<div class="list-group shadow-sm">
<?php foreach($tests as $t):?>
<div class="list-group-item p-3">
<div class="d-flex justify-content-between align-items-center gap-3">
<div class="min-w-0">
<strong class="fs-5"><?=e($t['title'])?></strong>
<div class="small text-secondary"><?=e($labels[$t['test_type']??'mixed']??'Combinatie')?> · <?=((($t['test_type']??'mixed')==='vocabulary' && ($t['vocab_direction']??'both')==='both') ? (int)ceil(((int)$t['question_count'])/2) : (int)$t['question_count'])?> <?=($t['test_type']??'mixed')==='vocabulary'?'woorden':'vragen'?></div>
<?php if($t['description']):?><div class="text-secondary mt-1"><?=e($t['description'])?></div><?php endif;?>
</div>
<div class="d-flex align-items-center gap-2 flex-shrink-0">
<?php if($t['in_progress_attempt_id']):?>
<a class="btn btn-primary btn-sm" href="quiz.php?id=<?=(int)$t['id']?>">Ga Verder</a>
<a class="btn btn-outline-secondary btn-sm" href="quiz.php?id=<?=(int)$t['id']?>&new=1">Start Opnieuw</a>
<?php else:?>
<a class="btn btn-outline-primary btn-sm" href="quiz.php?id=<?=(int)$t['id']?>">Start</a>
<?php endif;?>
</div>
</div>
</div>
<?php endforeach;?>
</div>
<?php endif;?>
</main>
</body>
</html>