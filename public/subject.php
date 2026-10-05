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

$s=$pdo->prepare("SELECT t.id,t.title,t.description,tp.id topic_id,tp.name topic_name,tp.test_date,COUNT(q.id) question_count
FROM tests t
JOIN topics tp ON tp.id=t.topic_id
LEFT JOIN questions q ON q.test_id=t.id
WHERE tp.subject_id=? AND t.is_active=1
GROUP BY t.id
ORDER BY tp.name,t.title");
$s->execute([$subjectId]);
$tests=$s->fetchAll();

if(!isset($_SESSION['learner_token']))$_SESSION['learner_token']=bin2hex(random_bytes(32));
$browserToken=$_SESSION['learner_token'];

$attemptsByTest=[];
if($tests){
    $testIds=array_map(fn($t)=>(int)$t['id'],$tests);
    $placeholders=implode(',',array_fill(0,count($testIds),'?'));
    $params=array_merge([$browserToken],$testIds);
    $x=$pdo->prepare("SELECT a.id,a.test_id,a.status,a.mode,a.score,a.started_at,
        (SELECT COUNT(*) FROM attempt_questions aq WHERE aq.attempt_id=a.id) AS total_questions,
        (SELECT COUNT(*) FROM attempt_answers aa WHERE aa.attempt_id=a.id) AS answered_questions,
        (SELECT COUNT(*) FROM attempt_answers aa WHERE aa.attempt_id=a.id AND aa.is_correct=0) AS incorrect_questions
        FROM attempts a
        WHERE a.browser_token=? AND a.test_id IN ($placeholders)
        ORDER BY a.started_at DESC,a.id DESC");
    $x->execute($params);

    foreach($x->fetchAll() as $attempt){
        $testId=(int)$attempt['test_id'];
        if(!isset($attemptsByTest[$testId])){
            $attemptsByTest[$testId]=[
                'active'=>null,
                'finished'=>null
            ];
        }

        if($attempt['status']==='in_progress' && $attemptsByTest[$testId]['active']===null){
            $attemptsByTest[$testId]['active']=$attempt;
        }

        if($attempt['status']==='finished' && $attempt['mode']==='normal' && $attemptsByTest[$testId]['finished']===null){
            $attemptsByTest[$testId]['finished']=$attempt;
        }
    }
}
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($subject['name'])?> - Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.topic-archived .topic-heading,.topic-archived .topic-test{filter:grayscale(1);opacity:.58}.topic-heading{display:flex;align-items:center;justify-content:space-between;gap:1rem}.subject-header{position:relative;min-height:220px;border-radius:1rem;overflow:hidden;background:#6c757d}.subject-header-image{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.subject-header-overlay{position:absolute;inset:0;background:linear-gradient(90deg,rgba(0,0,0,.68),rgba(0,0,0,.2))}.subject-header-content{position:relative;z-index:1;min-height:220px;display:flex;flex-direction:column;justify-content:end;padding:2rem;color:#fff}.subject-header-content h1{font-size:clamp(2rem,7vw,3.5rem);margin:0}.subject-header-content p{margin:.35rem 0 0;color:rgba(255,255,255,.8)}</style></head>
<body class="bg-light">
<main class="container py-4">
<a href="index.php">&larr; Alle vakken</a>
<div class="subject-header mt-3"><?php if($subject['image_mime']):?><img class="subject-header-image" src="subject_image.php?id=<?=(int)$subject['id']?>" alt=""><?php endif;?><div class="subject-header-overlay"></div><div class="subject-header-content"><h1><?=e($subject['name'])?></h1><?php if($subject['description']):?><p><?=e($subject['description'])?></p><?php endif;?></div></div>

<div class="row g-3">
<?php
$topic='';
$topicArchived=false;
$topicId=0;
foreach($tests as $t):
    if($topic!==$t['topic_name']):
        $topic=$t['topic_name'];
        $topicId=(int)$t['topic_id'];
        $topicArchived=!empty($t['test_date']) && $t['test_date'] < date('Y-m-d');
?>
<div class="col-12 topic-heading mt-3 <?=$topicArchived?'topic-archived':''?>">
  <div><h2 class="h4 mb-1"><?=e($topic)?></h2><?php if($t['test_date']):?><div class="small text-secondary">Overhoring: <?=e(date('d-m-Y',strtotime($t['test_date'])))?><?=$topicArchived?' · Gearchiveerd':''?></div><?php endif;?></div>
  <?php if($topicArchived):?><form method="post" class="m-0"><input type="hidden" name="action" value="reactivate_topic"><input type="hidden" name="topic_id" value="<?=$topicId?>"><button class="btn btn-outline-secondary btn-sm" type="submit">Heractiveren</button></form><?php endif;?>
</div>
<?php
    endif;

    $testId=(int)$t['id'];
    $state=$attemptsByTest[$testId]??['active'=>null,'finished'=>null];
    $active=$state['active'];
    $finished=$state['finished'];

    if($active){
        $total=(int)$active['total_questions'];
        $answered=(int)$active['answered_questions'];
        $progress=$total>0?min(100,(int)round($answered/$total*100)):0;
        $progressText=$answered.' / '.$total.' vragen';
        $continueUrl='quiz.php?id='.$testId;
        $continueLabel=$active['mode']==='mistakes'?'Verder met fouten oefenen':'Verder met sub-test';
    }elseif($finished){
        $total=(int)$finished['total_questions'];
        $answered=$total;
        $progress=100;
        $progressText=$total.' / '.$total.' vragen';
        $continueUrl='quiz.php?id='.$testId;
        $continueLabel='Opnieuw maken';
    }else{
        $total=(int)$t['question_count'];
        $answered=0;
        $progress=0;
        $progressText='0 / '.$total.' vragen';
        $continueUrl='quiz.php?id='.$testId;
        $continueLabel='Start sub-test';
    }
?>
<div class="col-md-6 col-lg-4 <?=$topicArchived?'topic-archived':''?>">
<div class="card h-100 shadow-sm topic-test">
<div class="card-body">
<h3 class="h5"><?=e($t['title'])?></h3>
<?php if($t['description']):?><p><?=e($t['description'])?></p><?php endif;?>

<div class="d-flex justify-content-between align-items-center small mb-1">
    <span class="text-secondary">Voortgang</span>
    <strong><?=$progress?>%</strong>
</div>
<div class="progress mb-2" role="progressbar" aria-label="Voortgang" aria-valuenow="<?=$progress?>" aria-valuemin="0" aria-valuemax="100" style="height:8px">
    <div class="progress-bar" style="width:<?=$progress?>%"></div>
</div>
<div class="small text-secondary mb-3"><?=$progressText?></div>

<div class="d-grid gap-2">
<?php if($active):?>
    <a class="btn btn-primary" href="<?=$continueUrl?>"><?=e($continueLabel)?></a>
<?php endif;?>

    <a class="btn btn-outline-primary" href="quiz.php?id=<?=$testId?>&new=1">Start sub-test</a>

<?php if($finished):?>
    <a class="btn btn-outline-secondary" href="result.php?id=<?=(int)$finished['id']?>">Resultaat bekijken<?php if($finished['score']!==null):?> (<?=e((string)$finished['score'])?>%)<?php endif;?></a>
<?php endif;?>

<?php if($finished && (int)$finished['incorrect_questions']>0):?>
    <a class="btn btn-warning" href="quiz.php?id=<?=$testId?>&mode=mistakes&source=<?=(int)$finished['id']?>">Alleen fouten oefenen</a>
<?php endif;?>
</div>
</div>
</div>
</div>
<?php endforeach;?>
</div>

<?php if(!$tests):?>
<div class="alert alert-info">Er zijn nog geen actieve sub-testen voor dit vak.</div>
<?php endif;?>
</main>
</body>
</html>