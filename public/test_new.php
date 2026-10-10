<?php
require __DIR__.'/../app/bootstrap.php';require_admin();

$topicId=filter_input(INPUT_GET,'topic_id',FILTER_VALIDATE_INT);
$subjectId=filter_input(INPUT_GET,'subject_id',FILTER_VALIDATE_INT);
if(!$topicId && !$subjectId){redirect('admin.php');}

if($topicId){
    $x=$pdo->prepare("SELECT tp.id topic_id,tp.name topic_name,s.id subject_id,s.name FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
    $x->execute([$topicId]);$context=$x->fetch();
    if(!$context){http_response_code(404);exit('Overhoring niet gevonden.');}
    require_subject_management((int)$context['subject_id']);
    $topicId=(int)$context['topic_id'];
    $subjectId=(int)$context['subject_id'];
    $topicName=$context['topic_name'];
    $subject=$context;
}else{
    $x=$pdo->prepare("SELECT id,name FROM subjects WHERE id=?");
    $x->execute([$subjectId]);$subject=$x->fetch();
    if(!$subject){http_response_code(404);exit('Taal niet gevonden.');}
    require_subject_management((int)$subjectId);
    $topicName='Algemeen';
}

function language_labels(string $name):array{
    $n=mb_strtolower(trim($name));
    $map=[
      'spaans'=>['Spaans','Nederlands'],
      'duits'=>['Duits','Nederlands'],
      'frans'=>['Frans','Nederlands'],
      'engels'=>['Engels','Nederlands'],
      'nederlands'=>['Nederlands','Nederlands']
    ];
    return $map[$n]??[$name,'Nederlands'];
}
[$leftLabel,$rightLabel]=language_labels($subject['name']);

$errors=[];$success=null;
$title=trim($_POST['title']??'');
$description=trim($_POST['description']??'');
$testType=$_POST['test_type']??'vocabulary';
$importText=(string)($_POST['import_text']??'');
$replaceExisting=!empty($_POST['replace_existing']);
$shuffleQuestions=isset($_POST['shuffle_questions'])?1:1;

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!in_array($testType,['vocabulary','multiple_choice','open','mixed'],true))$testType='vocabulary';
    if($title==='')$errors[]='Een titel is verplicht.';
    if(!$errors){
        $pdo->beginTransaction();
        try{
            if(!$topicId){
                $topic=$pdo->prepare("INSERT INTO topics(subject_id,name) VALUES(?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
                $topic->execute([$subjectId,'Algemeen']);
                $topicId=(int)$pdo->lastInsertId();
            }

            $existing=$pdo->prepare("SELECT id FROM tests WHERE topic_id=? AND title=? LIMIT 1");
            $existing->execute([$topicId,$title]);
            $existingTest=$existing->fetchColumn();

            if($existingTest){
                if(!$replaceExisting){
                    throw new RuntimeException('Er bestaat al een test met de titel "'.$title.'". Vink "Bestaande toets vervangen" aan als je de inhoud opnieuw wilt importeren.');
                }

                $attempts=$pdo->prepare("SELECT COUNT(*) FROM attempts WHERE test_id=?");
                $attempts->execute([(int)$existingTest]);
                if((int)$attempts->fetchColumn()>0){
                    throw new RuntimeException('De bestaande toets heeft al gemaakte pogingen en kan daarom niet automatisch worden vervangen. Kies een nieuwe titel.');
                }

                $testId=(int)$existingTest;
                $upd=$pdo->prepare("UPDATE tests SET description=?,test_type=?,vocab_left_label=?,vocab_right_label=?,vocab_direction=?,shuffle_questions=?,is_active=1 WHERE id=?");
                $upd->execute([$description,$testType,$testType==='vocabulary'?$leftLabel:null,$testType==='vocabulary'?$rightLabel:null,'both',$shuffleQuestions,$testId]);

                $del=$pdo->prepare("DELETE FROM questions WHERE test_id=?");
                $del->execute([$testId]);
            }else{
                $ins=$pdo->prepare("INSERT INTO tests(topic_id,title,description,test_type,vocab_left_label,vocab_right_label,vocab_direction,is_active) VALUES(?,?,?,?,?,?,?,1)");
                $ins->execute([$topicId,$title,$description,$testType,$testType==='vocabulary'?$leftLabel:null,$testType==='vocabulary'?$rightLabel:null,'both']);
                $testId=(int)$pdo->lastInsertId();
            }

            if(trim($importText)!==''){
                $lines=preg_split('/\R/u',$importText);
                $q=$pdo->prepare("INSERT INTO questions(test_id,question_text,image_path,question_type,explanation,sort_order) VALUES(?,?,?,?,?,?)");
                $opt=$pdo->prepare("INSERT INTO question_options(question_id,option_text,is_correct,sort_order) VALUES(?,?,?,?)");
                $oa=$pdo->prepare("INSERT INTO open_question_answers(question_id,answer_text,sort_order) VALUES(?,?,?)");
                $sort=0;
                foreach($lines as $lineNo=>$line){
                    $line=trim($line);
                    if($line===''||str_starts_with($line,'#'))continue;
                    if($testType==='vocabulary'){
                        $pos=strpos($line,'=');
                        if($pos===false)throw new RuntimeException('Regel '.($lineNo+1).' bevat geen = teken.');
                        $l=trim(substr($line,0,$pos));$r=trim(substr($line,$pos+1));
                        if($l===''||$r==='')throw new RuntimeException('Regel '.($lineNo+1).' moet aan beide kanten tekst bevatten.');
                        $sort++;
                        $q->execute([$testId,$l,null,'open','Vertaal naar '.$rightLabel.'.',$sort]);
                        $qid=(int)$pdo->lastInsertId();$oa->execute([$qid,$r,1]);
                        $sort++;
                        $q->execute([$testId,$r,null,'open','Vertaal naar '.$leftLabel.'.',$sort]);
                        $qid=(int)$pdo->lastInsertId();$oa->execute([$qid,$l,1]);
                    }else{
                        $row=str_getcsv($line,';');
                        if($testType==='multiple_choice'){
                            if(count($row)<6)throw new RuntimeException('Regel '.($lineNo+1).' heeft te weinig kolommen.');
                            [$question,$correct,$b,$c,$d,$explanation,$imagePath]=array_pad(array_map('trim',$row),7,'');
                            if($imagePath!==''){
                                $imagePath=str_replace('\\','/',$imagePath);
                                if($imagePath[0]==='/' || str_contains($imagePath,'..') || !preg_match('/^[A-Za-z0-9._\/-]+$/',$imagePath) || !preg_match('/\.(?:jpe?g|png|webp|gif)$/i',$imagePath) || !is_file(__DIR__.'/uploads/questions/'.$imagePath))throw new RuntimeException('Regel '.($lineNo+1).': afbeelding '.$imagePath.' bestaat niet in uploads/questions/.');
                            }
                            if($question===''||$correct===''||$b===''||$c===''||$d==='')throw new RuntimeException('Regel '.($lineNo+1).' mist een verplicht veld.');
                            $sort++;$q->execute([$testId,$question,$imagePath!==''?$imagePath:null,'multiple_choice',$explanation,$sort]);$qid=(int)$pdo->lastInsertId();
                            foreach([$correct,$b,$c,$d] as $i=>$answer)$opt->execute([$qid,$answer,$i===0?1:0,$i+1]);
                        }else{
                            if(count($row)<7)throw new RuntimeException('Regel '.($lineNo+1).' heeft te weinig kolommen.');
                            [$type,$question,$correct,$b,$c,$d,$explanation,$imagePath]=array_pad(array_map('trim',$row),8,'');
                            if($imagePath!==''){
                                $imagePath=str_replace('\\','/',$imagePath);
                                if($imagePath[0]==='/' || str_contains($imagePath,'..') || !preg_match('/^[A-Za-z0-9._\/-]+$/',$imagePath) || !preg_match('/\.(?:jpe?g|png|webp|gif)$/i',$imagePath) || !is_file(__DIR__.'/uploads/questions/'.$imagePath))throw new RuntimeException('Regel '.($lineNo+1).': afbeelding '.$imagePath.' bestaat niet in uploads/questions/.');
                            }
                            if(!in_array(strtolower($type),['mc','open'],true))throw new RuntimeException('Regel '.($lineNo+1).': type moet mc of open zijn.');
                            if($question===''||$correct==='')throw new RuntimeException('Regel '.($lineNo+1).' mist vraag of juiste antwoord.');
                            $qt=strtolower($type)==='mc'?'multiple_choice':'open';
                            if($qt==='multiple_choice'&&($b===''||$c===''||$d===''))throw new RuntimeException('Regel '.($lineNo+1).': bij mc zijn B, C en D verplicht.');
                            if($qt==='open'&&($b!==''||$c!==''||$d!==''))throw new RuntimeException('Regel '.($lineNo+1).': bij open moeten B/C/D leeg zijn.');
                            $sort++;$q->execute([$testId,$question,$imagePath!==''?$imagePath:null,$qt,$explanation,$sort]);$qid=(int)$pdo->lastInsertId();
                            if($qt==='open'){
                                foreach(array_values(array_filter(array_map('trim',explode('|',$correct)),fn($v)=>$v!=='')) as $i=>$answer)$oa->execute([$qid,$answer,$i+1]);
                            }else{
                                foreach([$correct,$b,$c,$d] as $i=>$answer)$opt->execute([$qid,$answer,$i===0?1:0,$i+1]);
                            }
                        }
                    }
                }
            }
            $pdo->commit();
            redirect('subject_manage.php?id='.$subjectId);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            $errors[]='Opslaan/importeren mislukt: '.$e->getMessage();
        }
    }
}
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nieuwe test</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light"><main class="container py-4">
<a href="subject_manage.php?id=<?=$subjectId?>">&larr; <?=e($subject['name'])?></a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<div class="d-flex justify-content-between align-items-start gap-3"><h1 class="h3 mb-0">Nieuwe test — <?=e($subject['name'])?></h1><a class="btn btn-outline-primary" href="ai_test_generator.php?<?= $topicId ? 'topic_id='.(int)$topicId : 'subject_id='.(int)$subjectId ?>">AI toets maken</a></div>
<div class="small text-secondary mb-3">Overhoring: <strong><?=e($topicName)?></strong></div>
<div class="alert alert-info"><strong>Taal:</strong> <?=e($leftLabel)?> → <?=e($rightLabel)?>. De taal is al bekend en hoeft niet opnieuw te worden ingevuld.</div>
<?php foreach($errors as $error):?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach;?>
<form method="post">
<label class="form-label">Titel</label><input class="form-control mb-3" name="title" value="<?=e($title)?>" required>
<label class="form-label">Beschrijving</label><textarea class="form-control mb-3" name="description" rows="2"><?=e($description)?></textarea>
<label class="form-label">Type test</label>
<select class="form-select mb-3" name="test_type" id="testType">
<option value="vocabulary" <?=$testType==='vocabulary'?'selected':''?>>Woordjes oefenen</option>
<option value="multiple_choice" <?=$testType==='multiple_choice'?'selected':''?>>Multiple choice</option>
<option value="open" <?=$testType==='open'?'selected':''?>>Open vragen</option>
<option value="mixed" <?=$testType==='mixed'?'selected':''?>>Combinatie</option>
</select>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="shuffle_questions" value="1" id="shuffleQuestions" checked><label class="form-check-label" for="shuffleQuestions"><strong>Vragen husselen</strong><br><span class="text-secondary">De vragen worden bij een nieuwe poging in willekeurige volgorde getoond.</span></label></div>
<div class="form-check mb-3">
<input class="form-check-input" type="checkbox" name="replace_existing" value="1" id="replaceExisting" <?=$replaceExisting?'checked':''?>>
<label class="form-check-label" for="replaceExisting"><strong>Bestaande toets met dezelfde titel vervangen</strong><br><span class="text-secondary">De huidige vragen worden vervangen door de geïmporteerde vragen. Dit kan alleen als er nog geen pogingen voor deze toets zijn.</span></label>
</div>
<div id="vocabHelp" class="alert alert-secondary import-help"><strong>Importformaat:</strong> één woordpaar per regel met <code>=</code>.<br><span class="mono"><?=e($leftLabel)?> = <?=e($rightLabel)?></span><br><span class="text-secondary">Er worden beide richtingen aangemaakt.</span></div>
<div id="mcHelp" class="alert alert-secondary import-help d-none"><strong>Importformaat:</strong> één vraag per regel, met <code>;</code> als scheidingsteken:<br><span class="mono">vraag;juiste_antwoord;antwoord_b;antwoord_c;antwoord_d;uitleg;afbeelding</span></div>
<div id="mixedHelp" class="alert alert-secondary import-help d-none"><strong>Importformaat:</strong> één vraag per regel, met <code>;</code> als scheidingsteken:<br><span class="mono">type;vraag;juiste_antwoord;antwoord_b;antwoord_c;antwoord_d;uitleg;afbeelding</span><br><code>type</code> is <code>mc</code> of <code>open</code>. Bij open laat je B/C/D leeg.</div>
<label class="form-label"><strong>Gegevens importeren</strong></label>
<textarea class="form-control mono" name="import_text" id="importText" rows="14" placeholder=""></textarea>
<div class="form-text mb-3">Lege regels en regels die beginnen met # worden overgeslagen.</div>
<button class="btn btn-primary">Test maken</button>
</form>
</div></div></main>
<script>
const sel=document.getElementById('testType'),text=document.getElementById('importText');
const vocabHelp=document.getElementById('vocabHelp'),mcHelp=document.getElementById('mcHelp'),mixedHelp=document.getElementById('mixedHelp');
function updateImport(){
 const t=sel.value;
 vocabHelp.classList.toggle('d-none',t!=='vocabulary');mcHelp.classList.toggle('d-none',t!=='multiple_choice');mixedHelp.classList.toggle('d-none',t!=='mixed');
 text.placeholder=t==='vocabulary'?'<?=e($leftLabel)?> = <?=e($rightLabel)?>\n<?=e($leftLabel)?> = <?=e($rightLabel)?>':t==='multiple_choice'?'Vraag;juiste antwoord;antwoord B;antwoord C;antwoord D;uitleg;afbeelding':'mc;Vraag;juiste antwoord;antwoord B;antwoord C;antwoord D;uitleg;afbeelding';
}
sel.addEventListener('change',updateImport);updateImport();
</script></body></html>