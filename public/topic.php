<?php
require __DIR__.'/../app/bootstrap.php';

$topicId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$topicId)redirect('index.php');

$x=$pdo->prepare("SELECT tp.id,tp.name,tp.test_date,tp.is_active,s.id subject_id,s.name subject_name,s.description subject_description FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
$x->execute([$topicId]);
$topic=$x->fetch();
if(!$topic || !(int)$topic['is_active']){http_response_code(404);exit('Overhoring niet gevonden.');}

$archived=!empty($topic['test_date']) && $topic['test_date'] < date('Y-m-d');

$x=$pdo->prepare("SELECT t.id,t.title,t.description,t.test_type,COUNT(q.id) question_count
FROM tests t LEFT JOIN questions q ON q.test_id=t.id
WHERE t.topic_id=? AND t.is_active=1
GROUP BY t.id
ORDER BY t.created_at,t.title");
$x->execute([$topicId]);
$tests=$x->fetchAll();

$labels=['vocabulary'=>'Woordjes oefenen','multiple_choice'=>'Multiple choice','mixed'=>'Combinatie'];
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($topic['name'])?> - Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-4" style="max-width:1000px">
<a href="subject.php?id=<?=(int)$topic['subject_id']?>">&larr; Terug naar <?=e($topic['subject_name'])?></a>
<div class="d-flex justify-content-between align-items-start gap-3 mt-3">
<div>
<h1 class="mb-1"><?=e($topic['name'])?></h1>
<?php if($topic['test_date']):?><div class="text-secondary">Overhoring: <?=e(date('d-m-Y',strtotime($topic['test_date'])))?><?=$archived?' · Gearchiveerd':''?></div><?php endif;?>
</div>
</div>

<h2 class="h4 mt-4 mb-3">Sub-Testen</h2>
<?php if(!$tests):?>
<div class="alert alert-info">Er zijn nog geen sub-testen voor deze overhoring.</div>
<?php else:?>
<div class="list-group shadow-sm">
<?php foreach($tests as $t):?>
<a class="list-group-item list-group-item-action p-3 text-decoration-none" href="quiz.php?id=<?=(int)$t['id']?>">
<div class="d-flex justify-content-between align-items-center gap-3">
<div>
<strong class="fs-5"><?=e($t['title'])?></strong>
<div class="small text-secondary"><?=e($labels[$t['test_type']??'mixed']??'Combinatie')?> · <?=e((string)$t['question_count'])?> vragen</div>
<?php if($t['description']):?><div class="text-secondary mt-1"><?=e($t['description'])?></div><?php endif;?>
</div>
<span class="btn btn-outline-primary btn-sm">Start</span>
</div>
</a>
<?php endforeach;?>
</div>
<?php endif;?>
</main>
</body>
</html>