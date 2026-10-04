<?php
require __DIR__.'/../app/bootstrap.php';require_admin();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);$testId=filter_input(INPUT_GET,'test_id',FILTER_VALIDATE_INT);
if($id){$s=$pdo->prepare("SELECT * FROM questions WHERE id=?");$s->execute([$id]);$q=$s->fetch();if(!$q)exit('Vraag niet gevonden.');$testId=(int)$q['test_id'];$s=$pdo->prepare("SELECT * FROM question_options WHERE question_id=? ORDER BY sort_order,id");$s->execute([$id]);$options=$s->fetchAll();}else{$q=null;$options=[['option_text'=>'','is_correct'=>0],['option_text'=>'','is_correct'=>0],['option_text'=>'','is_correct'=>0],['option_text'=>'','is_correct'=>0]];}
if(!$testId)redirect('admin.php');
if($_SERVER['REQUEST_METHOD']==='POST'){
 $text=trim($_POST['question_text']??'');$explanation=trim($_POST['explanation']??'');$sort=(int)($_POST['sort_order']??0);$texts=$_POST['option_text']??[];$correct=(int)($_POST['correct']??-1);
 if($text==='')$error='De vraag is verplicht.';elseif($correct<0||$correct>=count($texts))$error='Kies één juist antwoord.';else{
  $pdo->beginTransaction();try{
   if($id){$s=$pdo->prepare("UPDATE questions SET question_text=?,explanation=?,sort_order=? WHERE id=? AND test_id=?");$s->execute([$text,$explanation,$sort,$id,$testId]);$pdo->prepare("DELETE FROM question_options WHERE question_id=?")->execute([$id]);}
   else{$s=$pdo->prepare("INSERT INTO questions(test_id,question_text,explanation,sort_order) VALUES(?,?,?,?)");$s->execute([$testId,$text,$explanation,$sort]);$id=(int)$pdo->lastInsertId();}
   $s=$pdo->prepare("INSERT INTO question_options(question_id,option_text,is_correct,sort_order) VALUES(?,?,?,?)");
   foreach($texts as $i=>$v){$v=trim($v);if($v!=='')$s->execute([$id,$v,$i===$correct?1:0,$i+1]);}
   $pdo->commit();redirect('questions.php?test_id='.$testId);
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
 }
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vraag</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-4" style="max-width:800px"><a href="questions.php?test_id=<?=$testId?>">&larr; Vragen</a><div class="card shadow-sm mt-3"><div class="card-body p-4"><h1 class="h3"><?= $id?'Vraag bewerken':'Nieuwe vraag'?></h1><?php if(!empty($error)):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><form method="post"><label class="form-label">Vraag</label><textarea class="form-control mb-3" name="question_text" rows="3" required><?=e($q['question_text']??'')?></textarea><label class="form-label">Uitleg na beantwoorden (optioneel)</label><textarea class="form-control mb-3" name="explanation" rows="2"><?=e($q['explanation']??'')?></textarea><label class="form-label">Volgorde</label><input class="form-control mb-4" type="number" name="sort_order" value="<?=e((string)($q['sort_order']??0))?>"><h2 class="h5">Antwoorden</h2><p class="text-secondary">Vul minimaal twee antwoorden in en selecteer precies één juist antwoord.</p><?php foreach($options as $i=>$o):?><div class="input-group mb-2"><span class="input-group-text"><input type="radio" name="correct" value="<?=$i?>" <?=!empty($o['is_correct'])?'checked':''?>></span><input class="form-control" name="option_text[]" value="<?=e($o['option_text']??'')?>" placeholder="Antwoord <?=($i+1)?>"></div><?php endforeach;?><button class="btn btn-primary mt-3">Opslaan</button></form></div></div></main></body></html>
