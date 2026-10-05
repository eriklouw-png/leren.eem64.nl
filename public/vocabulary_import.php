<?php
require __DIR__.'/../app/bootstrap.php';require_admin();

$tests=$pdo->query("SELECT t.id,t.title,t.vocab_left_label,t.vocab_right_label,t.vocab_direction,s.name subject_name,tp.name topic_name FROM tests t JOIN topics tp ON tp.id=t.topic_id JOIN subjects s ON s.id=tp.subject_id WHERE t.test_type='vocabulary' ORDER BY s.name,tp.name,t.title")->fetchAll();

$errors=[];$success=null;$preview=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    $testId=filter_var($_POST['test_id']??null,FILTER_VALIDATE_INT);
    $text=(string)($_POST['wordlist']??'');
    if(isset($_FILES['wordlist_file']) && $_FILES['wordlist_file']['error']===UPLOAD_ERR_OK){
        if((int)$_FILES['wordlist_file']['size']>2*1024*1024)$errors[]='Het woordenlijstbestand mag maximaal 2 MB zijn.';
        else{$uploaded=file_get_contents($_FILES['wordlist_file']['tmp_name']);if($uploaded!==false)$text=$uploaded;else$errors[]='Het woordenlijstbestand kon niet worden gelezen.';}
    }
    $replace=isset($_POST['replace_existing']);
    $test=null;
    foreach($tests as $t)if((int)$t['id']===$testId){$test=$t;break;}
    if(!$test)$errors[]='Kies een woordjes-sub-test.';
    if(trim($text)==='')$errors[]='Plak eerst een woordenlijst.';
    if(strlen($text)>2*1024*1024)$errors[]='De woordenlijst mag maximaal 2 MB zijn.';
    if(!$errors){
        $lines=preg_split('/\R/u',$text);
        $pairs=[];
        foreach($lines as $lineNo=>$line){
            $line=trim($line);
            if($line===''||str_starts_with($line,'#'))continue;
            $pos=strpos($line,'=');
            if($pos===false){$errors[]='Regel '.($lineNo+1).' bevat geen = teken.';continue;}
            $left=trim(substr($line,0,$pos));$right=trim(substr($line,$pos+1));
            if($left===''||$right===''){$errors[]='Regel '.($lineNo+1).' moet aan beide kanten tekst bevatten.';continue;}
            $pairs[]=['left'=>$left,'right'=>$right,'line'=>$lineNo+1];
        }
        if(!$pairs&&!$errors)$errors[]='Geen woordparen gevonden.';
        if(!$errors){
            $preview=$pairs;
            $pdo->beginTransaction();
            try{
                if($replace){
                    $x=$pdo->prepare("DELETE FROM questions WHERE test_id=?");
                    $x->execute([$testId]);
                }
                $q=$pdo->prepare("INSERT INTO questions(test_id,question_text,question_type,explanation,sort_order) VALUES(?,?,?,?,?)");
                $oa=$pdo->prepare("INSERT INTO open_question_answers(question_id,answer_text,sort_order) VALUES(?,?,?)");
                $next=(int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM questions WHERE test_id=".(int)$testId)->fetchColumn();
                $direction=$test['vocab_direction']??'both';
                $leftLabel=$test['vocab_left_label']?:'Eerste taal';
                $rightLabel=$test['vocab_right_label']?:'Tweede taal';
                foreach($pairs as $pair){
                    $directions=[];
                    if($direction==='both'||$direction==='left_to_right')$directions[]=['prompt'=>$pair['left'],'answer'=>$pair['right'],'from'=>$leftLabel,'to'=>$rightLabel];
                    if($direction==='both'||$direction==='right_to_left')$directions[]=['prompt'=>$pair['right'],'answer'=>$pair['left'],'from'=>$rightLabel,'to'=>$leftLabel];
                    foreach($directions as $d){
                        $next++;
                        $q->execute([$testId,$d['prompt'],'open','Vertaal naar '.$d['to'].'.',$next]);
                        $qid=(int)$pdo->lastInsertId();
                        $oa->execute([$qid,$d['answer'],1]);
                    }
                }
                $pdo->commit();
                $success=count($pairs).' woordparen geïmporteerd als '.count($preview).' basisparen. De sub-test bevat nu de ingestelde oefenrichtingen.';
            }catch(Throwable $e){$pdo->rollBack();$errors[]='Import mislukt: '.$e->getMessage();}
        }
    }
}
?><!doctype html>
<html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Woordenlijst importeren</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4" style="max-width:1000px">
<a href="import.php">&larr; Vragen importeren</a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<h1>Woordenlijst importeren</h1>
<p>Plak een woordenlijst met één woordpaar per regel. Gebruik <code>=</code> als scheidingsteken.</p>
<div class="alert alert-secondary">
<strong>Voorbeeld:</strong><br>
<code>de tafel = der Tisch</code><br>
<code>de stoel = der Stuhl</code><br>
<code>het huis = das Haus</code>
</div>
<?php foreach($errors as $error):?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach;?>
<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif;?>
<form method="post" enctype="multipart/form-data">
<label class="form-label"><strong>Woordjes-sub-test</strong></label>
<select class="form-select mb-3" name="test_id" required>
<option value="">Kies een sub-test...</option>
<?php foreach($tests as $t):?>
<option value="<?=$t['id']?>" <?=((int)($_POST['test_id']??0)===(int)$t['id'])?'selected':''?>><?=e($t['subject_name'].' — '.$t['topic_name'].' — '.$t['title'])?></option>
<?php endforeach;?>
</select>
<label class="form-label"><strong>Woordenlijst plakken</strong></label>
<textarea class="form-control font-monospace mb-3" name="wordlist" rows="16" placeholder="de tafel = der Tisch&#10;de stoel = der Stuhl&#10;het huis = das Haus" required><?=e($_POST['wordlist']??'')?></textarea>
<label class="form-label mt-2"><strong>of een tekstbestand kiezen</strong></label>
<input class="form-control mb-2" type="file" name="wordlist_file" accept=".txt,.csv">
<div class="form-text mb-3">Maximaal 2 MB. Lege regels en regels die beginnen met # worden overgeslagen.</div>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="replace_existing" id="replace_existing"><label class="form-check-label" for="replace_existing"><strong>Bestaande woorden van deze sub-test vervangen</strong><br><span class="text-secondary">Gebruik dit wanneer je een nieuwe volledige woordenlijst importeert.</span></label></div>
<button class="btn btn-primary" type="submit">Woordenlijst importeren</button>
</form>
</div></div></main></body></html>