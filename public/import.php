<?php
require __DIR__.'/../app/bootstrap.php';require_admin();
$errors=[];$success=null;$preview=[];$pastePath=null;
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['csv_text'])){
    $csvText=(string)$_POST['csv_text'];
    if(trim($csvText)!==''){
        if(strlen($csvText)>2*1024*1024){
            $errors[]='De geplakte CSV mag maximaal 2 MB zijn.';
        }else{
            $pastePath=tempnam(sys_get_temp_dir(),'leren_csv_');
            if($pastePath===false || file_put_contents($pastePath,$csvText)===false){
                $errors[]='De geplakte CSV kon niet worden verwerkt.';
            }else{
                $_FILES['csv']=[
                    'error'=>UPLOAD_ERR_OK,
                    'size'=>strlen($csvText),
                    'tmp_name'=>$pastePath,
                    'name'=>'geplakte.csv'
                ];
                register_shutdown_function(function() use ($pastePath){if(is_file($pastePath))@unlink($pastePath);});
            }
        }
    }
}
$expected=['vak','overhoring','sub-test','type','vraag','juiste_antwoord','antwoord_b','antwoord_c','antwoord_d','uitleg','afbeelding','actief'];
$shortExpected=['vraag','juiste_antwoord','antwoord_b','antwoord_c','antwoord_d','uitleg','afbeelding'];
$shortImportDefaults=['vak'=>'Algemeen','overhoring'=>'Algemeen','sub-test'=>'Nieuwe toets','type'=>'mc','actief'=>'1'];
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!isset($_FILES['csv'])){
        if(!$errors)$errors[]='Kies een CSV-bestand of plak de CSV-tekst in het invoerveld.';
    }elseif($_FILES['csv']['error']!==UPLOAD_ERR_OK){
        $errors[]='Het CSV-bestand kon niet worden geüpload.';
    }elseif($_FILES['csv']['size']>2*1024*1024){$errors[]='Het CSV-bestand mag maximaal 2 MB zijn.';}
    else{
        $fh=fopen($_FILES['csv']['tmp_name'],'rb');
        $header=fgetcsv($fh,0,';');
        // Strip UTF-8 BOM from the first header field. Excel and many CSV generators add this automatically.
        if(isset($header[0])){$header[0]=preg_replace('/^\\xEF\\xBB\\xBF/','',$header[0]);}
        if(!$header){$errors[]='Het CSV-bestand is leeg.';}
        else{
            $header=array_map(fn($v)=>strtolower(trim((string)$v)),$header);
            if($header!==$expected && $header!==$shortExpected)$errors[]='De kolomvolgorde is ongeldig. Gebruik het volledige formaat of het korte formaat: vraag;juiste_antwoord;antwoord_b;antwoord_c;antwoord_d;uitleg;afbeelding.';
            elseif($header===$shortExpected){
                $expected=$shortExpected;
            }
            else{
                $line=1;
                while(($row=fgetcsv($fh,0,';'))!==false){
                    $line++;
                    if(count($row)===1&&trim((string)$row[0])==='')continue;
                    if(count($row)!==count($expected)){$errors[]="Regel $line heeft ".count($row)." kolommen; verwacht ".count($expected).".";continue;}
                    $row=array_map(fn($v)=>trim((string)$v),$row);$data=array_combine($expected,$row);
                    if($expected===$shortExpected){$data=array_merge($shortImportDefaults,$data);}
                    $type=strtolower($data['type']);
                    if(!in_array($type,['mc','open'],true)){$errors[]="Regel $line: type moet mc of open zijn.";continue;}
                    foreach(['vak','overhoring','sub-test','vraag','juiste_antwoord'] as $field)if($data[$field]==='')$errors[]="Regel $line: '$field' is verplicht.";
                    if($data['afbeelding']!==''){
                        $imagePath=str_replace('\\\\','/',trim($data['afbeelding']));
                        if($imagePath[0]==='/' || str_contains($imagePath,'..') || !preg_match('/^[A-Za-z0-9._\\/-]+$/',$imagePath) || !preg_match('/\\.(?:jpe?g|png|webp|gif)$/i',$imagePath))$errors[]="Regel $line: ongeldige afbeelding. Gebruik bijvoorbeeld grieken/tempel.jpg.";
                        elseif(!is_file(__DIR__.'/uploads/questions/'.$imagePath))$errors[]="Regel $line: afbeelding '$imagePath' bestaat niet in uploads/questions/.";
                    }
                    if($type==='mc'&&($data['antwoord_b']===''||$data['antwoord_c']===''||$data['antwoord_d']===''))$errors[]="Regel $line: bij mc zijn antwoord_b, antwoord_c en antwoord_d verplicht.";
                    if($type==='open'&&($data['antwoord_b']!==''||$data['antwoord_c']!==''||$data['antwoord_d']!==''))$errors[]="Regel $line: bij open mogen antwoord_b/c/d leeg blijven.";
                    $preview[]=['line'=>$line,'type'=>$type,'vak'=>$data['vak'],'overhoring'=>$data['overhoring'],'sub-test'=>$data['sub-test'],'vraag'=>$data['vraag']];
                }
                fclose($fh);
                if(!$errors){
                    $fh=fopen($_FILES['csv']['tmp_name'],'rb');fgetcsv($fh,0,';');
                    $pdo->beginTransaction();
                    try{
                        $subject=$pdo->prepare("INSERT INTO subjects(name) VALUES(?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
                        $topic=$pdo->prepare("INSERT INTO topics(subject_id,name) VALUES(?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
                        $findTest=$pdo->prepare("SELECT id FROM tests WHERE topic_id=? AND title=? LIMIT 1");
                        $createTest=$pdo->prepare("INSERT INTO tests(topic_id,title,description,is_active) VALUES(?,?,?,?)");
                        $updateTest=$pdo->prepare("UPDATE tests SET is_active=? WHERE id=?");
                        $q=$pdo->prepare("INSERT INTO questions(test_id,question_text,image_path,question_type,explanation,sort_order) VALUES(?,?,?,?,?,?)");
                        $opt=$pdo->prepare("INSERT INTO question_options(question_id,option_text,is_correct,sort_order) VALUES(?,?,?,?)");
                        $oa=$pdo->prepare("INSERT INTO open_question_answers(question_id,answer_text,sort_order) VALUES(?,?,?)");
                        $sortByTest=[];
                        while(($row=fgetcsv($fh,0,';'))!==false){
                            if(count($row)!==count($expected))continue;$data=array_combine($expected,array_map(fn($v)=>trim((string)$v),$row));
                            if($expected===$shortExpected){$data=array_merge($shortImportDefaults,$data);}
                            $subject->execute([$data['vak']]);$subjectId=(int)$pdo->lastInsertId();
                            $topic->execute([$subjectId,$data['overhoring']]);$topicId=(int)$pdo->lastInsertId();
                            $active=$data['actief']===''?1:(int)in_array(strtolower($data['actief']),['1','ja','yes','true'],true);
                            $findTest->execute([$topicId,$data['sub-test']]);$existingTest=$findTest->fetchColumn();
                            if($existingTest){$testId=(int)$existingTest;$updateTest->execute([$active,$testId]);}
                            else{$createTest->execute([$topicId,$data['sub-test'],'',$active]);$testId=(int)$pdo->lastInsertId();}
                            $sortByTest[$testId]=($sortByTest[$testId]??0)+1;
                            $imagePath = trim((string)($data['afbeelding'] ?? ''));\n                            $imagePath = str_replace('\\\\', '/', $imagePath);\n                            $q->execute([$testId,$data['vraag'],$imagePath !== '' ? $imagePath : null,$data['type']==='open'?'open':'multiple_choice',$data['uitleg'],$sortByTest[$testId]]);
                            $qid=(int)$pdo->lastInsertId();
                            if($data['type']==='open'){
                                $answers=array_values(array_filter(array_map('trim',explode('|',$data['juiste_antwoord'])),fn($v)=>$v!==''));
                                foreach($answers as $i=>$answer)$oa->execute([$qid,$answer,$i+1]);
                            }else{
                                $opts=[$data['juiste_antwoord'],$data['antwoord_b'],$data['antwoord_c'],$data['antwoord_d']];
                                foreach($opts as $i=>$answer)$opt->execute([$qid,$answer,$i===0?1:0,$i+1]);
                            }
                        }
                        fclose($fh);$pdo->commit();$success=count($preview).' vragen geïmporteerd.';
                    }catch(Throwable $e){$pdo->rollBack();$errors[]='Import mislukt: '.$e->getMessage();}
                }
            }
        }
    }
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Importeren</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-4" style="max-width:1000px"><a href="admin.php">&larr; Beheer</a><div class="card shadow-sm mt-3"><div class="card-body p-4"><div class="d-flex justify-content-between align-items-center"><h1 class="mb-0">Vragen importeren</h1><a class="btn btn-outline-primary" href="vocabulary_import.php">Woordenlijst importeren</a></div><p class="mt-3">CSV met <strong>puntkomma's</strong> als scheidingsteken. De import maakt vak, overhoring en sub-test automatisch aan als ze nog niet bestaan.</p><div class="alert alert-secondary"><strong>Volledig formaat:</strong> <code>vak;overhoring;sub-test;type;vraag;juiste_antwoord;antwoord_b;antwoord_c;antwoord_d;uitleg;afbeelding;actief</code><br><strong>Kort formaat:</strong> <code>vraag;juiste_antwoord;antwoord_b;antwoord_c;antwoord_d;uitleg;afbeelding</code><br><strong>type:</strong> <code>mc</code> of <code>open</code>. Bij een open vraag kun je meerdere goede antwoorden opgeven met <code>|</code>.</div><?php foreach($errors as $error):?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach;?><?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif;?><form method="post" enctype="multipart/form-data">
<label class="form-label"><strong>CSV-bestand</strong></label>
<input class="form-control mb-3" type="file" name="csv" accept=".csv,text/csv">
<div class="text-center text-secondary mb-3">of</div>
<label class="form-label"><strong>CSV rechtstreeks plakken</strong></label>
<textarea class="form-control font-monospace mb-3" name="csv_text" rows="12" placeholder="Plak hier de volledige CSV, inclusief de eerste regel met de kolomnamen..."></textarea>
<div class="form-text mb-3">Gebruik hetzelfde CSV-formaat met puntkomma's als scheidingsteken. Maximaal 2 MB.</div>
<button class="btn btn-primary">CSV importeren</button>
</form><hr><h2 class="h5">Voorbeeld voor ChatGPT</h2><p class="text-secondary">Bij het korte formaat worden vak, overhoring, sub-test en type automatisch ingevuld. Voor jouw klokvragen wordt dus automatisch een multiple-choice toets gemaakt.</p><pre class="bg-light p-3 border">vak;overhoring;sub-test;type;vraag;juiste_antwoord;antwoord_b;antwoord_c;antwoord_d;uitleg;actief
Geschiedenis;De Republiek;Sub-Test 1;mc;Wie was Willem van Oranje?;De leider van de Opstand;Een Franse koning;Een Romeinse keizer;Een Engelse admiraal;;grieken/tempel.jpg;1
Geschiedenis;De Republiek;Sub-Test 1;open;In welk jaar begon de Tachtigjarige Oorlog?;1568;;;;;;1
Geschiedenis;De Republiek;Sub-Test 1;open;Wie wordt ook de Vader des Vaderlands genoemd?;Willem van Oranje|Willem de Zwijger;;;;;;1</pre></div></div></main></body></html>
