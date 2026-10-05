<?php
require __DIR__.'/../app/bootstrap.php';

$subjectId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$subjectId)redirect('index.php');

$s=$pdo->prepare("SELECT id,name,description,image_mime FROM subjects WHERE id=?");
$s->execute([$subjectId]);
$subject=$s->fetch();
if(!$subject){http_response_code(404);exit('Vak niet gevonden.');}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='reactivate_topic'){
    $topicId=filter_var($_POST['topic_id']??null,FILTER_VALIDATE_INT);
    if(!$topicId){http_response_code(400);exit('Ongeldig overhoring.');}
    $x=$pdo->prepare("UPDATE topics SET test_date=NULL WHERE id=? AND subject_id=?");
    $x->execute([$topicId,$subjectId]);
    redirect('subject.php?id='.$subjectId);
}

$x=$pdo->prepare("SELECT id,name,test_date FROM topics WHERE subject_id=? AND is_active=1 ORDER BY
    test_date DESC,
    created_at DESC,
    name");
$x->execute([$subjectId]);
$topics=$x->fetchAll();
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($subject['name'])?> - Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.topic-card{transition:transform .12s ease,box-shadow .12s ease}
.topic-card:hover{transform:translateY(-2px);box-shadow:0 .5rem 1rem rgba(0,0,0,.12)!important}
.topic-archived{filter:grayscale(1);opacity:.58}
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
<a href="index.php">&larr; Alle vakken</a>
<div class="subject-header mt-3">
<?php if($subject['image_mime']):?><img class="subject-header-image" src="subject_image.php?id=<?=(int)$subject['id']?>" alt=""><?php endif;?>
<div class="subject-header-overlay"></div>
<div class="subject-header-content">
<h1><?=e($subject['name'])?></h1>
<?php if($subject['description']):?><p><?=e($subject['description'])?></p><?php endif;?>
</div>
</div>

<h2 class="h3 mt-4 mb-3">Overhoringen</h2>

<?php if(!$topics):?>
<div class="alert alert-info">Er zijn nog geen overhoringen voor dit vak.</div>
<?php else:?>
<div class="list-group shadow-sm">
<?php foreach($topics as $topic):
    $archived=!empty($topic['test_date']) && $topic['test_date'] < date('Y-m-d');
?>
<div class="list-group-item p-0 <?=$archived?'topic-archived':''?>">
<div class="d-flex justify-content-between align-items-center gap-3 p-3">
<div class="min-w-0">
<a class="text-decoration-none text-dark d-block" href="topic.php?id=<?=(int)$topic['id']?>">
<strong class="fs-5"><?=e($topic['name'])?></strong>
<?php if($topic['test_date']):?>
<div class="small text-secondary">Overhoring: <?=e(date('d-m-Y',strtotime($topic['test_date'])))?><?=$archived?' · Gearchiveerd':''?></div>
<?php endif;?>
</a>
</div>
<div class="d-flex align-items-center gap-2">
<?php if($archived):?>
<form method="post" class="m-0">
<input type="hidden" name="action" value="reactivate_topic">
<input type="hidden" name="topic_id" value="<?=$topic['id']?>">
<button class="btn btn-outline-secondary btn-sm" type="submit">Heractiveren</button>
</form>
<?php endif;?>
<a class="btn btn-primary btn-sm" href="topic.php?id=<?=(int)$topic['id']?>">Bekijken</a>
</div>
</div>
</div>
<?php endforeach;?>
</div>
<?php endif;?>
</main>
</body>
</html>