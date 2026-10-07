<?php
require __DIR__.'/../app/bootstrap.php';require_admin();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);$test=null;
if($id){
 $s=$pdo->prepare("SELECT t.*,s.name subject_name,tp.name topic_name FROM tests t JOIN topics tp ON tp.id=t.topic_id JOIN subjects s ON s.id=tp.subject_id WHERE t.id=? AND t.is_active=1");
 $s->execute([$id]);$test=$s->fetch();if(!$test)exit('Sub-Test niet gevonden.');
}
$subjects=$pdo->query("SELECT id,name FROM subjects ORDER BY name")->fetchAll();
$topics=$pdo->query("SELECT id,subject_id,name,is_active FROM topics WHERE is_active=1 ORDER BY subject_id,name")->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=$_POST['action']??'save';
 if(!$id){http_response_code(400);exit('Ongeldig Sub-Test.');}
 if($action==='delete'){
   $subjectId=(int)($test['subject_id']??0);
   $s=$pdo->prepare("UPDATE tests SET is_active=0 WHERE id=?");
   $s->execute([$id]);
   redirect($subjectId?'subject_manage.php?id='.$subjectId.'&deleted=test':'admin.php?deleted=test');
 }
 $importText=(string)($_POST['import_text']??'');
 if($action==='import'){
   if(trim($importText)===''){$error='Plak eerst gegevens om te importeren.';}
   else{
     $lines=preg_split('/\R/u',$importText);$pdo->beginTransaction();
     try{
       $q=$pdo->prepare("INSERT INTO questions(test_id,question_text,question_type,explanation,sort_order) VALUES(?,?,?,?,?)");
       $opt=$pdo->prepare("INSERT INTO question_options(question_id,option_text,is_correct,sort_order) VALUES(?,?,?,?)");
       $oa=$pdo->prepare("INSERT INTO open_question_answers(question_id,answer_text,sort_order) VALUES(?,?,?)");
       $sort=(int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM questions WHERE test_id=".(int)$id)->fetchColumn();
       $count=0;$direction=$test['vocab_direction']??'both';$leftLabel=$test['vocab_left_label']?:'Eerste taal';$rightLabel=$test['vocab_right_label']?:'Nederlands';
       foreach($lines as $lineNo=>$line){
         $line=trim($line);if($line===''||str_starts_with($line,'#'))continue;
         if($test['test_type']==='vocabulary'){
           $pos=strpos($line,'=');if($pos===false)throw new RuntimeException('Regel '.($lineNo+1).' bevat geen = teken.');
           $l=trim(substr($line,0,$pos));$r=trim(substr($line,$pos+1));if($l===''||$r==='')throw new RuntimeException('Regel '.($lineNo+1).' moet aan beide kanten tekst bevatten.');
           $dirs=[];if($direction==='both'||$direction==='left_to_right')$dirs[]=['p'=>$l,'a'=>$r,'to'=>$rightLabel];if($direction==='both'||$direction==='right_to_left')$dirs[]=['p'=>$r,'a'=>$l,'to'=>$leftLabel];
           foreach($dirs as $d){$sort++;$q->execute([$id,$d['p'],'open','Vertaal naar '.$d['to'].'.',$sort]);$oa->execute([(int)$pdo->lastInsertId(),$d['a'],1]);$count++;}
         }elseif($test['test_type']==='multiple_choice'){
           $row=array_map('trim',str_getcsv($line,';'));if(count($row)<6)throw new RuntimeException('Regel '.($lineNo+1).' heeft te weinig kolommen.');
           [$question,$correct,$b,$c,$d,$explanation]=array_pad($row,6,'');if($question===''||$correct===''||$b===''||$c===''||$d==='')throw new RuntimeException('Regel '.($lineNo+1).' mist een verplicht veld.');
           $sort++;$q->execute([$id,$question,'multiple_choice',$explanation,$sort]);$qid=(int)$pdo->lastInsertId();foreach([$correct,$b,$c,$d] as $i=>$answer)$opt->execute([$qid,$answer,$i===0?1:0,$i+1]);$count++;
         }else{
           $row=array_map('trim',str_getcsv($line,';'));if(count($row)<7)throw new RuntimeException('Regel '.($lineNo+1).' heeft te weinig kolommen.');
           [$type,$question,$correct,$b,$c,$d,$explanation]=array_pad($row,7,'');$qt=strtolower($type)==='mc'?'multiple_choice':(strtolower($type)==='open'?'open':null);if(!$qt)throw new RuntimeException('Regel '.($lineNo+1).': type moet mc of open zijn.');if($question===''||$correct==='')throw new RuntimeException('Regel '.($lineNo+1).' mist vraag of juiste antwoord.');
           $sort++;$q->execute([$id,$question,$qt,$explanation,$sort]);$qid=(int)$pdo->lastInsertId();if($qt==='open'){if($b!==''||$c!==''||$d!=='')throw new RuntimeException('Regel '.($lineNo+1).': bij open moeten B/C/D leeg zijn.');foreach(array_values(array_filter(array_map('trim',explode('|',$correct)),fn($v)=>$v!=='')) as $i=>$answer)$oa->execute([$qid,$answer,$i+1]);}else{if($b===''||$c===''||$d==='')throw new RuntimeException('Regel '.($lineNo+1).': bij mc zijn B, C en D verplicht.');foreach([$correct,$b,$c,$d] as $i=>$answer)$opt->execute([$qid,$answer,$i===0?1:0,$i+1]);}$count++;
         }
       }
       $pdo->commit();redirect('questions.php?test_id='.$id);
     }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error='Import mislukt: '.$e->getMessage();}
   }
 } 
 $title=trim($_POST['title']??'');$description=trim($_POST['description']??'');$topicId=filter_input(INPUT_POST,'topic_id',FILTER_VALIDATE_INT);$testType=$_POST['test_type']??'mixed';$vocabDirection=$_POST['vocab_direction']??($test['vocab_direction']??'both');
 $shuffleQuestions=isset($_POST['shuffle_questions'])?1:0;
 if(!in_array($testType,['vocabulary','multiple_choice','open','mixed'],true))$testType='mixed';
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
     $s=$pdo->prepare("UPDATE tests SET topic_id=?,title=?,description=?,test_type=?,vocab_left_label=?,vocab_right_label=?,vocab_direction=?,shuffle_questions=? WHERE id=?");
     $s->execute([$topicId,$title,$description,$testType,$testType==='vocabulary'?$leftLabel:null,$testType==='vocabulary'?$rightLabel:null,$vocabDirection,$shuffleQuestions,$id]);
     $subjectStmt=$pdo->prepare("SELECT subject_id FROM topics WHERE id=?");
     $subjectStmt->execute([$topicId]);
     $subjectId=(int)$subjectStmt->fetchColumn();
     redirect($subjectId?'subject_manage.php?id='.$subjectId:'admin.php');
   }catch(PDOException $e){
     $error=$e->getCode()==='23000'?'Deze titel bestaat al binnen deze overhoring. Kies een andere titel.':'Opslaan is mislukt. Probeer het opnieuw.';
   }
 }
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sub-Test</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-4" style="max-width:760px"><a href="subject_manage.php?id=<?=isset($test['subject_id'])?(int)$test['subject_id']:''?>">&larr; Terug naar vak</a><div class="card shadow-sm mt-3"><div class="card-body p-4"><h1 class="h3"><?= $id?'Sub-Test bewerken':'Nieuwe sub-test'?></h1><?php if(!empty($error)):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><form method="post"><input type="hidden" name="action" value="save"><label class="form-label">Titel</label><input class="form-control mb-3" name="title" value="<?=e($test['title']??'')?>" required><label class="form-label">Beschrijving</label><textarea class="form-control mb-3" name="description" rows="3"><?=e($test['description']??'')?></textarea><label class="form-label">Type sub-test</label><select class="form-select mb-3" name="test_type"><option value="vocabulary" <?=($test['test_type']??'mixed')==='vocabulary'?'selected':''?>>Woordjes oefenen</option><option value="multiple_choice" <?=($test['test_type']??'mixed')==='multiple_choice'?'selected':''?>>Alleen multiple choice</option><option value="open" <?=($test['test_type']??'mixed')==='open'?'selected':''?>>Alleen open vragen</option><option value="mixed" <?=($test['test_type']??'mixed')==='mixed'?'selected':''?>>Combinatie van multiple choice en open vragen</option></select><div class="form-text mb-3">Bij Woordjes oefenen kun je een woordenlijst importeren en beide richtingen oefenen.</div>
<div id="vocab-settings" class="border rounded p-3 mb-3 <?=($test['test_type']??'mixed')==='vocabulary'?'':'d-none'?>">
<div class="alert alert-secondary py-2">Taal: <strong><?=e($test['subject_name']??'')?> → Nederlands</strong></div>
<label class="form-label">Oefenrichting</label><select class="form-select" name="vocab_direction"><option value="both" <?=($test['vocab_direction']??'both')==='both'?'selected':''?>>Beide richtingen</option><option value="left_to_right" <?=($test['vocab_direction']??'both')==='left_to_right'?'selected':''?>>Alleen eerste → tweede</option><option value="right_to_left" <?=($test['vocab_direction']??'both')==='right_to_left'?'selected':''?>>Alleen tweede → eerste</option></select>
</div>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="shuffle_questions" value="1" id="shuffleQuestions" <?=((int)($test['shuffle_questions']??1)===1)?'checked':''?>><label class="form-check-label" for="shuffleQuestions"><strong>Vragen husselen</strong><br><span class="text-secondary">De vragen worden bij een nieuwe poging in willekeurige volgorde getoond.</span></label></div>
<label class="form-label">Overhoring</label><select class="form-select mb-3" name="topic_id" required><option value="">Kies een overhoring...</option><?php foreach($topics as $topic):?><option value="<?=$topic['id']?>" <?=isset($test['topic_id'])&&(int)$test['topic_id']===(int)$topic['id']?'selected':''?>><?php $subjectName='';foreach($subjects as $subject)if((int)$subject['id']===(int)$topic['subject_id']){$subjectName=$subject['name'];break;}?><?=e($subjectName.' — '.$topic['name'])?></option><?php endforeach;?></select>
<div class="border rounded p-3 mt-4 mb-3"><label class="form-label"><strong>Gegevens importeren</strong></label><div id="vocabHelp" class="small text-secondary mb-2">Woordjes: één woordpaar per regel met <code>=</code>.</div><div id="mcHelp" class="small text-secondary mb-2 d-none">Multiple choice: <code>vraag;juiste_antwoord;antwoord_b;antwoord_c;antwoord_d;uitleg</code></div><div id="mixedHelp" class="small text-secondary mb-2 d-none">Combinatie: <code>mc;vraag;juiste_antwoord;antwoord_b;antwoord_c;antwoord_d;uitleg</code> of <code>open;vraag;juiste_antwoord;;; ;uitleg</code>.</div><textarea class="form-control font-monospace mb-2" name="import_text" rows="8" placeholder=""></textarea><button class="btn btn-outline-primary" type="submit" name="action" value="import">Importeren</button></div>
<div class="d-flex justify-content-between align-items-center gap-2 mt-4"><button class="btn btn-primary" type="submit" name="action" value="save">Opslaan</button><?php if($id):?><button class="btn btn-outline-danger" type="submit" name="action" value="delete" onclick="return confirm('Weet u zeker dat u deze Sub-Test wilt verwijderen? De Sub-Test verdwijnt uit de website, maar blijft in de database bewaard.');">Verwijderen</button><?php endif;?></div>

</form></div></div></main><script>
const typeSelect=document.querySelector('[name="test_type"]'),vocabSettings=document.getElementById('vocab-settings');const vocabHelp=document.getElementById('vocabHelp'),mcHelp=document.getElementById('mcHelp'),mixedHelp=document.getElementById('mixedHelp');
function toggleVocab(){vocabSettings.classList.toggle('d-none',typeSelect.value!=='vocabulary')}
typeSelect.addEventListener('change',toggleVocab);function toggleImport(){const t=typeSelect.value;vocabHelp.classList.toggle('d-none',t!=='vocabulary');mcHelp.classList.toggle('d-none',t!=='multiple_choice');mixedHelp.classList.toggle('d-none',t!=='mixed')}typeSelect.addEventListener('change',toggleImport);toggleImport();
</script></body></html>