<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';
require_login();

$currentUser=current_user();
$studentId=(int)$currentUser['id'];

$reviewMode=isset($_GET['review']) && $_GET['review']==='1';
$topicId=filter_input(INPUT_GET,'topic_id',FILTER_VALIDATE_INT);
$testId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);

if($reviewMode){
    if(!$topicId)redirect('index.php');
    $reviewTestName='Fouten oefenen';
    $findReview=$pdo->prepare("SELECT id FROM tests WHERE topic_id=? AND title=? LIMIT 1");
    $findReview->execute([$topicId,$reviewTestName]);
    $reviewTestId=(int)($findReview->fetchColumn()?:0);
    if(!$reviewTestId){
        $createReview=$pdo->prepare("INSERT INTO tests(topic_id,title,description,test_type,is_active) VALUES(?,?,?,?,0)");
        $createReview->execute([$topicId,$reviewTestName,'Gezamenlijke oefentoets met fouten uit de sub-testen.','mixed']);
        $reviewTestId=(int)$pdo->lastInsertId();
    }
    $testId=$reviewTestId;
}

if(!$testId)redirect('index.php');

$s=$pdo->prepare("SELECT t.id,t.title,t.description,t.test_type,t.vocab_left_label,t.vocab_right_label,t.vocab_direction,s.id subject_id,s.name subject_name,tp.id topic_id,tp.name topic_name FROM tests t JOIN topics tp ON tp.id=t.topic_id JOIN subjects s ON s.id=tp.subject_id WHERE t.id=? AND (t.is_active=1 OR ?=1)");
$s->execute([$testId,$reviewMode?1:0]);$test=$s->fetch();
if(!$test){http_response_code(404);exit('Sub-Test niet gevonden.');}
if($reviewMode && (int)$test['topic_id']!==$topicId){http_response_code(404);exit('Overhoring niet gevonden.');}

if(!isset($_SESSION['learner_token']))$_SESSION['learner_token']=bin2hex(random_bytes(32));
$browserToken=$_SESSION['learner_token'];
$mode=$reviewMode?'mistakes':($_GET['mode']??'normal');
if(!in_array($mode,['normal','mistakes'],true))$mode='normal';
$sourceAttemptId=filter_input(INPUT_GET,'source',FILTER_VALIDATE_INT)?:null;
$newAttempt=isset($_GET['new'])&&$_GET['new']==='1';
$viewMode=isset($_GET['view'])&&$_GET['view']==='1';
$resumeAttemptId=filter_input(INPUT_GET,'attempt',FILTER_VALIDATE_INT)?:0;
if($viewMode && !$reviewMode && $resumeAttemptId){
    $view=$pdo->prepare("SELECT id FROM attempts WHERE id=? AND test_id=? AND student_id=? AND status='finished' AND mode='normal' LIMIT 1");
    $view->execute([$resumeAttemptId,$testId,$studentId]);
    if($view->fetch()){
        $attempt=['id'=>$resumeAttemptId];
    }else{
        redirect('topic.php?id='.(int)$test['topic_id']);
    }
}
if(!$viewMode && !$newAttempt && $reviewMode && $resumeAttemptId){
    $resume=$pdo->prepare("SELECT id FROM attempts WHERE id=? AND test_id=? AND student_id=? AND status='in_progress' AND mode='mistakes' LIMIT 1");
    $resume->execute([$resumeAttemptId,$testId,$studentId]);
    if($resume->fetch()){
        $attempt=['id'=>$resumeAttemptId];
    }else{
        $resumeAttemptId=0;
    }
}
if(!$viewMode && !$newAttempt && $mode==='normal'){
    $resume=$pdo->prepare("SELECT id FROM attempts WHERE test_id=? AND student_id=? AND status='in_progress' AND mode='normal' ORDER BY started_at DESC LIMIT 1");
    $resume->execute([$testId,$studentId]);
    if(!$resume->fetch())$newAttempt=true;
}
$vocabDirectionChoice=$_POST['vocab_direction']??($_GET['direction']??null);
if(in_array(($test['test_type']??'mixed'),['vocabulary','sentences'],true) && $newAttempt && !$viewMode && $_SERVER['REQUEST_METHOD']==='GET' && !$vocabDirectionChoice){
    $allowed=$test['vocab_direction']??'both';
    ?>
    <!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($test['title'])?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
    <body class="bg-light"><main class="container py-4">
    <a href="subject.php?id=<?=(int)$test['subject_id']?>">&larr; Terug</a>
    <div class="card shadow-sm mt-4"><div class="card-body p-4">
    <h1 class="h3"><?=e($test['title'])?></h1>
    <p class="text-secondary">Kies eerst welke richting je wilt oefenen.</p>
    <div class="d-grid gap-3 mt-4">
    <?php if(in_array($allowed,['both','left_to_right'],true)):?>
    <form method="post"><input type="hidden" name="vocab_direction" value="left_to_right"><button class="btn btn-primary btn-lg w-100" type="submit"><?=e($test['vocab_left_label'])?> → <?=e($test['vocab_right_label'])?></button></form>
    <?php endif;?>
    <?php if(in_array($allowed,['both','right_to_left'],true)):?>
    <form method="post"><input type="hidden" name="vocab_direction" value="right_to_left"><button class="btn btn-outline-primary btn-lg w-100" type="submit"><?=e($test['vocab_right_label'])?> → <?=e($test['vocab_left_label'])?></button></form>
    <?php endif;?>
    <?php if($allowed==='both'):?>
    <form method="post"><input type="hidden" name="vocab_direction" value="both"><button class="btn btn-outline-secondary btn-lg w-100" type="submit">Beide richtingen</button></form>
    <?php endif;?>
    </div></div></div></main></body></html>
    <?php
    exit;
}
if(in_array(($test['test_type']??'mixed'),['vocabulary','sentences'],true) && $newAttempt && $vocabDirectionChoice){
    $allowed=$test['vocab_direction']??'both';
    if(!in_array($vocabDirectionChoice,['both','left_to_right','right_to_left'],true) || ($allowed!=='both' && $vocabDirectionChoice!==$allowed)){
        http_response_code(400);exit('Ongeldige oefenrichting.');
    }
}

if(!$viewMode && $_SERVER['REQUEST_METHOD']==='POST' && in_array(($_POST['action']??''),['save_answer','save_draft'],true)){
    header('Content-Type: application/json; charset=utf-8');
    $attemptId=filter_var($_POST['attempt_id']??null,FILTER_VALIDATE_INT);
    $questionId=filter_var($_POST['question_id']??null,FILTER_VALIDATE_INT);
    if(!$attemptId||!$questionId){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'missing_attempt_or_question']);exit;}
    $a=$pdo->prepare("SELECT id FROM attempts WHERE id=? AND test_id=? AND student_id=? AND status='in_progress'");
    $a->execute([$attemptId,$testId,$studentId]);
    if(!$a->fetch()){http_response_code(403);echo json_encode(['ok'=>false]);exit;}
    $q=$pdo->prepare($reviewMode
        ? "SELECT q.question_text,q.question_type,q.explanation FROM questions q JOIN attempt_questions aq ON aq.question_id=q.id AND aq.attempt_id=? WHERE q.id=?"
        : "SELECT q.question_text,q.question_type,q.explanation FROM questions q JOIN attempt_questions aq ON aq.question_id=q.id AND aq.attempt_id=? WHERE q.id=? AND q.test_id=?"
    );
    if($reviewMode)$q->execute([$attemptId,$questionId]);
    else $q->execute([$attemptId,$questionId,$testId]);
    $question=$q->fetch();
    if(!$question){http_response_code(400);echo json_encode(['ok'=>false]);exit;}
    $raw=$_POST['answer']??'';$selected=null;$answerText=null;$ok=null;
    $feedback=[];
    if(($_POST['action']??'')==='save_draft'){
        $answerText=trim((string)$raw);
        if($question['question_type']==='multiple_choice'){
            $selected=filter_var($raw,FILTER_VALIDATE_INT);
            if($selected===false||$selected===null){
                echo json_encode(['ok'=>true,'answered'=>false]);exit;
            }
            $x=$pdo->prepare("SELECT id FROM question_options WHERE id=? AND question_id=?");
            $x->execute([$selected,$questionId]);
            if(!$x->fetch()){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'invalid_option']);exit;}
            $answerText=null;
        }else{
            if($answerText===''){echo json_encode(['ok'=>true,'answered'=>false]);exit;}
        }
        try{
            $x=$pdo->prepare("INSERT INTO attempt_answers(attempt_id,question_id,selected_option_id,answer_text,is_correct,answered_at) VALUES(?,?,?,?,NULL,NOW()) ON DUPLICATE KEY UPDATE selected_option_id=VALUES(selected_option_id),answer_text=VALUES(answer_text),is_correct=attempt_answers.is_correct,answered_at=NOW()");
            $x->execute([$attemptId,$questionId,$selected,$answerText]);
            echo json_encode(['ok'=>true,'answered'=>true]);exit;
        }catch(Throwable $e){
            http_response_code(500);echo json_encode(['ok'=>false,'error'=>'save_failed']);exit;
        }
    }
    if($question['question_type']==='open'){
        $answerText=trim((string)$raw);
        if($answerText===''){echo json_encode(['ok'=>true,'answered'=>false]);exit;}
        $x=$pdo->prepare("SELECT answer_text FROM open_question_answers WHERE question_id=? ORDER BY sort_order,id");
        $x->execute([$questionId]);$correctAnswers=$x->fetchAll(PDO::FETCH_COLUMN);
        $exact=open_answer_matches($answerText,$correctAnswers);
        if($exact){
            $ok=1;
            $aiReason='';
            $aiUsed=false;
            $aiAvailable=true;
            $aiModel='';
        }else{
            $ai=ai_grade_open_answer($question['question_text']??'',implode(' | ',$correctAnswers),$answerText);
            $ok=$ai['correct']?1:0;
            $aiReason=$ai['reason'];
            $aiUsed=(bool)($ai['available']??false);
            $aiAvailable=$aiUsed;
            $aiModel=(string)($ai['model']??'');
        }
        $feedback=[
            'correct_answers'=>$correctAnswers,
            'explanation'=>$question['explanation']??'',
            'ai_reason'=>$aiReason,
            'ai_used'=>$aiUsed,
            'ai_available'=>$aiAvailable,
            'ai_model'=>$aiModel
        ];
    }else{
        $selected=filter_var($raw,FILTER_VALIDATE_INT);
        if($selected!==false&&$selected!==null){
            $x=$pdo->prepare("SELECT option_text,is_correct FROM question_options WHERE id=? AND question_id=?");
            $x->execute([$selected,$questionId]);$o=$x->fetch();
            if(!$o)$selected=null;else$ok=(int)$o['is_correct'];
        }
        if($selected===null){echo json_encode(['ok'=>true,'answered'=>false]);exit;}
        $x=$pdo->prepare("SELECT option_text FROM question_options WHERE question_id=? AND is_correct=1 ORDER BY sort_order,id");
        $x->execute([$questionId]);$correctOptions=$x->fetchAll(PDO::FETCH_COLUMN);
        $feedback=[
            'correct_answers'=>$correctOptions,
            'explanation'=>$question['explanation']??''
        ];
    }
    try{
        $x=$pdo->prepare("INSERT INTO attempt_answers(attempt_id,question_id,selected_option_id,answer_text,is_correct,answered_at) VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE selected_option_id=VALUES(selected_option_id),answer_text=VALUES(answer_text),is_correct=VALUES(is_correct),answered_at=NOW()");
        $x->execute([$attemptId,$questionId,$selected,$answerText,$ok]);
        echo json_encode(['ok'=>true,'answered'=>true,'is_correct'=>(bool)$ok,'feedback'=>$feedback]);exit;
    }catch(Throwable $e){
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'save_failed','message'=>$e->getMessage()]);exit;
    }
}

$attempt=$attempt??null;
if(!$attempt && !$viewMode && (!$newAttempt && ($mode==='normal' || $mode==='mistakes' || $reviewMode))){
    $resumeMode=$reviewMode?'mistakes':$mode;
    if($reviewMode){
        $x=$pdo->prepare("SELECT a.*, (SELECT COUNT(*) FROM attempt_answers aa WHERE aa.attempt_id=a.id AND ((aa.answer_text IS NOT NULL AND TRIM(aa.answer_text)<>'') OR aa.selected_option_id IS NOT NULL)) AS answered_count FROM attempts a WHERE a.test_id=? AND a.student_id=? AND a.status='in_progress' AND a.mode=? AND (SELECT COUNT(*) FROM attempt_answers aa0 WHERE aa0.attempt_id=a.id AND ((aa0.answer_text IS NOT NULL AND TRIM(aa0.answer_text)<>'') OR aa0.selected_option_id IS NOT NULL)) > 0 ORDER BY answered_count DESC, a.id DESC LIMIT 1");
    }else{
        $x=$pdo->prepare("SELECT a.*
        FROM attempts a
        WHERE a.test_id=? AND a.student_id=? AND a.status='in_progress' AND a.mode=?
          AND EXISTS (
              SELECT 1 FROM attempt_answers az
              WHERE az.attempt_id=a.id
                AND (
                    (az.answer_text IS NOT NULL AND TRIM(az.answer_text)<>'')
                    OR az.selected_option_id IS NOT NULL
                )
          )
        ORDER BY a.started_at DESC LIMIT 1");
    }
    $x->execute([$testId,$studentId,$resumeMode]);$attempt=$x->fetch();
}

if(!$attempt && !$viewMode){
    $mistakes=[];
    if($mode==='mistakes'){
        if($reviewMode){
            $x=$pdo->prepare("
                SELECT aq.question_id AS id,aq.sort_order
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
                ORDER BY t.id,aq.sort_order,aq.question_id
            ");
            $x->execute([$studentId,$topicId,$topicId,$studentId,$topicId]);$mistakes=$x->fetchAll();
            if(!$mistakes)redirect('topic.php?id='.$topicId);
        }else{
            if($sourceAttemptId){
                $x=$pdo->prepare("SELECT a.id
                FROM attempts a
                WHERE a.id=? AND a.test_id=? AND a.student_id=? AND a.status='finished' AND a.mode='normal'
                  AND EXISTS (
                      SELECT 1 FROM attempt_answers az
                      WHERE az.attempt_id=a.id
                        AND (
                            (az.answer_text IS NOT NULL AND TRIM(az.answer_text)<>'')
                            OR az.selected_option_id IS NOT NULL
                        )
                  )");
                $x->execute([$sourceAttemptId,$testId,$studentId]);
                $validatedAttempt=$x->fetchColumn();
                if(!$validatedAttempt){$sourceAttemptId=null;}
            }
            if(!$sourceAttemptId){
                $x=$pdo->prepare("SELECT a.id
                FROM attempts a
                WHERE a.test_id=? AND a.student_id=? AND a.status='finished' AND a.mode='normal'
                  AND EXISTS (
                      SELECT 1 FROM attempt_answers az
                      WHERE az.attempt_id=a.id
                        AND (
                            (az.answer_text IS NOT NULL AND TRIM(az.answer_text)<>'')
                            OR az.selected_option_id IS NOT NULL
                        )
                  )
                ORDER BY a.finished_at DESC,a.id DESC LIMIT 1");
                $x->execute([$testId,$studentId]);
                $sourceAttemptId=(int)($x->fetchColumn()?:0);
            }
            if(!$sourceAttemptId)redirect('topic.php?id='.(int)$test['topic_id']);
            $x=$pdo->prepare("SELECT q.id,q.sort_order FROM attempt_questions aq JOIN questions q ON q.id=aq.question_id JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id WHERE aq.attempt_id=? AND aa.is_correct=0 ORDER BY aq.sort_order,q.id");
            $x->execute([$sourceAttemptId]);$mistakes=$x->fetchAll();
            if(!$mistakes)redirect('result.php?id='.$sourceAttemptId.'&done=1');
        }
    }
    if($mode==='normal'){
        if(in_array(($test['test_type']??'mixed'),['vocabulary','sentences'],true) && $vocabDirectionChoice!=='both'){
            $directionTo=$vocabDirectionChoice==='left_to_right'
                ? (string)$test['vocab_right_label']
                : (string)$test['vocab_left_label'];
            $directionExplanation='Vertaal naar '.$directionTo.'.';
            $emptyCheck=$pdo->prepare("SELECT COUNT(*) FROM questions WHERE test_id=? AND explanation=?");
            $emptyCheck->execute([$testId,$directionExplanation]);
            $directionCount=(int)$emptyCheck->fetchColumn();

            /*
             * Oudere door de AI aangemaakte woordenlijsten hadden nog een algemene
             * uitleg ("Vertaal het woord naar de andere taal.") zonder richting.
             * Bij die toetsen zijn de twee richtingen per woordpaar opgeslagen:
             * oneven sort_order = eerste richting, even = omgekeerde richting.
             */
            if($directionCount===0){
                $parity= $vocabDirectionChoice==='left_to_right' ? 1 : 0;
                $emptyCheck=$pdo->prepare("SELECT COUNT(*) FROM questions WHERE test_id=? AND MOD(sort_order,2)=?");
                $emptyCheck->execute([$testId,$parity]);
            }
        }else{
            $emptyCheck=$pdo->prepare("SELECT COUNT(*) FROM questions WHERE test_id=?");
            $emptyCheck->execute([$testId]);
        }
        if((int)$emptyCheck->fetchColumn()===0){
            redirect('topic.php?id='.(int)$test['topic_id']);
        }
    }

    $pdo->beginTransaction();
    try{
        $x=$pdo->prepare("INSERT INTO attempts(test_id,student_id,status,mode,source_attempt_id,browser_token) VALUES(?,?,'in_progress',?,?,?)");
        $x->execute([$testId,$studentId,$mode,$mode==='mistakes'?$sourceAttemptId:null,$browserToken]);
        $attemptId=(int)$pdo->lastInsertId();
        if($mode==='mistakes'){
            $y=$pdo->prepare("INSERT INTO attempt_questions(attempt_id,question_id,sort_order) VALUES(?,?,?)");
            foreach($mistakes as $i=>$q)$y->execute([$attemptId,$q['id'],$i+1]);
        }else{
            if(in_array(($test['test_type']??'mixed'),['vocabulary','sentences'],true)){
                if($vocabDirectionChoice==='left_to_right' || $vocabDirectionChoice==='right_to_left'){
                    $directionTo=$vocabDirectionChoice==='left_to_right'
                        ? (string)$test['vocab_right_label']
                        : (string)$test['vocab_left_label'];
                    $directionExplanation='Vertaal naar '.$directionTo.'.';
                    $y=$pdo->prepare("SELECT id FROM questions WHERE test_id=? AND explanation=? ORDER BY RAND()");
                    $y->execute([$testId,$directionExplanation]);
                    $questionIds=$y->fetchAll(PDO::FETCH_COLUMN);

                    // Compatibiliteit met oudere AI-woordenlijsten zonder richtingsuitleg.
                    if(!$questionIds){
                        $parity=$vocabDirectionChoice==='left_to_right' ? 1 : 0;
                        $y=$pdo->prepare("SELECT id FROM questions WHERE test_id=? AND MOD(sort_order,2)=? ORDER BY RAND()");
                        $y->execute([$testId,$parity]);
                        $questionIds=$y->fetchAll(PDO::FETCH_COLUMN);
                    }
                }else{
                    $y=$pdo->prepare("SELECT id FROM questions WHERE test_id=? ORDER BY RAND()");
                    $y->execute([$testId]);
                    $questionIds=$y->fetchAll(PDO::FETCH_COLUMN);
                }
                $ins=$pdo->prepare("INSERT INTO attempt_questions(attempt_id,question_id,sort_order) VALUES(?,?,?)");
                foreach($questionIds as $i=>$questionId)$ins->execute([$attemptId,(int)$questionId,$i+1]);
            }else{
                $questionOrder=((int)($test['shuffle_questions']??1)===1) ? 'ORDER BY RAND()' : 'ORDER BY sort_order,id';
                $y=$pdo->prepare("INSERT INTO attempt_questions(attempt_id,question_id,sort_order) SELECT ?,id,ROW_NUMBER() OVER () FROM questions WHERE test_id=? ".$questionOrder);
                $y->execute([$attemptId,$testId]);
            }
        }
        $pdo->commit();
        $attempt=['id'=>$attemptId];
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
$attemptId=(int)$attempt['id'];
if($mode==='mistakes' && !$reviewMode){
    $sourceAttemptId=(int)($attempt['source_attempt_id']??$sourceAttemptId??0);
}

if(!$viewMode && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='finish'){
    $x=$pdo->prepare("SELECT COUNT(*) total,
        SUM(CASE WHEN aa.id IS NOT NULL
              AND ((aa.answer_text IS NOT NULL AND TRIM(aa.answer_text)<>'') OR aa.selected_option_id IS NOT NULL)
            THEN 1 ELSE 0 END) answered,
        SUM(CASE WHEN aa.is_correct=1 THEN 1 ELSE 0 END) correct
        FROM attempt_questions aq
        LEFT JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id
        WHERE aq.attempt_id=?");
    $x->execute([$attemptId]);$stats=$x->fetch();
    $total=(int)$stats['total'];
    $answered=(int)$stats['answered'];
    $correct=(int)$stats['correct'];
    if($total===0 || $answered===0){
        $deleteEmpty=$pdo->prepare("DELETE FROM attempts WHERE id=? AND student_id=? AND status='in_progress'");
        $deleteEmpty->execute([$attemptId,$studentId]);
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'empty'=>true,'redirect'=>'topic.php?id='.(int)$test['topic_id']]);
        exit;
    }
    $score=round($correct/$total*100,2);
    $x=$pdo->prepare("UPDATE attempts SET status='finished',score=?,finished_at=NOW() WHERE id=? AND status='in_progress'");
    $x->execute([$score,$attemptId]);

    if($reviewMode || $mode==='mistakes'){
        /* Een fout die in Alleen fouten of Fouten oefenen goed wordt gemaakt,
         * telt voortaan als goed op de oorspronkelijke gewone poging. */
        $correctQuestions=$pdo->prepare("
            SELECT aq.question_id
            FROM attempt_questions aq
            JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id
            WHERE aq.attempt_id=? AND aa.is_correct=1
        ");
        $correctQuestions->execute([$attemptId]);
        $markCorrect=$correctQuestions->fetchAll(PDO::FETCH_COLUMN);
        foreach($markCorrect as $questionId){
            $findOriginal=$pdo->prepare("
                SELECT a.id
                FROM attempts a
                JOIN tests t ON t.id=a.test_id
                JOIN attempt_questions aq ON aq.attempt_id=a.id AND aq.question_id=?
                JOIN attempt_answers aa ON aa.attempt_id=a.id AND aa.question_id=aq.question_id
                WHERE a.student_id=? AND a.status='finished' AND a.mode='normal'
                  AND t.topic_id=? AND aa.is_correct=0
                ORDER BY a.id DESC
                LIMIT 1
            ");
            if($mode==='mistakes' && !$reviewMode && $sourceAttemptId){
                $findOriginal=$pdo->prepare("
                    SELECT a.id
                    FROM attempts a
                    JOIN attempt_questions aq ON aq.attempt_id=a.id AND aq.question_id=?
                    JOIN attempt_answers aa ON aa.attempt_id=a.id AND aa.question_id=aq.question_id
                    WHERE a.id=? AND a.student_id=? AND a.status='finished' AND a.mode='normal' AND aa.is_correct=0
                    LIMIT 1
                ");
                $findOriginal->execute([(int)$questionId,$sourceAttemptId,$studentId]);
            }else{
                $findOriginal->execute([(int)$questionId,$studentId,$topicId]);
            }
            $originalAttemptId=(int)($findOriginal->fetchColumn()?:0);
            if($originalAttemptId){
                $fix=$pdo->prepare("UPDATE attempt_answers SET is_correct=1 WHERE attempt_id=? AND question_id=?");
                $fix->execute([$originalAttemptId,(int)$questionId]);

                $scoreStmt=$pdo->prepare("
                    SELECT COUNT(*) total,
                           SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) correct
                    FROM attempt_answers
                    WHERE attempt_id=?
                ");
                $scoreStmt->execute([$originalAttemptId]);
                $scoreData=$scoreStmt->fetch();
                $newScore=((int)$scoreData['total'])>0
                    ?round(((int)$scoreData['correct']/(int)$scoreData['total'])*100,2):0;
                $pdo->prepare("UPDATE attempts SET score=? WHERE id=?")->execute([$newScore,$originalAttemptId]);
            }
        }
    }

    unset($_SESSION['current_attempt_id']);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'finished'=>true,'attempt_id'=>$attemptId,'redirect'=>'result.php?id='.$attemptId]);
    exit;
}

$x=$pdo->prepare("SELECT q.id,q.question_text,q.image_path,q.question_type,q.explanation,aq.sort_order,aa.selected_option_id,aa.answer_text,aa.is_correct FROM attempt_questions aq JOIN questions q ON q.id=aq.question_id LEFT JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id WHERE aq.attempt_id=? ORDER BY aq.sort_order,q.id");

$x->execute([$attemptId]);$questions=$x->fetchAll();
$resumeIndex=0;
if(!$viewMode){
foreach($questions as $questionIndex=>$resumeQuestion){
    $hasAnswer=($resumeQuestion['selected_option_id']!==null && $resumeQuestion['selected_option_id']!=='')
        || ($resumeQuestion['answer_text']!==null && trim((string)$resumeQuestion['answer_text'])!=='');
    if(!$hasAnswer){$resumeIndex=$questionIndex;break;}
    $resumeIndex=$questionIndex+1;
}
}
if($viewMode)$resumeIndex=0;
if($resumeIndex>=count($questions) && count($questions)>0)$resumeIndex=count($questions)-1;
$o=$pdo->prepare("SELECT id,option_text FROM question_options WHERE question_id=? ORDER BY sort_order,id");
foreach($questions as &$q){
    $q['options']=[];
    if($q['question_type']==='multiple_choice'){
        $o->execute([$q['id']]);
        $q['options']=$o->fetchAll();
        shuffle($q['options']);
    }
}
unset($q);
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($test['title'])?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-4"><a href="topic.php?id=<?=$test['topic_id']?>">&larr; Terug naar <?=e($test['topic_name'])?></a><h1 class="mt-3"><?=e($test['title'])?></h1><div class="mb-3"><?php $typeLabels=['vocabulary'=>'Woordjes oefenen','sentences'=>'Zinnen oefenen','multiple_choice'=>'Alleen multiple choice','mixed'=>'Combinatie'];?><span class="badge text-bg-secondary"><?=e($typeLabels[$test['test_type']??'mixed']??'Combinatie')?></span><?php if(in_array(($test['test_type']??'mixed'),['vocabulary','sentences'],true) && $vocabDirectionChoice):?> <span class="badge text-bg-primary"><?=e($test['vocab_left_label'])?> → <?=e($test['vocab_right_label'])?><?php if($vocabDirectionChoice==='right_to_left'):?> omgekeerd<?php endif;?></span><?php endif;?></div><div class="progress mb-4" style="height:8px"><div id="progressBar" class="progress-bar" style="width:<?=count($questions)?100/count($questions):0?>%"></div></div><form method="post" id="quizForm"><input type="hidden" name="action" value="finish"><?php foreach($questions as $n=>$q):?><section class="question-card <?=$n===0?'':'d-none'?>" data-index="<?=$n?>" data-question-id="<?=$q['id']?>"><div class="card shadow-sm mb-4"><div class="card-body"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div class="text-secondary question-counter">Vraag <?=$n+1?> van <?=count($questions)?></div><button type="button" class="btn btn-primary next-btn quiz-next-top"><?=$viewMode ? ($n===count($questions)-1?'Klaar':'Volgende') : 'Check'?></button></div><h2 class="h5"><?=e($q['question_text'])?></h2><?php if(!empty($q['image_path']) && preg_match('/^[A-Za-z0-9._\\/-]+$/',(string)$q['image_path']) && !str_contains($q['image_path'],'..') && !str_starts_with($q['image_path'],'/')):?><div class="mb-3 text-center"><img src="uploads/questions/<?=e($q['image_path'])?>" alt="Afbeelding bij de vraag" class="img-fluid rounded" style="max-height:420px;object-fit:contain"></div><?php endif;?><?php if($q['question_type']==='open'):?><label class="form-label text-secondary mt-3">Typ je antwoord:</label><textarea class="form-control answer-input" data-question="<?=$q['id']?>" name="question_<?=$q['id']?>" rows="4" <?=$viewMode?'disabled':''?>><?=e($q['answer_text']??'')?></textarea><?php
$showSpecialChars=!empty($test['vocab_left_label'])&&!empty($test['vocab_right_label']);
$targetLanguage='';
if($showSpecialChars&&!$viewMode){
    if(($test['vocab_direction']??'both')==='right_to_left')$targetLanguage=(string)$test['vocab_left_label'];
    elseif(($test['vocab_direction']??'both')==='left_to_right')$targetLanguage=(string)$test['vocab_right_label'];
    else $targetLanguage=($n%2===0)?(string)$test['vocab_right_label']:(string)$test['vocab_left_label'];
}
if($showSpecialChars):?><div class="special-chars mt-3" data-explanation="<?=e($q['explanation']??'')?>" data-language="<?=e($targetLanguage)?>"><div class="text-secondary small mb-2">Speciale tekens</div><div class="special-char-grid"></div></div><?php endif;?><?php else:?><div class="answer-grid"><?php foreach($q['options'] as $opt):?><label class="answer-option <?=($viewMode && (int)($q['selected_option_id']??0)===(int)$opt['id']) ? ((int)($q['is_correct']??0)===1 ? 'answer-option-review-correct' : 'answer-option-review-incorrect') : ''?>">
<input class="form-check-input answer-input answer-radio-input" data-question="<?=$q['id']?>" type="radio" name="question_<?=$q['id']?>" value="<?=$opt['id']?>" <?=((int)($q['selected_option_id']??0)===(int)$opt['id'])?'checked':''?> <?=$viewMode?'disabled':''?>><span class="answer-text"><?=e($opt['option_text'])?></span></label><?php endforeach;?></div><?php endif;?><?php if($viewMode):?>
<?php
$viewAnswered=$q['answer_text']!==null && trim((string)$q['answer_text'])!=='';
$viewAnswered=$viewAnswered || $q['selected_option_id']!==null;
$viewCorrect=(int)($q['is_correct']??0)===1;
$viewCorrectAnswers=[];
if($q['question_type']==='multiple_choice'){
    $viewCorrectStmt=$pdo->prepare("SELECT option_text FROM question_options WHERE question_id=? AND is_correct=1 ORDER BY sort_order,id");
    $viewCorrectStmt->execute([(int)$q['id']]);
    $viewCorrectAnswers=$viewCorrectStmt->fetchAll(PDO::FETCH_COLUMN);
}else{
    $viewCorrectStmt=$pdo->prepare("SELECT answer_text FROM open_question_answers WHERE question_id=? ORDER BY sort_order,id");
    $viewCorrectStmt->execute([(int)$q['id']]);
    $viewCorrectAnswers=$viewCorrectStmt->fetchAll(PDO::FETCH_COLUMN);
}
?>
<div class="feedback mt-4 alert <?=$viewCorrect?'alert-success':'alert-danger'?>">
<button type="button" class="feedback-close" aria-label="Melding sluiten">&times;</button>
<strong><?=$viewCorrect?'Goed!':'Helaas, fout.'?></strong>
<?php if(!$viewAnswered):?> <span>Niet ingevuld.</span><?php endif;?>
<?php if(!$viewCorrect && $viewCorrectAnswers):?><div class="mt-2"><strong>Juiste antwoord:</strong> <?=e(implode(' / ',$viewCorrectAnswers))?></div><?php endif;?>
</div>
<?php else:?><div class="feedback mt-4 d-none"></div><?php endif;?></div></div></section><?php endforeach;?></form></main><script>
(function(){
 const attemptId='<?= (int)$attemptId ?>',testId='<?= (int)$testId ?>';
 const cards=[...document.querySelectorAll('.question-card')],bar=document.getElementById('progressBar'),form=document.getElementById('quizForm');
 let current=<?= (int)$resumeIndex ?>,checking=false;
 function activity(data){fetch('activity.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data),keepalive:true}).catch(()=>{});}
 const saveUrl='quiz.php?id='+testId+<?= $reviewMode ? "'&review=1&topic_id=".(int)$topicId."'" : ($mode==='mistakes' ? "'&mode=mistakes'" : "''") ?>;
 function save(questionId,value){return fetch(saveUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'save_answer',attempt_id:attemptId,question_id:questionId,answer:value})}).then(r=>r.json());}
 function saveDraft(questionId,value){return fetch(saveUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'save_draft',attempt_id:attemptId,question_id:questionId,answer:value})}).then(r=>r.json());}
 function value(card){const el=card.querySelector('input[type=radio]:checked,textarea');return el?el.value.trim():'';}
 function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
 const specialChars={
   nederlands:['ë','ï','é','è','ê','ö','ü','á','à','â'],
   dutch:['ë','ï','é','è','ê','ö','ü','á','à','â'],
   duits:['ä','ö','ü','ß','Ä','Ö','Ü'],deutsch:['ä','ö','ü','ß','Ä','Ö','Ü'],german:['ä','ö','ü','ß','Ä','Ö','Ü'],
   spaans:['á','é','í','ó','ú','ü','ñ','¿','¡','Á','É','Í','Ó','Ú','Ü','Ñ'],
   spanish:['á','é','í','ó','ú','ü','ñ','¿','¡','Á','É','Í','Ó','Ú','Ü','Ñ'],
   frans:['à','â','æ','ç','é','è','ê','ë','î','ï','ô','œ','ù','û','ü','ÿ','À','Â','Æ','Ç','É','È','Ê','Ë','Î','Ï','Ô','Œ','Ù','Û','Ü','Ÿ'],
   french:['à','â','æ','ç','é','è','ê','ë','î','ï','ô','œ','ù','û','ü','ÿ','À','Â','Æ','Ç','É','È','Ê','Ë','Î','Ï','Ô','Œ','Ù','Û','Ü','Ÿ'],
   engels:['’','á','é','í','ó','ú','ä','ö','ü','ñ'],english:['’','á','é','í','ó','ú','ä','ö','ü','ñ'],
   italiaans:['à','è','é','ì','ò','ù','À','È','É','Ì','Ò','Ù'],italian:['à','è','é','ì','ò','ù','À','È','É','Ì','Ò','Ù'],
   portugees:['á','à','â','ã','ç','é','ê','í','ó','ô','õ','ú','ü'],portuguese:['á','à','â','ã','ç','é','ê','í','ó','ô','õ','ú','ü'],
   zweeds:['å','ä','ö','Å','Ä','Ö'],swedish:['å','ä','ö','Å','Ä','Ö'],
   deens:['æ','ø','å','Æ','Ø','Å'],danish:['æ','ø','å','Æ','Ø','Å'],
   noors:['æ','ø','å','Æ','Ø','Å'],norwegian:['æ','ø','å','Æ','Ø','Å'],
   fins:['ä','ö','å','Ä','Ö','Å'],finnish:['ä','ö','å','Ä','Ö','Å'],
   pools:['ą','ć','ę','ł','ń','ó','ś','ź','ż','Ą','Ć','Ę','Ł','Ń','Ó','Ś','Ź','Ż'],polish:['ą','ć','ę','ł','ń','ó','ś','ź','ż','Ą','Ć','Ę','Ł','Ń','Ó','Ś','Ź','Ż'],
   tsjechisch:['á','č','ď','é','ě','í','ň','ó','ř','š','ť','ú','ů','ý','ž'],czech:['á','č','ď','é','ě','í','ň','ó','ř','š','ť','ú','ů','ý','ž'],
   slowaaks:['á','ä','č','ď','é','í','ĺ','ľ','ň','ó','ô','ŕ','š','ť','ú','ý','ž'],slovak:['á','ä','č','ď','é','í','ĺ','ľ','ň','ó','ô','ŕ','š','ť','ú','ý','ž'],
   hongaars:['á','é','í','ó','ö','ő','ú','ü','ű'],hungarian:['á','é','í','ó','ö','ő','ú','ü','ű'],
   roemeens:['ă','â','î','ș','ț'],romanian:['ă','â','î','ș','ț'],
   turks:['ç','ğ','ı','İ','ö','ş','ü','Ç','Ğ','Ö','Ş','Ü'],turkish:['ç','ğ','ı','İ','ö','ş','ü','Ç','Ğ','Ö','Ş','Ü'],
   iJslands:['á','ð','é','í','ó','ú','ý','þ','æ','ö'],icelandic:['á','ð','é','í','ó','ú','ý','þ','æ','ö']
 };
 function languageKey(label){return String(label||'').trim().toLowerCase().replace(/\s+/g,' ').replace(/^de /,'');}
 function charsForLanguage(label){
   const key=languageKey(label);
   return specialChars[key]||[];
 }
 function setupSpecialChars(card){
   const box=card.querySelector('.special-chars');if(!box)return;
   const grid=box.querySelector('.special-char-grid');
   const chars=charsForLanguage(box.dataset.language);
   if(!chars.length){box.classList.add('d-none');return;}
   chars.forEach(ch=>{
     const btn=document.createElement('button');btn.type='button';
     btn.className='btn btn-outline-primary btn-lg special-char-btn';btn.textContent=ch;
     btn.addEventListener('click',()=>{
       const input=card.querySelector('textarea.answer-input');if(!input)return;
       const start=input.selectionStart??input.value.length,end=input.selectionEnd??start;
       input.value=input.value.slice(0,start)+ch+input.value.slice(end);input.focus();
       const pos=start+ch.length;input.setSelectionRange(pos,pos);
       input.dispatchEvent(new Event('input',{bubbles:true}));
     });
     grid.appendChild(btn);
   });
 }
 cards.forEach(setupSpecialChars);
 function updateSelected(card){
   card.querySelectorAll('.answer-option').forEach(label=>{
     const radio=label.querySelector('input[type=radio]');
     label.classList.toggle('selected',!!radio?.checked);
   });
 }
 function closeFeedback(box){if(box)box.classList.add('d-none');}
 document.addEventListener('click',e=>{
   const close=e.target.closest('.feedback-close');
   if(close)closeFeedback(close.closest('.feedback'));
 });
 function warningFeedback(box,message){
   box.className='feedback mt-4 alert alert-warning';
   box.innerHTML='<button type="button" class="feedback-close" aria-label="Melding sluiten">&times;</button>'+esc(message);
 }
 function feedback(card,data){
   const box=card.querySelector('.feedback');
   box.className='feedback mt-4 alert '+(data.is_correct?'alert-success':'alert-danger');
   box.innerHTML='<button type="button" class="feedback-close" aria-label="Melding sluiten">&times;</button><strong>'+(data.is_correct?'Goed!':'Helaas, fout.')+'</strong>';
   if(!data.is_correct && data.feedback && data.feedback.correct_answers && data.feedback.correct_answers.length) box.innerHTML+='<div class="mt-2"><strong>Juiste antwoord:</strong> '+data.feedback.correct_answers.map(esc).join(' / ')+'</div>';
   if(data.feedback && data.feedback.explanation) box.innerHTML+='<div class="mt-2">'+esc(data.feedback.explanation)+'</div>';
   if(data.feedback && data.feedback.ai_reason) box.innerHTML+='<div class="mt-2 small text-secondary"><strong>AI-melding:</strong> '+esc(data.feedback.ai_reason)+'</div>';
   if(data.feedback && data.feedback.ai_used){
     box.innerHTML+='<div class="mt-3 small text-secondary">✓ Beoordeeld door '+esc(data.feedback.ai_model||'AI')+'</div>';
   }else if(data.feedback && data.feedback.ai_available===false && !data.is_correct){
     box.innerHTML+='<div class="mt-3 small text-warning">⚠ AI-beoordeling niet beschikbaar. Het antwoord is daarom niet als goed beoordeeld.</div>';
   }
 }
 function show(i){cards.forEach((c,n)=>c.classList.toggle('d-none',n!==i));current=i;bar.style.width=((i+1)/cards.length*100)+'%';window.scrollTo({top:0,behavior:'smooth'});}
 async function check(card,i,btn){
   if(<?= $viewMode ? 'true' : 'false' ?>){
     if(i===cards.length-1){window.location.href='topic.php?id='+<?= (int)$test['topic_id'] ?>;return;}
     show(i+1);return;
   }
   if(btn.dataset.checked==='1'){
     if(i===cards.length-1){finish();return;}
     show(i+1);
     return;
   }
   const v=value(card);
   if(!v){warningFeedback(card.querySelector('.feedback'),'Geef eerst een antwoord voordat je verdergaat.');return;}
   if(checking)return;checking=true;btn.disabled=true;
   try{
     const data=await save(card.dataset.questionId,v);
     if(!data.ok||data.answered===false)throw new Error(data.message||data.error||'save_failed');
     feedback(card,data);
     card.querySelectorAll('.answer-input').forEach(el=>el.disabled=true);
     btn.dataset.checked='1';
     btn.textContent=i===cards.length-1?'Afronden':'Volgende';
     btn.disabled=false;
   }catch(e){
     btn.disabled=false;
     warningFeedback(card.querySelector('.feedback'),'Het antwoord kon niet worden opgeslagen. '+(e&&e.message?'Fout: '+e.message:'Probeer het opnieuw.'));
   }finally{checking=false;}
 }
 async function finish(){
   btnFinishState();
   try{
     const response=await fetch(saveUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'finish',attempt_id:attemptId})});
     if(response.status===409){
       const finishData=await response.json();
       if(finishData.empty && finishData.redirect){window.location.href=finishData.redirect;return;}
     }
     if(!response.ok)throw new Error('finish_failed_'+response.status);
     const finishResult=await response.json();
     if(!finishResult.ok||!finishResult.finished||!finishResult.redirect)throw new Error('finish_invalid_response');
     window.location.href=finishResult.redirect;
   }catch(e){
     const card=cards[cards.length-1];
     const b=card.querySelector('.feedback');
     b.className='feedback mt-4 alert alert-warning';
     b.innerHTML='<button type="button" class="feedback-close" aria-label="Melding sluiten">&times;</button>De toets kon niet worden afgerond. Probeer het opnieuw.';
     const btn=card.querySelector('.next-btn');btn.disabled=false;btn.textContent='Afronden';
   }
 }
 function btnFinishState(){
   const btn=cards[cards.length-1]?.querySelector('.next-btn');
   if(btn){btn.disabled=true;btn.textContent='Afronden…';}
 }
 cards.forEach((card,i)=>{
   card.querySelector('.next-btn').addEventListener('click',()=>check(card,i,card.querySelector('.next-btn')));
   if(<?= $viewMode ? 'true' : 'false' ?>) return;
   card.querySelectorAll('.answer-input').forEach(el=>{
     if(el.type==='radio')el.addEventListener('change',()=>{
       updateSelected(card);
       saveDraft(el.dataset.question,el.value).catch(()=>{});
     });
     if(el.tagName==='TEXTAREA'){let timer;el.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>saveDraft(el.dataset.question,el.value).catch(()=>{}),500);});}
   });
   updateSelected(card);
 });
 show(current);
 if(!<?= $viewMode ? 'true' : 'false' ?>)activity({action:'start',test_id:testId,attempt_id:attemptId});
 let activeUntil=Date.now()+60000;const touch=()=>{activeUntil=Date.now()+60000;};
 ['mousemove','mousedown','keydown','touchstart','scroll'].forEach(e=>window.addEventListener(e,touch,{passive:true}));
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')touch();});
 setInterval(()=>activity({action:'heartbeat',active:(document.visibilityState==='visible'&&Date.now()<activeUntil)?'1':'0'}),15000);
 window.addEventListener('beforeunload',()=>activity({action:'end'}));
})();
</script></body></html>