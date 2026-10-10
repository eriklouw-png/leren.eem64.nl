<?php
require __DIR__.'/../app/bootstrap.php';require_admin();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);$testId=filter_input(INPUT_GET,'test_id',FILTER_VALIDATE_INT);
if($id){$s=$pdo->prepare("SELECT * FROM questions WHERE id=?");$s->execute([$id]);$q=$s->fetch();if(!$q)exit('Vraag niet gevonden.');$testId=(int)$q['test_id'];$s=$pdo->prepare("SELECT * FROM question_options WHERE question_id=? ORDER BY sort_order,id");$s->execute([$id]);$options=$s->fetchAll();$s=$pdo->prepare("SELECT * FROM open_question_answers WHERE question_id=? ORDER BY sort_order,id");$s->execute([$id]);$openAnswers=$s->fetchAll();}else{$q=null;$options=[['option_text'=>'','is_correct'=>0],['option_text'=>'','is_correct'=>0],['option_text'=>'','is_correct'=>0],['option_text'=>'','is_correct'=>0]];$openAnswers=[['answer_text'=>''],['answer_text'=>'']];}
if(!$testId)redirect('admin.php');
$ownerCheck=$pdo->prepare('SELECT tp.subject_id FROM tests t JOIN topics tp ON tp.id=t.topic_id WHERE t.id=?');
$ownerCheck->execute([$testId]);
require_subject_management((int)$ownerCheck->fetchColumn());
$ts=$pdo->prepare("SELECT test_type,title FROM tests WHERE id=?");$ts->execute([$testId]);$test=$ts->fetch();if(!$test)exit('Toets niet gevonden.');
$testType=$test['test_type']??'mixed';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(($_POST['action']??'')==='delete'){
        if(!$id){http_response_code(400);exit('Ongeldige vraag.');}
        $oldImage=(string)($q['image_path']??'');
        $s=$pdo->prepare("DELETE FROM questions WHERE id=? AND test_id=?");
        $s->execute([$id,$testId]);
        if($oldImage!==''){
            $oldFile=__DIR__.'/uploads/questions/'.basename($oldImage);
            if(is_file($oldFile))@unlink($oldFile);
        }
        redirect('questions.php?test_id='.$testId);
    }
    $text=trim($_POST['question_text']??'');$type=($_POST['question_type']??'multiple_choice')==='open'?'open':'multiple_choice';
    if($testType==='vocabulary')$type='open';
    if($testType==='multiple_choice')$type='multiple_choice';$explanation=trim($_POST['explanation']??'');$sort=max(0,(int)($_POST['sort_order']??0));
    $removeImage=!empty($_POST['remove_image']);
    $uploadPath=null;$uploadError=null;
    if(isset($_FILES['question_image']) && $_FILES['question_image']['error']!==UPLOAD_ERR_NO_FILE){
        $file=$_FILES['question_image'];
        if($file['error']!==UPLOAD_ERR_OK)$uploadError='De afbeelding kon niet worden geüpload.';
        elseif($file['size']>10*1024*1024)$uploadError='De afbeelding mag maximaal 10 MB zijn.';
        elseif(!is_uploaded_file($file['tmp_name']) || @getimagesize($file['tmp_name'])===false)$uploadError='Het bestand is geen geldige afbeelding.';
        else{
            $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($file['tmp_name']);
            $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
            if(!isset($extensions[$mime]))$uploadError='Gebruik JPG, PNG, WebP of GIF.';
            else{
                $dir=__DIR__.'/uploads/questions';
                if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir))$uploadError='De uploadmap kon niet worden aangemaakt.';
                else{
                    $filename=bin2hex(random_bytes(16)).'.'.$extensions[$mime];
                    if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$filename))$uploadError='De afbeelding kon niet worden opgeslagen.';
                    else $uploadPath=$filename;
                }
            }
        }
    }
    $texts=$_POST['option_text']??[];$correct=(int)($_POST['correct']??-1);$answers=array_values(array_filter(array_map('trim',$_POST['open_answer']??[]),fn($v)=>$v!==''));
    if($uploadError)$error=$uploadError;
    elseif($text==='')$error='De vraag is verplicht.';
    elseif($type==='multiple_choice'&&($correct<0||$correct>=count($texts)))$error='Kies één juist antwoord.';
    elseif($type==='open'&&count($answers)===0)$error='Vul minimaal één goed antwoord in.';
    else{
        $pdo->beginTransaction();try{
            if($id){
                $oldImage=$q['image_path']??null;
                $newImage=$uploadPath!==null?$uploadPath:($removeImage?null:$oldImage);
                $s=$pdo->prepare("UPDATE questions SET question_text=?,image_path=?,question_type=?,explanation=?,sort_order=? WHERE id=? AND test_id=?");$s->execute([$text,$newImage,$type,$explanation,$sort,$id,$testId]);$pdo->prepare("DELETE FROM question_options WHERE question_id=?")->execute([$id]);$pdo->prepare("DELETE FROM open_question_answers WHERE question_id=?")->execute([$id]);}
            else{$s=$pdo->prepare("INSERT INTO questions(test_id,question_text,image_path,question_type,explanation,sort_order) VALUES(?,?,?,?,?,?)");$s->execute([$testId,$text,$uploadPath,$type,$explanation,$sort]);$id=(int)$pdo->lastInsertId();$oldImage=null;}
            if($type==='multiple_choice'){
                $s=$pdo->prepare("INSERT INTO question_options(question_id,option_text,is_correct,sort_order) VALUES(?,?,?,?)");
                foreach($texts as $i=>$v){$v=trim($v);if($v!=='')$s->execute([$id,$v,$i===$correct?1:0,$i+1]);}
            }else{
                $s=$pdo->prepare("INSERT INTO open_question_answers(question_id,answer_text,sort_order) VALUES(?,?,?)");
                foreach($answers as $i=>$answer)$s->execute([$id,$answer,$i+1]);
            }
            $pdo->commit();
            if(isset($oldImage) && $oldImage && ($uploadPath!==null || $removeImage)){
                $oldFile=__DIR__.'/uploads/questions/'.basename($oldImage);if(is_file($oldFile))@unlink($oldFile);
            }
            redirect('questions.php?test_id='.$testId);
        }catch(Throwable $e){$pdo->rollBack();throw $e;}
    }
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vraag</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-4"><a href="questions.php?test_id=<?=$testId?>">&larr; Vragen</a><div class="card shadow-sm mt-3"><div class="card-body p-4"><h1 class="h3"><?= $id?'Vraag bewerken':'Nieuwe vraag'?></h1><?php if(!empty($error)):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><form method="post" enctype="multipart/form-data"><label class="form-label">Type vraag</label><?php if($testType==='vocabulary'):?><div class="alert alert-info py-2">Bij een toets van het type <strong>Woordjes oefenen</strong> zijn alle vragen open vragen.</div><?php elseif($testType==='multiple_choice'):?><div class="alert alert-info py-2">Bij een toets van het type <strong>Alleen multiple choice</strong> zijn alle vragen meerkeuzevragen.</div><?php endif;?><select class="form-select mb-3" name="question_type" id="question_type" <?=in_array($testType,['vocabulary','multiple_choice'],true)?'disabled':''?>><option value="multiple_choice" <?=($q['question_type']??'multiple_choice')==='multiple_choice'?'selected':''?>>Meerkeuze</option><option value="open" <?=($q['question_type']??'multiple_choice')==='open'?'selected':''?>>Open vraag</option></select><label class="form-label">Vraag</label><textarea class="form-control mb-3" name="question_text" rows="3" required><?=e($q['question_text']??'')?></textarea><label class="form-label">Afbeelding bij de vraag (optioneel)</label><input class="form-control mb-2" type="file" name="question_image" accept="image/jpeg,image/png,image/webp,image/gif"><div class="form-text mb-3">JPG, PNG, WebP of GIF, maximaal 10 MB.</div><?php if($id):?><div class="alert alert-secondary py-2"><strong>Vraag ID:</strong> <?= (int)$id ?><br><strong>image_path:</strong> <code><?=e((string)($q['image_path']??'NULL'))?></code></div><?php endif;?><?php if(!empty($q['image_path'])):?><div class="mb-3"><img src="question_image.php?id=<?=$id?>" alt="Huidige afbeelding" style="max-width:100%;max-height:280px;border-radius:8px"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_image" id="remove_image" value="1"><label class="form-check-label" for="remove_image">Afbeelding verwijderen</label></div></div><?php endif;?><label class="form-label">Uitleg na beantwoorden (optioneel)</label><textarea class="form-control mb-3" name="explanation" rows="2"><?=e($q['explanation']??'')?></textarea><label class="form-label">Volgorde</label><input class="form-control mb-4" type="number" min="0" name="sort_order" value="<?=e((string)($q['sort_order']??0))?>"><div id="mc-options"><h2 class="h5">Antwoorden</h2><p class="text-secondary">Selecteer precies één juist antwoord.</p><?php foreach($options as $i=>$o):?><div class="input-group mb-2"><span class="input-group-text"><input type="radio" name="correct" value="<?=$i?>" <?=!empty($o['is_correct'])?'checked':''?>></span><input class="form-control" name="option_text[]" value="<?=e($o['option_text']??'')?>" placeholder="Antwoord <?=($i+1)?>"></div><?php endforeach;?></div><div id="open-options" class="d-none"><h2 class="h5">Goede antwoorden</h2><p class="text-secondary">Je kunt meerdere varianten opgeven. Hoofdletters, leestekens, accenten en een kleine typefout worden niet onnodig zwaar bestraft.</p><?php foreach($openAnswers as $a):?><input class="form-control mb-2" name="open_answer[]" value="<?=e($a['answer_text']??'')?>" placeholder="Bijvoorbeeld: Willem van Oranje"><?php endforeach;?><input class="form-control mb-2" name="open_answer[]" placeholder="Extra goed antwoord"></div><button class="btn btn-primary mt-3" type="submit" name="action" value="save">Opslaan</button><?php if($id):?><button class="btn btn-danger mt-3 ms-2" type="submit" name="action" value="delete" data-confirm="Weet u zeker dat u deze vraag wilt verwijderen? De vraag en de bijbehorende antwoorden worden verwijderd.">Verwijderen</button><?php endif;?></form></div></div></main><script>const type=document.getElementById('question_type'),mc=document.getElementById('mc-options'),op=document.getElementById('open-options');function toggle(){const open=type.value==='open';mc.classList.toggle('d-none',open);op.classList.toggle('d-none',!open)}type.addEventListener('change',toggle);toggle();</script></body></html>
