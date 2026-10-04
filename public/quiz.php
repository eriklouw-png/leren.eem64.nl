<?php
require __DIR__.'/../app/bootstrap.php';

$testId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$testId)redirect('index.php');

$s=$pdo->prepare("SELECT t.id,t.title,t.description,s.id subject_id,s.name subject_name FROM tests t JOIN topics tp ON tp.id=t.topic_id JOIN subjects s ON s.id=tp.subject_id WHERE t.id=? AND t.is_active=1");
$s->execute([$testId]);$test=$s->fetch();
if(!$test){http_response_code(404);exit('Toets niet gevonden.');}

if(!isset($_SESSION['learner_token']))$_SESSION['learner_token']=bin2hex(random_bytes(32));
$browserToken=$_SESSION['learner_token'];
$mode=$_GET['mode']??'normal';
if(!in_array($mode,['normal','mistakes'],true))$mode='normal';
$sourceAttemptId=filter_input(INPUT_GET,'source',FILTER_VALIDATE_INT)?:null;

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_answer'){
    header('Content-Type: application/json; charset=utf-8');
    $attemptId=filter_var($_POST['attempt_id']??null,FILTER_VALIDATE_INT);
    $questionId=filter_var($_POST['question_id']??null,FILTER_VALIDATE_INT);
    if(!$attemptId||!$questionId){http_response_code(400);echo json_encode(['ok'=>false]);exit;}
    $a=$pdo->prepare("SELECT id FROM attempts WHERE id=? AND test_id=? AND browser_token=? AND status='in_progress'");
    $a->execute([$attemptId,$testId,$browserToken]);
    if(!$a->fetch()){http_response_code(403);echo json_encode(['ok'=>false]);exit;}
    $q=$pdo->prepare("SELECT q.question_type FROM questions q JOIN attempt_questions aq ON aq.question_id=q.id AND aq.attempt_id=? WHERE q.id=? AND q.test_id=?");
    $q->execute([$attemptId,$questionId,$testId]);$question=$q->fetch();
    if(!$question){http_response_code(400);echo json_encode(['ok'=>false]);exit;}
    $raw=$_POST['answer']??'';$selected=null;$answerText=null;$ok=null;
    if($question['question_type']==='open'){
        $answerText=trim((string)$raw);
        $x=$pdo->prepare("SELECT answer_text FROM open_question_answers WHERE question_id=? ORDER BY sort_order,id");
        $x->execute([$questionId]);$ok=$answerText!==''&&open_answer_matches($answerText,$x->fetchAll(PDO::FETCH_COLUMN))?1:0;
    }else{
        $selected=filter_var($raw,FILTER_VALIDATE_INT);
        if($selected!==false&&$selected!==null){
            $x=$pdo->prepare("SELECT is_correct FROM question_options WHERE id=? AND question_id=?");
            $x->execute([$selected,$questionId]);$o=$x->fetch();
            if(!$o)$selected=null;else$ok=(int)$o['is_correct'];
        }
    }
    $x=$pdo->prepare("INSERT INTO attempt_answers(attempt_id,question_id,selected_option_id,answer_text,is_correct,answered_at) VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE selected_option_id=VALUES(selected_option_id),answer_text=VALUES(answer_text),is_correct=VALUES(is_correct),answered_at=NOW()");
    $x->execute([$attemptId,$questionId,$selected,$answerText,$ok]);
    echo json_encode(['ok'=>true]);exit;
}

$attempt=null;
if($mode==='normal'){
    $x=$pdo->prepare("SELECT * FROM attempts WHERE test_id=? AND browser_token=? AND status='in_progress' AND mode='normal' ORDER BY started_at DESC LIMIT 1");
    $x->execute([$testId,$browserToken]);$attempt=$x->fetch();
}

if(!$attempt){
    $mistakes=[];
    if($mode==='mistakes'){
        if(!$sourceAttemptId)redirect('subject.php?id='.$test['subject_id']);
        $x=$pdo->prepare("SELECT id FROM attempts WHERE id=? AND test_id=? AND browser_token=? AND status='finished'");
        $x->execute([$sourceAttemptId,$testId,$browserToken]);
        if(!$x->fetch()){http_response_code(403);exit('Deze poging kan niet worden gebruikt voor foutentraining.');}
        $x=$pdo->prepare("SELECT q.id,q.sort_order FROM attempt_questions aq JOIN questions q ON q.id=aq.question_id JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id WHERE aq.attempt_id=? AND aa.is_correct=0 ORDER BY aq.sort_order,q.id");
        $x->execute([$sourceAttemptId]);$mistakes=$x->fetchAll();
        if(!$mistakes)redirect('result.php?id='.$sourceAttemptId.'&done=1');
    }
    $pdo->beginTransaction();
    try{
        $x=$pdo->prepare("INSERT INTO attempts(test_id,status,mode,source_attempt_id,browser_token) VALUES(?,'in_progress',?,?,?)");
        $x->execute([$testId,$mode,$mode==='mistakes'?$sourceAttemptId:null,$browserToken]);
        $attemptId=(int)$pdo->lastInsertId();
        if($mode==='mistakes'){
            $y=$pdo->prepare("INSERT INTO attempt_questions(attempt_id,question_id,sort_order) VALUES(?,?,?)");
            foreach($mistakes as $i=>$q)$y->execute([$attemptId,$q['id'],$i+1]);
        }else{
            $y=$pdo->prepare("INSERT INTO attempt_questions(attempt_id,question_id,sort_order) SELECT ?,id,sort_order FROM questions WHERE test_id=? ORDER BY sort_order,id");
            $y->execute([$attemptId,$testId]);
        }
        $pdo->commit();
        $attempt=['id'=>$attemptId];
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
$attemptId=(int)$attempt['id'];

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='finish'){
    foreach($_POST as $key=>$raw){
        if(strpos($key,'question_')!==0)continue;
        $questionId=(int)substr($key,9);
        $q=$pdo->prepare("SELECT question_type FROM questions q JOIN attempt_questions aq ON aq.question_id=q.id WHERE aq.attempt_id=? AND q.id=? AND q.test_id=?");
        $q->execute([$attemptId,$questionId,$testId]);$question=$q->fetch();
        if(!$question)continue;
        $selected=null;$answerText=null;$ok=null;
        if($question['question_type']==='open'){
            $answerText=trim((string)$raw);
            $x=$pdo->prepare("SELECT answer_text FROM open_question_answers WHERE question_id=?");
            $x->execute([$questionId]);$ok=$answerText!==''&&open_answer_matches($answerText,$x->fetchAll(PDO::FETCH_COLUMN))?1:0;
        }else{
            $selected=filter_var($raw,FILTER_VALIDATE_INT);
            if($selected!==false&&$selected!==null){
                $x=$pdo->prepare("SELECT is_correct FROM question_options WHERE id=? AND question_id=?");
                $x->execute([$selected,$questionId]);$o=$x->fetch();
                if(!$o)$selected=null;else$ok=(int)$o['is_correct'];
            }
        }
        $x=$pdo->prepare("INSERT INTO attempt_answers(attempt_id,question_id,selected_option_id,answer_text,is_correct,answered_at) VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE selected_option_id=VALUES(selected_option_id),answer_text=VALUES(answer_text),is_correct=VALUES(is_correct),answered_at=NOW()");
        $x->execute([$attemptId,$questionId,$selected,$answerText,$ok]);
    }
    $x=$pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN aa.is_correct=1 THEN 1 ELSE 0 END) correct FROM attempt_questions aq LEFT JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id WHERE aq.attempt_id=?");
    $x->execute([$attemptId]);$stats=$x->fetch();
    $total=(int)$stats['total'];$correct=(int)$stats['correct'];$score=$total?round($correct/$total*100,2):0;
    $x=$pdo->prepare("UPDATE attempts SET status='finished',score=?,finished_at=NOW() WHERE id=? AND status='in_progress'");
    $x->execute([$score,$attemptId]);
    unset($_SESSION['current_attempt_id']);
    redirect('result.php?id='.$attemptId);
}

$x=$pdo->prepare("SELECT q.id,q.question_text,q.question_type,aq.sort_order,aa.selected_option_id,aa.answer_text FROM attempt_questions aq JOIN questions q ON q.id=aq.question_id LEFT JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id WHERE aq.attempt_id=? ORDER BY aq.sort_order,q.id");
$x->execute([$attemptId]);$questions=$x->fetchAll();
$o=$pdo->prepare("SELECT id,option_text FROM question_options WHERE question_id=? ORDER BY sort_order,id");
foreach($questions as &$q){$q['options']=[];if($q['question_type']==='multiple_choice'){$o->execute([$q['id']]);$q['options']=$o->fetchAll();}}unset($q);
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($test['title'])?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-4" style="max-width:850px"><a href="subject.php?id=<?=$test['subject_id']?>">&larr; Terug</a><h1 class="mt-3"><?=e($test['title'])?></h1><?php if($mode==='mistakes'):?><div class="alert alert-warning">Je oefent nu alleen de vragen die je eerder fout had.</div><?php else:?><div class="alert alert-info">Je antwoorden worden automatisch opgeslagen. Je kunt later verdergaan.</div><?php endif;?><form method="post" id="quizForm"><input type="hidden" name="action" value="finish"><?php foreach($questions as $n=>$q):?><div class="card shadow-sm mb-4"><div class="card-body"><h2 class="h5"><?=$n+1?>. <?=e($q['question_text'])?></h2><?php if($q['question_type']==='open'):?><label class="form-label text-secondary">Typ je antwoord:</label><textarea class="form-control autosave" data-question="<?=$q['id']?>" name="question_<?=$q['id']?>" rows="3"><?=e($q['answer_text']??'')?></textarea><?php else:foreach($q['options'] as $opt):?><div class="form-check my-2"><input class="form-check-input autosave" data-question="<?=$q['id']?>" type="radio" name="question_<?=$q['id']?>" value="<?=$opt['id']?>" <?=((int)($q['selected_option_id']??0)===(int)$opt['id'])?'checked':''?>><label class="form-check-label"><?=e($opt['option_text'])?></label></div><?php endforeach;endif;?></div></div><?php endforeach;?><button class="btn btn-primary btn-lg" type="submit">Toets nakijken</button></form></main><script>
(function(){
 const attemptId='<?= (int)$attemptId ?>',testId='<?= (int)$testId ?>';
 function save(questionId,value){fetch('quiz.php?id='+testId,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'save_answer',attempt_id:attemptId,question_id:questionId,answer:value}),keepalive:true}).catch(()=>{});}
 document.querySelectorAll('input.autosave').forEach(el=>el.addEventListener('change',()=>save(el.dataset.question,el.value)));
 document.querySelectorAll('textarea.autosave').forEach(el=>{let timer;el.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>save(el.dataset.question,el.value),500);});});
 function activity(data){fetch('activity.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data),keepalive:true}).catch(()=>{});}
 activity({action:'start',test_id:testId,attempt_id:attemptId});
 let activeUntil=0;const touch=()=>{activeUntil=Date.now()+60000;};
 ['mousemove','mousedown','keydown','touchstart','scroll'].forEach(e=>window.addEventListener(e,touch,{passive:true}));
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')touch();});
 setInterval(()=>activity({action:'heartbeat',active:(document.visibilityState==='visible'&&Date.now()<activeUntil)?'1':'0'}),15000);
 document.getElementById('quizForm').addEventListener('submit',()=>activity({action:'end'}));
 window.addEventListener('beforeunload',()=>activity({action:'end'}));
})();
</script></body></html>