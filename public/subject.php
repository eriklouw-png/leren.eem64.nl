<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';
$currentUser=current_user();
$studentId=$currentUser ? (int)$currentUser['id'] : 0;

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

/* Een volledige overhoring is klaar zodra alle actieve sub-testen
 * minimaal één keer zijn afgerond en hun laatste resultaat 100% is. */
if($studentId && $topics){
    $topicIds=array_map(fn($row)=>(int)$row['id'],$topics);
    $placeholders=implode(',',array_fill(0,count($topicIds),'?'));
    $scoreStmt=$pdo->prepare("
        SELECT t.topic_id,t.id test_id,a.score
        FROM tests t
        JOIN attempts a ON a.test_id=t.id
        JOIN (
            SELECT test_id,MAX(id) attempt_id
            FROM attempts
            WHERE student_id=? AND status='finished' AND mode='normal'
            GROUP BY test_id
        ) latest ON latest.attempt_id=a.id
        WHERE t.topic_id IN ($placeholders) AND t.is_active=1
    ");
    $scoreStmt->execute(array_merge([$studentId],$topicIds));
    $scoresByTopic=[];
    foreach($scoreStmt->fetchAll() as $row){
        $scoresByTopic[(int)$row['topic_id']][]=(float)$row['score'];
    }

    $testCountStmt=$pdo->prepare("SELECT topic_id,COUNT(*) FROM tests WHERE topic_id IN ($placeholders) AND is_active=1 GROUP BY topic_id");
    $testCountStmt->execute($topicIds);
    $testCounts=[];
    foreach($testCountStmt->fetchAll() as $row)$testCounts[(int)$row['topic_id']]=(int)$row['COUNT(*)];

    foreach($topics as &$topic){
        $topicId=(int)$topic['id'];
        $scores=$scoresByTopic[$topicId]??[];
        $topic['is_complete']=isset($testCounts[$topicId]) && count($scores)===$testCounts[$topicId]
            && $testCounts[$topicId]>0
            && !array_filter($scores,fn($score)=>$score<100);
        $topic['progress_total']=$testCounts[$topicId]??0;
        $topic['progress_done']=count($scores);
        /* De voortgang toont het gemiddelde van de laatste scores per sub-test.
         * Zo blijft een overhoring met alle sub-testen gemaakt maar bijvoorbeeld
         * één score van 80% zichtbaar als 95% wanneer de andere drie 100% zijn. */
        $topic['progress_percent']=($topic['progress_done']>0)
            ? (int)round(array_sum($scores)/$topic['progress_done'])
            : 0;
    }
    unset($topic);
}
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($subject['name'])?> - Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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

<section class="leren-section">
<div class="leren-section-title">
<h2>Overhoringen</h2>
</div>

<?php if(!$topics):?>
<div class="leren-empty">Er zijn nog geen overhoringen voor dit vak.</div>
<?php else:?>
<div class="leren-list">
<?php foreach($topics as $topic):
    $archived=!empty($topic['test_date']) && $topic['test_date'] < date('Y-m-d');
    $topicUrl='topic.php?id='.(int)$topic['id'];
    $menuActions=[
        ['label'=>'Bekijken','href'=>$topicUrl,'primary'=>true],
    ];
    if($archived){
        $menuActions[]=[
            'type'=>'form',
            'label'=>'Heractiveren',
            'action'=>'subject.php?id='.(int)$subjectId,
            'fields'=>[
                'action'=>'reactivate_topic',
                'topic_id'=>(int)$topic['id'],
            ],
        ];
    }
?>
<div class="leren-list-item<?=$archived?' topic-archived':''?><?=$topic['is_complete']??false?' subtest-complete':''?>">
<a class="leren-list-item-main" href="<?=e($topicUrl)?>">
<div class="leren-list-item-content">
<div class="leren-list-item-heading">
<?php if(!empty($topic['is_complete'])):?><span class="leren-list-item-check" aria-label="100 procent behaald">✓</span><?php endif;?>
<strong class="leren-list-item-title"><?=e($topic['name'])?></strong>
</div>
<div class="leren-list-item-subtitle">
<?php if($topic['test_date']):?>
Overhoring: <?=e(date('d-m-Y',strtotime($topic['test_date'])))?><?=$archived?' · Gearchiveerd':''?>
<?php else:?>
Overhoring
<?php endif;?>
</div>
<?php if((int)($topic['progress_total']??0)>0):?>
<div class="leren-list-item-progress">
<div class="d-flex justify-content-between small text-secondary mb-1"><span>Voortgang</span><strong><?=$topic['progress_percent']?>%</strong></div>
<div class="progress" role="progressbar" aria-label="Voortgang van overhoring" aria-valuenow="<?=$topic['progress_percent']?>" aria-valuemin="0" aria-valuemax="100">
<div class="progress-bar" style="width:<?=$topic['progress_percent']?>%"></div>
</div>
<div class="small text-secondary mt-1"><?=$topic['progress_done']?> van <?=$topic['progress_total']?> sub-testen afgerond</div>
</div>
<?php endif;?>
</div>
</a>
<button type="button" class="leren-list-item-menu" data-list-menu data-list-modal="topicOptionsModal"
 data-menu-title="<?=e($topic['name'])?>"
 data-list-actions="<?=e(json_encode($menuActions,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>"
 aria-label="Opties voor <?=e($topic['name'])?>">
<span></span><span></span><span></span>
</button>
</div>
<?php endforeach;?>
</div>
<?php endif;?>
</section>

<div class="leren-modal" id="topicOptionsModal" data-list-modal hidden aria-hidden="true">
<div class="leren-modal-backdrop" data-list-modal-close></div>
<div class="leren-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="topicOptionsTitle">
<button type="button" class="leren-modal-close" data-list-modal-close aria-label="Sluiten">&times;</button>
<h2 id="topicOptionsTitle" data-list-modal-title>Overhoring</h2>
<div class="leren-modal-actions" data-list-modal-actions></div>
</div>
</div>
</main>
</body>
</html>