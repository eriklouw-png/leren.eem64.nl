<?php
require __DIR__.'/../app/bootstrap.php';
$testId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);if(!$testId)redirect('index.php');
$s=$pdo->prepare("SELECT t.id,t.title,t.description,s.id subject_id,s.name subject_name FROM tests t JOIN topics tp ON tp.id=t.topic_id JOIN subjects s ON s.id=tp.subject_id WHERE t.id=? AND t.is_active=1");$s->execute([$testId]);$test=$s->fetch();if(!$test){http_response_code(404);exit('Toets niet gevonden.');}
$s=$pdo->prepare("SELECT id,question_text,question_type FROM questions WHERE test_id=? ORDER BY sort_order,id");$s->execute([$testId]);$questions=$s->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){
    $pdo->beginTransaction();
    try{
        $x=$pdo->prepare("INSERT INTO attempts(test_id) VALUES(?)");$x->execute([$testId]);$attemptId=(int)$pdo->lastInsertId();$correct=0;
        $op=$pdo->prepare("SELECT id,is_correct FROM question_options WHERE id=? AND question_id=?");
        $open=$pdo->prepare("SELECT answer_text FROM open_question_answers WHERE question_id=? ORDER BY sort_order,id");
        $ans=$pdo->prepare("INSERT INTO attempt_answers(attempt_id,question_id,selected_option_id,answer_text,is_correct) VALUES(?,?,?,?,?)");
        foreach($questions as $q){
            $raw=$_POST['question_'.$q['id']]??'';
            $selected=null;$answerText=null;$ok=null;
            if($q['question_type']==='open'){
                $answerText=trim((string)$raw);
                $open->execute([$q['id']]);$accepted=$open->fetchAll(PDO::FETCH_COLUMN);
                $ok=$answerText!==''&&open_answer_matches($answerText,$accepted)?1:0;
                if($ok)$correct++;
            }else{
                $selected=filter_var($raw,FILTER_VALIDATE_INT);
                if($selected!==false&&$selected!==null){
                    $op->execute([$selected,$q['id']]);$o=$op->fetch();
                    if($o){$ok=(int)$o['is_correct'];$correct+=$ok;}else{$selected=null;}
                }
            }
            $ans->execute([$attemptId,$q['id'],$selected,$answerText,$ok]);
        }
        $total=count($questions);$score=$total?round($correct/$total*100,2):0;
        $u=$pdo->prepare("UPDATE attempts SET score=?,finished_at=NOW() WHERE id=?");$u->execute([$score,$attemptId]);
        $pdo->commit();redirect('result.php?id='.$attemptId);
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
$o=$pdo->prepare("SELECT id,option_text FROM question_options WHERE question_id=? ORDER BY sort_order,id");
foreach($questions as &$q){$q['options']=[];if($q['question_type']==='multiple_choice'){$o->execute([$q['id']]);$q['options']=$o->fetchAll();}}unset($q);
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($test['title'])?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-4" style="max-width:850px"><a href="subject.php?id=<?=$test['subject_id']??''?>">&larr; Terug</a><h1 class="mt-3"><?=e($test['title'])?></h1><p class="text-secondary"><?=e($test['description'])?></p><form method="post"><?php foreach($questions as $n=>$q):?><div class="card shadow-sm mb-4"><div class="card-body"><h2 class="h5"><?=$n+1?>. <?=e($q['question_text'])?></h2><?php if($q['question_type']==='open'):?><label class="form-label text-secondary">Typ je antwoord:</label><textarea class="form-control" name="question_<?=$q['id']?>" rows="3" required></textarea><?php else:foreach($q['options'] as $opt):?><div class="form-check my-2"><input class="form-check-input" type="radio" name="question_<?=$q['id']?>" id="option_<?=$opt['id']?>" value="<?=$opt['id']?>"><label class="form-check-label" for="option_<?=$opt['id']?>"><?=e($opt['option_text'])?></label></div><?php endforeach;endif;?></div></div><?php endforeach;?><button class="btn btn-primary btn-lg" type="submit">Toets nakijken</button></form></main></body></html>
