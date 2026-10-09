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
$cleanupZeroAttempts=$pdo->prepare("
    DELETE a
    FROM attempts a
    JOIN tests t ON t.id=a.test_id
    WHERE a.student_id=? AND t.topic_id=?
      AND NOT EXISTS (
          SELECT 1 FROM attempt_answers aa
          WHERE aa.attempt_id=a.id
            AND (
                (aa.answer_text IS NOT NULL AND TRIM(aa.answer_text)<>'')
                OR aa.selected_option_id IS NOT NULL
            )
      )
");
$cleanupZeroAttempts->execute([$studentId,$topicId]);

$topicSummaries=$summaryStmt->fetchAll();
usort($topicSummaries,function(array $a,array $b):int{
    return strnatcasecmp((string)$a['name'],(string)$b['name']);
});

$archived=!empty($topic['test_date']) && $topic['test_date'] < date('Y-m-d');

$x=$pdo->prepare("
SELECT
    t.id,t.title,t.description,t.test_type,t.vocab_direction,t.created_at,
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
        SELECT a2.test_id,MAX(a2.id) id
        FROM attempts a2
        WHERE a2.student_id=? AND a2.status='in_progress' AND a2.mode='normal'
          AND EXISTS (
              SELECT 1
              FROM attempt_answers aa2
              WHERE aa2.attempt_id=a2.id
                AND (
                    (aa2.answer_text IS NOT NULL AND TRIM(aa2.answer_text)<>'')
                    OR aa2.selected_option_id IS NOT NULL
                )
          )
        GROUP BY a2.test_id
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
          AND EXISTS (
              SELECT 1 FROM attempt_answers az
              WHERE az.attempt_id=a.id
                AND (
                    (az.answer_text IS NOT NULL AND TRIM(az.answer_text)<>'')
                    OR az.selected_option_id IS NOT NULL
                )
          )
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
                  AND EXISTS (
                      SELECT 1 FROM attempt_answers az
                      WHERE az.attempt_id=a.id
                        AND (
                            (az.answer_text IS NOT NULL AND TRIM(az.answer_text)<>'')
                            OR az.selected_option_id IS NOT NULL
                        )
                  )
                GROUP BY a.test_id
            ) latest ON latest.test_id=t.id
            JOIN attempts latest_attempt ON latest_attempt.id=latest.attempt_id
            JOIN attempt_questions aq ON aq.attempt_id=latest.attempt_id
            JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id
            WHERE t.topic_id=? AND t.is_active=1 AND aa.is_correct=0
              AND NOT EXISTS (
                  SELECT 1
                  FROM attempts ra
                  JOIN tests rt ON rt.id=ra.test_id
                  JOIN attempt_answers raa ON raa.attempt_id=ra.id
                  WHERE ra.student_id=?
                    AND ra.status='finished'
                    AND ra.mode='mistakes'
                    AND rt.topic_id=?
                    AND rt.title='Fouten oefenen'
                    AND raa.question_id=aq.question_id
                    AND raa.is_correct=1
                    AND ra.finished_at>latest_attempt.finished_at
              )
        ");
        $wrongStmt->execute([$studentId,$topicId,$topicId,$studentId,$topicId]);
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
      AND EXISTS (
          SELECT 1 FROM attempt_answers az
          WHERE az.attempt_id=attempts.id
            AND (
                (az.answer_text IS NOT NULL AND TRIM(az.answer_text)<>'')
                OR az.selected_option_id IS NOT NULL
            )
      )
    ORDER BY finished_at DESC,id DESC
");
$historyByTest=[];
$mistakeCountByTest=[];
$completedProgressByTest=[];
foreach($tests as $testRow){
    $historyStmt->execute([(int)$testRow['id'],$studentId]);
    $historyByTest[(int)$testRow['id']]=$historyStmt->fetchAll();
    if($historyByTest[(int)$testRow['id']]){
        $latestAttemptId=(int)$historyByTest[(int)$testRow['id']][0]['id'];
        $wrongCountStmt=$pdo->prepare("SELECT COUNT(*) FROM attempt_answers WHERE attempt_id=? AND is_correct=0");
        $wrongCountStmt->execute([$latestAttemptId]);
        $mistakeCountByTest[(int)$testRow['id']]=(int)$wrongCountStmt->fetchColumn();
        $completedProgressStmt=$pdo->prepare("
            SELECT COUNT(*) total_count,
                   COUNT(CASE WHEN (answer_text IS NOT NULL AND TRIM(answer_text)<>'') OR selected_option_id IS NOT NULL THEN 1 END) answered_count
            FROM attempt_questions aq
            LEFT JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id
            WHERE aq.attempt_id=?
        ");
        $completedProgressStmt->execute([$latestAttemptId]);
        $completedProgressByTest[(int)$testRow['id']]=$completedProgressStmt->fetch() ?: ['total_count'=>0,'answered_count'=>0];
    }else{
        $mistakeCountByTest[(int)$testRow['id']]=0;
    }
}

$labels=['vocabulary'=>'Woordjes oefenen','sentences'=>'Zinnen oefenen','multiple_choice'=>'Multiple choice','open'=>'Open vragen','mixed'=>'Combinatie'];
if(!isset($_SESSION['learner_token']))$_SESSION['learner_token']=bin2hex(random_bytes(32));
$browserToken=$_SESSION['learner_token'];
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($topic['name'])?> - Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

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
<a class="btn btn-success flex-shrink-0" href="quiz.php?review=1&topic_id=<?=(int)$topicId?>&attempt=<?=(int)$reviewInProgressId?>"><?= $reviewInProgressId?'Ga verder':'Start' ?></a>
</div>
</div>
</div>
<?php endif;?>
<?php if(!$tests):?>
<div class="alert alert-info">Er zijn nog geen sub-testen voor deze overhoring.</div>
<?php else:?>
<div class="leren-list">
<?php foreach($tests as $t):?>
<?php
$history=$historyByTest[(int)$t['id']]??[];
$latestScore=$history ? (float)$history[0]['score'] : null;
$isComplete=$latestScore!==null && $latestScore>=100;
if($t['in_progress_attempt_id']){
    $primaryUrl='quiz.php?id='.(int)$t['id'];
    $primaryLabel='Ga verder';
}elseif($latestScore!==null && !empty($history[0]['id'])){
    $primaryUrl='quiz.php?id='.(int)$t['id'].'&view=1&attempt='.(int)$history[0]['id'];
    $primaryLabel='Bekijken';
}else{
    $primaryUrl='quiz.php?id='.(int)$t['id'];
    $primaryLabel='Start';
}
$menuActions=[
    ['label'=>$primaryLabel,'href'=>$primaryUrl,'primary'=>!$t['in_progress_attempt_id'],'class'=>$t['in_progress_attempt_id']?'leren-menu-continue':''],
];
if($t['in_progress_attempt_id'] || $latestScore!==null){
    $menuActions[]=[
        'label'=>'Start opnieuw',
        'href'=>'quiz.php?id='.(int)$t['id'].'&new=1',
        'confirm'=>'U gaat een toets opnieuw start. Uw huidige voortgang gaat hiermee verloren. Weet u het zeker?',
        'class'=>'leren-menu-restart'
    ];
}
if(($mistakeCountByTest[(int)$t['id']]??0)>0){
    $menuActions[]=['label'=>'Alleen fouten ('.(int)$mistakeCountByTest[(int)$t['id']].')','href'=>'quiz.php?id='.(int)$t['id'].'&mode=mistakes'];
}
?>
<div class="leren-list-item<?=$isComplete?' subtest-complete':''?>">
<a class="leren-list-item-main" href="<?=e($primaryUrl)?>">
<div class="leren-list-item-content">
<div class="leren-list-item-heading">
<?php if($isComplete):?><span class="leren-list-item-check" aria-label="100 procent behaald">✓</span><?php endif;?>
<strong class="leren-list-item-title"><?=e($t['title'])?></strong>
</div>
<div class="leren-list-item-subtitle"><?=e($labels[$t['test_type']??'mixed']??'Combinatie')?> · <?=((int)$t['question_count'])?> vragen · Aangemaakt <?=e(date('d-m-Y H:i',strtotime((string)$t['created_at'])))?></div>
<?php if($t['in_progress_attempt_id']):?><div class="leren-list-item-progress-text">Voortgang: <?=((int)$t['in_progress_answered_count'])?> van <?=((int)$t['in_progress_total_count'])?> vragen gedaan.</div><?php elseif($latestScore!==null):?><?php $completedProgress=$completedProgressByTest[(int)$t['id']]??['total_count'=>0,'answered_count'=>0]; ?><div class="leren-list-item-progress-text">Voortgang: <?=((int)$completedProgress['answered_count'])?> van <?=((int)$completedProgress['total_count'])?> vragen gedaan.</div><?php else:?><?php $totalQuestions=(int)$t['question_count']; ?><div class="leren-list-item-progress-text">Voortgang: 0 van <?=$totalQuestions?> vragen gedaan.</div><?php endif;?>
</div>
<div class="leren-list-item-progress">
<?php
if($t['in_progress_attempt_id']){
    $subProgressTotal=max(1,(int)$t['in_progress_total_count']);
    $subProgressDone=min($subProgressTotal,(int)$t['in_progress_answered_count']);
    $subProgressPercent=(int)round($subProgressDone/$subProgressTotal*100);
}elseif($latestScore!==null){
    $subProgressPercent=100;
}else{
    $subProgressPercent=0;
}
?>
<div class="d-flex justify-content-between small text-secondary mb-1"><span><?=$latestScore!==null?'Resultaat':'Voortgang'?></span><strong><?=$latestScore!==null?(int)round($latestScore):$subProgressPercent?>%</strong></div>
<div class="progress" role="progressbar" aria-label="<?=$latestScore!==null?'Resultaat van sub-test':'Voortgang van sub-test'?>" aria-valuenow="<?=$latestScore!==null?(int)round($latestScore):$subProgressPercent?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?= $latestScore!==null ? 'leren-result-bar leren-result-bar-'.(((int)round($latestScore)<=24)?'red':(((int)round($latestScore)<=74)?'orange':'green')) : '' ?>" style="width:<?=$latestScore!==null?(int)round($latestScore):$subProgressPercent?>%"></div></div>
</div>
</a>
<button type="button" class="leren-list-item-menu" data-list-menu data-list-modal="subtestOptionsModal"
 data-menu-title="<?=e($t['title'])?>"
 data-list-actions="<?=e(json_encode($menuActions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>"
 aria-label="Opties voor <?=e($t['title'])?>">
<span></span><span></span><span></span>
</button>
</div>
<?php endforeach;?>
</div>
<?php endif;?>
<?php if($topicSummaries):?>
<section class="mt-4 mb-4">
<div class="d-flex justify-content-between align-items-center mb-3">
<h2 class="h3 mb-0">Samenvattingen</h2>
<span class="small text-secondary"><?=count($topicSummaries)?> beschikbaar</span>
</div>
<div class="leren-list">
<?php foreach($topicSummaries as $summary):?>
<div class="leren-list-item">
<a class="leren-list-item-main" href="summary.php?id=<?=(int)$summary['id']?>">
<div class="leren-list-item-content">
<div class="leren-list-item-heading">
<strong class="leren-list-item-title"><?=e($summary['name'])?></strong>
</div>
<div class="leren-list-item-subtitle">Samenvatting</div>
</div>
</a>
</div>
<?php endforeach;?>
</div>
</section>
<?php endif;?>
<div class="leren-modal" id="subtestOptionsModal" data-list-modal hidden aria-hidden="true">
<div class="leren-modal-backdrop" data-list-modal-close></div>
<div class="leren-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="subtestOptionsTitle">
<button type="button" class="leren-modal-close" data-list-modal-close aria-label="Sluiten">&times;</button>
<h2 id="subtestOptionsTitle" data-list-modal-title>Sub-test</h2>
<div class="leren-modal-actions" data-list-modal-actions></div>
</div>
</div>
</main>
</body>
</html>