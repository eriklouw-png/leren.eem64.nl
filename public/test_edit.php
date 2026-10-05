<?php
require __DIR__.'/../app/bootstrap.php';require_admin();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);$test=null;
if($id){
 $s=$pdo->prepare("SELECT t.*,s.name subject_name,tp.name topic_name FROM tests t JOIN topics tp ON tp.id=t.topic_id JOIN subjects s ON s.id=tp.subject_id WHERE t.id=?");
 $s->execute([$id]);$test=$s->fetch();if(!$test)exit('Sub-Test niet gevonden.');
}
$subjects=$pdo->query("SELECT id,name FROM subjects ORDER BY name")->fetchAll();
$topics=$pdo->query("SELECT id,subject_id,name,is_active FROM topics WHERE is_active=1 ORDER BY subject_id,name")->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=$_POST['action']??'save';
 if(!$id){http_response_code(400);exit('Ongeldig Sub-Test.');}
 if($action==='hide' || $action==='restore'){
   $active=$action==='restore'?1:0;
   $s=$pdo->prepare("UPDATE tests SET is_active=? WHERE id=?");
   $s->execute([$active,$id]);
   redirect('test_edit.php?id='.$id);
 }
 $title=trim($_POST['title']??'');$description=trim($_POST['description']??'');$topicId=filter_input(INPUT_POST,'topic_id',FILTER_VALIDATE_INT);$testType=$_POST['test_type']??'mixed';$vocabDirection=$_POST['vocab_direction']??($test['vocab_direction']??'both');
 if(!in_array($testType,['vocabulary','multiple_choice','mixed'],true))$testType='mixed';
 if(!in_array($vocabDirection,['both','left_to_right','right_to_left'],true))$vocabDirection='both';
 if($title==='')$error='Een titel is verplicht.';
 elseif(!$topicId)$error='Kies een overhoring.';
 elseif(in_array($testType,['multiple_choice','vocabulary'],true)){
     $requiredType=$testType==='multiple_choice'?'multiple_choice':'open';
     $wrongLabel=$testType==='multiple_choice'?'open':'meerkeuze';
     $x=$pdo->prepare("SELECT COUNT(*) FROM questions WHERE test_id=? AND question_type<>?");
     $x->execute([$id,$requiredType]);
     if((int)$x->fetchColumn()>0)$error='Deze sub-test bevat vragen van het verkeerde type. Een sub-test van dit type mag alleen '.$wrongLabel.'vragen bevatten.';
 }
 if(empty($error)){
   try{
     $leftLabel=$test['subject_name']??'';$rightLabel='Nederlands';
     $s=$pdo->prepare("UPDATE tests SET topic_id=?,title=?,description=?,test_type=?,vocab_left_label=?,vocab_right_label=?,vocab_direction=? WHERE id=?");
     $s->execute([$topicId,$title,$description,$testType,$testType==='vocabulary'?$leftLabel:null,$testType==='vocabulary'?$rightLabel:null,$vocabDirection,$id]);
     redirect('questions.php?test_id='.$id);
   }catch(PDOException $e){
     $error=$e->getCode()==='23000'?'Deze titel bestaat al binnen deze overhoring. Kies een andere titel.':'Opslaan is mislukt. Probeer het opnieuw.';
   }
 }
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sub-Test</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-4" style="max-width:760px"><a href="subject_manage.php?id=<?=isset($test['subject_id'])?(int)$test['subject_id']:''?>">&larr; Terug naar beheer</a><div class="card shadow-sm mt-3"><div class="card-body p-4"><h1 class="h3"><?= $id?'Sub-Test bewerken':'Nieuwe sub-test'?></h1><?php if(!empty($error)):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><form method="post"><input type="hidden" name="action" value="save"><label class="form-label">Titel</label><input class="form-control mb-3" name="title" value="<?=e($test['title']??'')?>" required><label class="form-label">Beschrijving</label><textarea class="form-control mb-3" name="description" rows="3"><?=e($test['description']??'')?></textarea><label class="form-label">Type sub-test</label><select class="form-select mb-3" name="test_type"><option value="vocabulary" <?=($test['test_type']??'mixed')==='vocabulary'?'selected':''?>>Woordjes oefenen</option><option value="multiple_choice" <?=($test['test_type']??'mixed')==='multiple_choice'?'selected':''?>>Alleen multiple choice</option><option value="mixed" <?=($test['test_type']??'mixed')==='mixed'?'selected':''?>>Combinatie van multiple choice en open vragen</option></select><div class="form-text mb-3">Bij Woordjes oefenen kun je een woordenlijst importeren en beide richtingen oefenen.</div>
<div id="vocab-settings" class="border rounded p-3 mb-3 <?=($test['test_type']??'mixed')==='vocabulary'?'':'d-none'?>">
<div class="alert alert-secondary py-2">Taal: <strong><?=e($test['subject_name']??'')?> → Nederlands</strong></div>
<label class="form-label">Oefenrichting</label><select class="form-select" name="vocab_direction"><option value="both" <?=($test['vocab_direction']??'both')==='both'?'selected':''?>>Beide richtingen</option><option value="left_to_right" <?=($test['vocab_direction']??'both')==='left_to_right'?'selected':''?>>Alleen eerste → tweede</option><option value="right_to_left" <?=($test['vocab_direction']??'both')==='right_to_left'?'selected':''?>>Alleen tweede → eerste</option></select>
</div>
<label class="form-label">Overhoring</label><select class="form-select mb-3" name="topic_id" required><option value="">Kies een overhoring...</option><?php foreach($topics as $topic):?><option value="<?=$topic['id']?>" <?=isset($test['topic_id'])&&(int)$test['topic_id']===(int)$topic['id']?'selected':''?>><?php $subjectName='';foreach($subjects as $subject)if((int)$subject['id']===(int)$topic['subject_id']){$subjectName=$subject['name'];break;}?><?=e($subjectName.' — '.$topic['name'])?></option><?php endforeach;?></select>
<div class="d-flex justify-content-between align-items-center gap-2 mt-4"><button class="btn btn-primary" type="submit">Opslaan</button><?php if($id):?><?php if((int)$test['is_active']):?><button class="btn btn-outline-danger" type="submit" name="action" value="hide" onclick="return confirm('Deze Sub-Test verbergen? De gegevens blijven bewaard.')">Verbergen</button><?php else:?><button class="btn btn-outline-success" type="submit" name="action" value="restore">Herstellen</button><?php endif;?><?php endif;?></div>
<?php if($id && !(int)$test['is_active']):?><div class="alert alert-warning mt-3 mb-0">Deze Sub-Test is verborgen voor leerlingen.</div><?php endif;?>
</form></div></div></main><script>
const typeSelect=document.querySelector('[name="test_type"]'),vocabSettings=document.getElementById('vocab-settings');
function toggleVocab(){vocabSettings.classList.toggle('d-none',typeSelect.value!=='vocabulary')}
typeSelect.addEventListener('change',toggleVocab);
</script></body></html>