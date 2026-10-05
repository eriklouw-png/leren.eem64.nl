<?php
require __DIR__.'/../app/bootstrap.php';require_admin();

$subjectId=filter_input(INPUT_GET,'subject_id',FILTER_VALIDATE_INT);
if(!$subjectId){redirect('admin.php');}
$x=$pdo->prepare("SELECT id,name,description FROM subjects WHERE id=?");
$x->execute([$subjectId]);$subject=$x->fetch();
if(!$subject){http_response_code(404);exit('Taal niet gevonden.');}

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

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!in_array($testType,['vocabulary','multiple_choice','mixed'],true))$testType='vocabulary';
    if($title==='')$errors[]='Een titel is verplicht.';
    if(!$errors){
        $pdo->beginTransaction();
        try{
            $topic=$pdo->prepare("INSERT INTO topics(subject_id,name) VALUES(?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            $topic->execute([$subjectId,'Algemeen']);
            $topicId=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare("INSERT INTO tests(topic_id,title,description,test_type,vocab_left_label,vocab_right_label,vocab_direction,is_active) VALUES(?,?,?,?,?,?,?,1)");
            $ins->execute([$topicId,$title,$description,$testType,$testType==='vocabulary'?$leftLabel:null,$testType==='vocabulary'?$rightLabel:null,'both']);
            $testId=(int)$pdo->lastInsertId();

            if(trim($importText)!==''){
                $lines=preg_split('/\R/u',$importText);
                $q=$pdo->prepare("INSERT INTO questions(test_id,question_text,question_type,explanation,sort_order) VALUES(?,?,?,?,?)");
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
                        $q->execute([$testId,$l,'open','Vertaal naar '.$rightLabel.'.',$sort]);
                        $qid=(int)$pdo->lastInsertId();$oa->execute([$qid,$r,1]);
                        $sort++;
                        $q->execute([$testId,$r,'open','Vertaal naar '.$leftLabel.'.',$sort]);
                        $qid=(int)$pdo->lastInsertId();$oa->execute([$qid,$l,1]);
                    }else{
                        $row=str_getcsv($line,';');
                        if($testType==='multiple_choice'){
                            if(count($row)<6)throw new RuntimeException('Regel '.($lineNo+1).' heeft te weinig kolommen.');
                            [$question,$correct,$b,$c,$d,$explanation]=array_pad(array_map('trim',$row),6,'');
                            if($question===''||$correct===''||$b===''||$c===''||$d==='')throw new RuntimeException('Regel '.($lineNo+1).' mist een verplicht veld.');
                            $sort++;$q->execute([$testId,$question,'multiple_choice',$explanation,$sort]);$qid=(int)$pdo->lastInsertId();
                            foreach([$correct,$b,$c,$d] as $i=>$answer)$opt->execute([$qid,$answer,$i===0?1:0,$i+1]);
                        }else{
                            if(count($row)<7)throw new RuntimeException('Regel '.($lineNo+1).' heeft te weinig kolommen.');
                            [$type,$question,$correct,$b,$c,$d,$explanation]=array_pad(array_map('trim',$row),7,'');
                            if(!in_array(strtolower($type),['mc','open'],true))throw new RuntimeException('Regel '.($lineNo+1).': type moet mc of open zijn.');
                            if($question===''||$correct==='')throw new RuntimeException('Regel '.($lineNo+1).' mist vraag of juiste antwoord.');
                            $qt=strtolower($type)==='mc'?'multiple_choice':'open';
                            if($qt==='multiple_choice'&&($b===''||$c===''||$d===''))throw new RuntimeException('Regel '.($lineNo+1).': bij mc zijn B, C en D verplicht.');
                            if($qt==='open'&&($b!==''||$c!==''||$d!==''))throw new RuntimeException('Regel '.($lineNo+1).': bij open moeten B, C en D leeg zijn.');
                            $sort++;$q->execute([$testId,$question,$qt,$explanation,$sort]);$qid=(int)$pdo->lastInsertId();
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
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nieuwe toets</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.import-help{font-size:.9rem}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}</style></head>
<body class="bg-light"><main class="container py-4" style="max-width:900px">
<a href="subject_manage.php?id=<?=$subjectId?>">&larr; <?=e($subject['name'])?></a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<h1 class="h3">Nieuwe toets — <?=e($subject['name'])?></h1>
<div class="alert alert-info"><strong>Taal:</strong> <?=e($leftLabel)?> → <?=e($rightLabel)?>. De taal is al bekend en hoeft niet opnieuw te worden ingevuld.</div>
<?php foreach($errors as $error):?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach;?>
<form method="post">
<label class="form-label">Titel</label><input class="form-control mb-3" name="title" value="<?=e($title)?>" required>
<label class="form-label">Beschrijving</label><textarea class="form-control mb-3" name="description" rows="2"><?=e($description)?></textarea>
<label class="form-label">Type toets</label>
<select class="form-select mb-3" name="test_type" id="testType">
<option value="vocabulary" <?=$testType==='vocabulary'?'selected':''?>>Woordjes oefenen</option>
<option value="multiple_choice" <?=$testType==='multiple_choice'?'selected':''?>>Multiple choice</option>
<option value="mixed" <?=$testType==='mixed'?'selected':''?>>Combinatie</option>
</select>
<div id="vocabHelp" class="alert alert-secondary import-help"><strong>Importformaat:</strong> één woordpaar per regel met <code>=</code>.<br><span class="mono"><?=e($leftLabel)?> = <?=e($rightLabel)?></span><br><span class="text-secondary">Er worden beide richtingen aangemaakt.</span></div>
<div id="mcHelp" class="alert alert-secondary import-help d-none"><strong>Importformaat:</strong> één vraag per regel, met <code>;</code> als scheidingsteken:<br><span class="mono">vraag;juiste_antwoord;antwoord_b;antwoord_c;antwoord_d;uitleg</span></div>
<div id="mixedHelp" class="alert alert-secondary import-help d-none"><strong>Importformaat:</strong> één vraag per regel, met <code>;</code> als scheidingsteken:<br><span class="mono">type;vraag;juiste_antwoord;antwoord_b;antwoord_c;antwoord_d;uitleg</span><br><code>type</code> is <code>mc</code> of <code>open</code>. Bij open laat je B/C/D leeg.</div>
<label class="form-label"><strong>Gegevens importeren</strong></label>
<textarea class="form-control mono" name="import_text" id="importText" rows="14" placeholder=""></textarea>
<div class="form-text mb-3">Lege regels en regels die beginnen met # worden overgeslagen.</div>
<button class="btn btn-primary">Toets maken</button>
</form>
</div></div></main>
<script>
const sel=document.getElementById('testType'),text=document.getElementById('importText');
const vocabHelp=document.getElementById('vocabHelp'),mcHelp=document.getElementById('mcHelp'),mixedHelp=document.getElementById('mixedHelp');
function updateImport(){
 const t=sel.value;
 vocabHelp.classList.toggle('d-none',t!=='vocabulary');mcHelp.classList.toggle('d-none',t!=='multiple_choice');mixedHelp.classList.toggle('d-none',t!=='mixed');
 text.placeholder=t==='vocabulary'?'<?=e($leftLabel)?> = <?=e($rightLabel)?>\n<?=e($leftLabel)?> = <?=e($rightLabel)?>':t==='multiple_choice'?'Vraag;juiste antwoord;antwoord B;antwoord C;antwoord D;uitleg':'mc;Vraag;juiste antwoord;antwoord B;antwoord C;antwoord D;uitleg';
}
sel.addEventListener('change',updateImport);updateImport();
</script></body></html>