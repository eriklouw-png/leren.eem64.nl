<?php
require __DIR__.'/../app/bootstrap.php';
require_admin();

$topicId=filter_input(INPUT_GET,'topic_id',FILTER_VALIDATE_INT);
$subjectId=filter_input(INPUT_GET,'subject_id',FILTER_VALIDATE_INT);
if(!$topicId && !$subjectId){redirect('admin.php');}

if($topicId){
    $x=$pdo->prepare("SELECT tp.id topic_id,tp.name topic_name,s.id subject_id,s.name subject_name FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
    $x->execute([$topicId]);$context=$x->fetch();
    if(!$context){http_response_code(404);exit('Overhoring niet gevonden.');}
    $topicId=(int)$context['topic_id'];$subjectId=(int)$context['subject_id'];
    $topicName=$context['topic_name'];$subjectName=$context['subject_name'];
}else{
    $x=$pdo->prepare("SELECT id,name FROM subjects WHERE id=?");
    $x->execute([$subjectId]);$subject=$x->fetch();
    if(!$subject){http_response_code(404);exit('Vak niet gevonden.');}
    $subjectName=$subject['name'];$topicName='Algemeen';
}

$errors=[];$analysis=null;

function ai_cleanup_source_images(array $paths):void{
    $dirs=[];
    foreach($paths as $path){
        if(is_string($path) && is_file($path)){
            $dirs[]=dirname($path);
            @unlink($path);
        }
    }
    foreach(array_unique($dirs) as $dir){
        if(is_dir($dir))@rmdir($dir);
    }
}

function ai_cleanup_session():void{
    if(isset($_SESSION['ai_test_analysis']['images']) && is_array($_SESSION['ai_test_analysis']['images'])){
        ai_cleanup_source_images($_SESSION['ai_test_analysis']['images']);
    }
    unset($_SESSION['ai_test_analysis']);
}
if(isset($_SESSION['ai_test_analysis'])&&is_array($_SESSION['ai_test_analysis'])){
    $saved=$_SESSION['ai_test_analysis'];
    if(($saved['subject_id']??null)===$subjectId && ($saved['topic_id']??null)===$topicId){
        $analysis=$saved['analysis']??null;
    }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    if($action==='clear'){
        ai_cleanup_session();
        redirect('ai_test_generator.php?'.($topicId?'topic_id='.$topicId:'subject_id='.$subjectId));
    }

    if($action==='generate'){
        $saved=$_SESSION['ai_test_analysis']??null;
        $selected=$_POST['subtests']??[];
        if(!is_array($saved)||($saved['subject_id']??null)!==$subjectId||($saved['topic_id']??null)!==$topicId){
            $errors[]='De eerdere AI-analyse is verlopen. Analyseer de pagina’s opnieuw.';
        }elseif(!is_array($selected)||!$selected){
            $errors[]='Selecteer minimaal één sub-test.';
        }else{
            $available=$saved['analysis']['subtests']??[];
            $chosen=[];
            foreach($selected as $index){
                if(!ctype_digit((string)$index))continue;
                $index=(int)$index;
                if(isset($available[$index]))$chosen[]=$available[$index];
            }
            if(!$chosen)$errors[]='De geselecteerde sub-tests zijn ongeldig.';
            if(count($chosen)>3)$errors[]='Je kunt maximaal drie sub-tests tegelijk genereren.';
            if(!$errors){
                $requested=[];
                foreach($chosen as $sub){
                    $count=max(1,min(15,(int)($sub['question_count']??10)));
                    $types=array_values(array_intersect((array)($sub['recommended_types']??[]),['mc','open']));
                    if(!$types)$types=['mc','open'];
                    $requested[]=['title'=>(string)$sub['title'],'description'=>(string)$sub['description'],'question_count'=>$count,'types'=>$types];
                }
                $prompt='Maak nu concrete oefentoetsvragen voor de geselecteerde sub-tests. Gebruik uitsluitend de informatie uit de schoolboekpagina’s. De voorgestelde leerstof en sub-tests zijn: '.json_encode($requested,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'. Maak per sub-test precies het gevraagde aantal vragen. Verdeel MC en open zo logisch mogelijk binnen de voorgestelde types. Bij mc moeten options exact vier antwoorden bevatten en correct_option de index van het juiste antwoord zijn; correct_answer moet exact gelijk zijn aan die optie. Bij open moet options leeg zijn, correct_option 0 zijn en accepted_answers minimaal één geldig antwoord bevatten. source_page is de pagina uit de geüploade set waarop de vraag het duidelijkst gebaseerd is. Zet use_image alleen op true als een afbeelding, kaart, schema of foto op die pagina echt relevant is voor het beantwoorden van de vraag.';
                $data=openai_generate_test_questions($prompt,(array)($saved['images']??[]));
                if(isset($data['_leren_error']))$errors[]=$data['_leren_error'];
                else{
                    $generated=openai_output_json($data);
                    if(!$generated||!isset($generated['subtests'])||!is_array($generated['subtests'])){
                        $errors[]='De AI gaf geen bruikbaar JSON-resultaat voor de vragen terug. Probeer dezelfde selectie opnieuw.';
                    }else{
                        $_SESSION['ai_test_analysis']['generated']=$generated;
                    }
                }
            }
        }
        $analysis=$_SESSION['ai_test_analysis']['analysis']??$analysis;
    }


    if($action==='save'){
        $saved=$_SESSION['ai_test_analysis']??null;
        $posted=$_POST['tests']??[];
        if(!is_array($saved)||!isset($saved['generated']['subtests'])||!is_array($posted)){
            $errors[]='De gegenereerde vragen zijn verlopen. Genereer ze opnieuw.';
        }else{
            $generated=$saved['generated']['subtests'];
            $sourceImages=(array)($saved['images']??[]);
            $validTests=[];
            foreach($posted as $si=>$test){
                if(!isset($generated[$si])||!is_array($test))continue;
                $title=trim((string)($test['title']??''));
                $description=trim((string)($test['description']??''));
                $questions=$test['questions']??[];
                if($title===''){ $errors[]='Elke sub-test moet een titel hebben.'; continue; }
                if(!is_array($questions)||!$questions){$errors[]='Sub-test "'.$title.'" bevat geen vragen.';continue;}
                $validQuestions=[];
                foreach($questions as $qi=>$q){
                    if(!isset($generated[$si]['questions'][$qi])||!is_array($q))continue;
                    $type=(string)($q['type']??'');
                    $question=trim((string)($q['question']??''));
                    $correct=trim((string)($q['correct_answer']??''));
                    $explanation=trim((string)($q['explanation']??''));
                    $sourcePage=max(1,(int)($q['source_page']??1));
                    $useImage=!empty($q['use_image']);
                    if($question===''||$correct===''){$errors[]='Vraag '.($qi+1).' in "'.$title.'" mist vraag of antwoord.';continue;}
                    if(!in_array($type,['mc','open'],true)){$errors[]='Ongeldig vraagtype in "'.$title.'".';continue;}
                    $options=[];
                    if($type==='mc'){
                        $rawOptions=$q['options']??[];
                        if(!is_array($rawOptions)||count($rawOptions)!==4){$errors[]='Multiple-choicevraag '.($qi+1).' in "'.$title.'" moet precies vier antwoorden hebben.';continue;}
                        foreach($rawOptions as $option)$options[]=trim((string)$option);
                        if(in_array('', $options,true)){$errors[]='Een antwoordoptie is leeg in "'.$title.'".';continue;}
                        $correctOption=(int)($q['correct_option']??-1);
                        if($correctOption<0||$correctOption>3){$errors[]='Het juiste antwoord van vraag '.($qi+1).' in "'.$title.'" is ongeldig.';continue;}
                        $correct=$options[$correctOption];
                    }else{
                        $acceptedRaw=$q['accepted_answers']??'';
                        $accepted=is_array($acceptedRaw)?$acceptedRaw:preg_split('/\s*\|\s*/u',(string)$acceptedRaw,-1,PREG_SPLIT_NO_EMPTY);
                        $accepted=array_values(array_filter(array_map(fn($v)=>trim((string)$v),$accepted),fn($v)=>$v!==''));
                        if(!$accepted)$accepted=[$correct];
                        $correct=implode(' | ',$accepted);
                    }
                    $validQuestions[]=['type'=>$type,'question'=>$question,'correct'=>$correct,'options'=>$options,'correct_option'=>$type==='mc'?(int)$q['correct_option']:0,'explanation'=>$explanation,'source_page'=>$sourcePage,'use_image'=>$useImage];
                }
                if($validQuestions)$validTests[]=['title'=>$title,'description'=>$description,'questions'=>$validQuestions];
            }
            if(!$errors&&!$validTests)$errors[]='Er is geen geldige sub-test om op te slaan.';
            if(!$errors){
                $pdo->beginTransaction();
                try{
                    $qIns=$pdo->prepare("INSERT INTO questions(test_id,question_text,image_path,question_type,explanation,sort_order) VALUES(?,?,?,?,?,?)");
                    $optIns=$pdo->prepare("INSERT INTO question_options(question_id,option_text,is_correct,sort_order) VALUES(?,?,?,?)");
                    $oaIns=$pdo->prepare("INSERT INTO open_question_answers(question_id,answer_text,sort_order) VALUES(?,?,?)");
                    $testIns=$pdo->prepare("INSERT INTO tests(topic_id,title,description,test_type,vocab_left_label,vocab_right_label,vocab_direction,is_active) VALUES(?,?,?,?,NULL,NULL,NULL,1)");
                    $savedCount=0;
                    $createdQuestionImages=[];
                    $questionImageDir=__DIR__.'/uploads/questions';
                    if(!is_dir($questionImageDir)&&!@mkdir($questionImageDir,0755,true))throw new RuntimeException('De map uploads/questions kon niet worden aangemaakt.');
                    foreach($validTests as $test){
                        $testType='multiple_choice';
                        $hasOpen=false;$hasMc=false;
                        foreach($test['questions'] as $q){$hasOpen=$hasOpen||$q['type']==='open';$hasMc=$hasMc||$q['type']==='mc';}
                        if($hasOpen&&$hasMc)$testType='mixed';elseif($hasOpen)$testType='open';
                        $check=$pdo->prepare("SELECT id FROM tests WHERE topic_id=? AND title=? LIMIT 1");
                        $check->execute([$topicId,$test['title']]);
                        if($check->fetchColumn())throw new RuntimeException('Er bestaat al een sub-test met de titel "'.$test['title'].'". Pas de titel aan voordat je opslaat.');
                        $testIns->execute([$topicId,$test['title'],$test['description'],$testType]);
                        $testId=(int)$pdo->lastInsertId();
                        foreach($test['questions'] as $sort=>$q){
                            $imagePath=null;
                            if($q['use_image']){
                                $source=$sourceImages[$q['source_page']-1]??null;
                                if($source&&is_file($source)){
                                    $mime=(string)(@mime_content_type($source)?:'');
                                    $ext=$mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
                                    $filename='ai_'.bin2hex(random_bytes(12)).'.'.$ext;
                                    if(!@copy($source,$questionImageDir.'/'.$filename))throw new RuntimeException('Afbeelding kon niet aan een vraag worden gekoppeld.');
                                    $imagePath=$filename;
                                    $createdQuestionImages[]=$questionImageDir.'/'.$filename;
                                }
                            }
                            $qIns->execute([$testId,$q['question'],$imagePath,$q['type']==='mc'?'multiple_choice':'open',$q['explanation'],$sort+1]);
                            $qid=(int)$pdo->lastInsertId();
                            if($q['type']==='mc'){
                                foreach($q['options'] as $oi=>$option)$optIns->execute([$qid,$option,$oi===$q['correct_option']?1:0,$oi+1]);
                            }else{
                                foreach(array_map('trim',explode('|',$q['correct'])) as $ai=>$answer)$oaIns->execute([$qid,$answer,$ai+1]);
                            }
                        }
                        $savedCount++;
                    }
                    $pdo->commit();
                    ai_cleanup_session();
                    redirect('subject_manage.php?id='.$subjectId.'&ai_saved='.$savedCount);
                }catch(Throwable $e){
                    if($pdo->inTransaction())$pdo->rollBack();
                    foreach($createdQuestionImages as $createdImage)if(is_file($createdImage))@unlink($createdImage);
                    $errors[]='Opslaan mislukt: '.$e->getMessage();
                }
            }
        }
        $analysis=$_SESSION['ai_test_analysis']['analysis']??$analysis;
    }

    if($action==='analyze'){
        if(!warm_ai())$errors[]='AI is niet beschikbaar. Controleer OPENAI_API_KEY en de AI-instellingen.';
        $files=$_FILES['pages']??null;
        $valid=[];
        $base=sys_get_temp_dir().'/leren_ai_test_pages';
        if(!is_dir($base)&&!@mkdir($base,0700,true))$errors[]='De tijdelijke opslagmap voor AI-pagina’s kon niet worden aangemaakt.';
        $sessionDir=$base.'/'.bin2hex(random_bytes(16));
        if(!$errors&&!is_dir($sessionDir)&&!@mkdir($sessionDir,0700,true))$errors[]='De tijdelijke opslagmap voor deze AI-analyse kon niet worden aangemaakt.';

        if(!$errors && $files && isset($files['name']) && is_array($files['name'])){
            $allowed=['image/jpeg','image/png','image/webp'];
            foreach($files['name'] as $i=>$name){
                if(count($valid)>=10)break;
                $error=(int)($files['error'][$i]??UPLOAD_ERR_NO_FILE);
                if($error===UPLOAD_ERR_NO_FILE)continue;
                if($error!==UPLOAD_ERR_OK){$errors[]='Een van de afbeeldingen kon niet worden geüpload.';continue;}
                $size=(int)($files['size'][$i]??0);
                if($size<1||$size>5*1024*1024){$errors[]='Elke afbeelding mag maximaal 5 MB zijn.';continue;}
                $tmp=(string)($files['tmp_name'][$i]??'');
                $info=@getimagesize($tmp);
                $mime=(string)($info['mime']??'');
                if(!$info||!in_array($mime,$allowed,true)){$errors[]='Alleen JPG, PNG en WebP-afbeeldingen zijn toegestaan.';continue;}
                $ext=$mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
                $path=$sessionDir.'/'.bin2hex(random_bytes(16)).'.'.$ext;
                if(!@move_uploaded_file($tmp,$path)){$errors[]='Een afbeelding kon niet veilig worden opgeslagen.';continue;}
                $valid[]=$path;
            }
            if(!$valid&&!$errors)$errors[]='Selecteer minimaal één afbeelding.';
        }elseif(!$errors){
            $errors[]='Selecteer minimaal één afbeelding.';
        }

        if(!$errors){
            $prompt='Analyseer de geüploade schoolboekpagina’s en maak een voorstel voor oefentoetsen. Identificeer het vak en onderwerp, vat de stof kort samen, geef de belangrijkste leerpunten en stel logische afzonderlijke sub-tests voor. Gebruik uitsluitend informatie uit de pagina’s. Houd de voorstellen geschikt voor een leerling van ongeveer 12-15 jaar. Een sub-test bevat idealiter 8-15 vragen. Gebruik "mc" voor multiple choice en "open" voor open vragen. Geef alleen JSON volgens het opgegeven schema.';
            $data=openai_generate_with_images($prompt,$valid);
            if(isset($data['_leren_error'])){
                foreach($valid as $path)@unlink($path);
                @rmdir($sessionDir);
                $errors[]=$data['_leren_error'];
            }else{
                $analysis=openai_output_json($data);
                if(!$analysis||!isset($analysis['subtests'])||!is_array($analysis['subtests'])||count($analysis['subtests'])===0){
                    foreach($valid as $path)@unlink($path);
                    @rmdir($sessionDir);
                    $errors[]='De AI gaf geen bruikbaar analyse-resultaat terug.';
                    $analysis=null;
                }else{
                    $_SESSION['ai_test_analysis']=[
                        'subject_id'=>$subjectId,
                        'topic_id'=>$topicId,
                        'analysis'=>$analysis,
                        'images'=>$valid,
                        'created_at'=>time()
                    ];
                }
            }
        }
        if($errors && isset($sessionDir)&&is_dir($sessionDir)){
            foreach($valid as $path)if(is_file($path))@unlink($path);
            @rmdir($sessionDir);
        }
    }
}
?>
<!doctype html>
<html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI toets maken</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.dropzone{border:2px dashed #adb5bd;border-radius:.75rem;padding:2rem;text-align:center;background:#fff;cursor:pointer}.dropzone:hover{border-color:#2f7d4a;background:#f8fbf9}.analysis-card{border-left:4px solid #2f7d4a}</style>
</head><body class="bg-light"><main class="container py-4" style="max-width:1000px">
<a href="subject_manage.php?id=<?=$subjectId?>">&larr; <?=e($subjectName)?></a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<h1 class="h3 mb-1">AI toets maken</h1>
<div class="text-secondary mb-4">Vak: <strong><?=e($subjectName)?></strong> · Onderwerp: <strong><?=e($topicName)?></strong></div>
<?php foreach($errors as $error):?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach;?>

<?php if(!$analysis):?>
<div class="alert alert-info">Upload foto’s van de relevante pagina’s uit het boek. De AI leest de pagina’s en maakt eerst een voorstel. Er wordt nog niets in de database opgeslagen.</div>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="analyze">
<label class="dropzone d-block mb-3" for="pages">
<div class="fs-1">📷</div><strong>Foto’s van boekpagina’s kiezen</strong>
<div class="text-secondary small mt-1">JPG, PNG of WebP · maximaal 10 pagina’s · maximaal 5 MB per afbeelding</div>
<input class="d-none" id="pages" type="file" name="pages[]" accept="image/jpeg,image/png,image/webp" multiple required>
</label>
<div id="fileList" class="small text-secondary mb-3"></div>
<button class="btn btn-primary" type="submit">Analyseer met AI</button>
<a class="btn btn-outline-secondary" href="subject_manage.php?id=<?=$subjectId?>">Annuleren</a>
</form>
<?php else:?>
<div class="d-flex justify-content-between align-items-start gap-3 mb-3">
<div><h2 class="h4 mb-1"><?=e($analysis['subject'])?> — <?=e($analysis['topic'])?></h2><p class="mb-0 text-secondary"><?=e($analysis['summary'])?></p></div>
<form method="post"><input type="hidden" name="action" value="clear"><button class="btn btn-outline-secondary btn-sm">Nieuwe analyse</button></form>
</div>
<h3 class="h5 mt-4">Belangrijkste leerpunten</h3>
<ul><?php foreach(($analysis['learning_points']??[]) as $point):?><li><?=e($point)?></li><?php endforeach;?></ul>
<h3 class="h5 mt-4">Voorgestelde sub-tests</h3>
<form method="post">
<input type="hidden" name="action" value="generate">
<?php foreach(($analysis['subtests']??[]) as $i=>$sub):?>
<label class="card analysis-card mb-3"><div class="card-body">
<div class="form-check">
<input class="form-check-input" type="checkbox" name="subtests[]" value="<?=$i?>" id="subtest<?=$i?>" checked>
<span class="form-check-label d-block" for="subtest<?=$i?>">
<span class="d-flex justify-content-between gap-3"><span><strong><?=e($sub['title'])?></strong><br><span class="text-secondary"><?=e($sub['description'])?></span></span><span class="badge text-bg-light align-self-start"><?=e((string)$sub['question_count'])?> vragen</span></span>
<span class="small text-secondary">Voorgestelde vraagtypes: <?=e(implode(', ',(array)($sub['recommended_types']??[])))?></span>
</span>
</div>
</div></label>
<?php endforeach;?>
<button class="btn btn-primary" type="submit">Genereer geselecteerde vragen met AI</button>
</form>
<?php if(isset($_SESSION['ai_test_analysis']['generated']['subtests'])):?>
<hr class="my-4">
<h3 class="h5">Gegenereerde vragen controleren</h3>
<div class="alert alert-warning">Pas hieronder de teksten, antwoorden en uitleg aan. Er wordt pas iets in de database opgeslagen wanneer je onderaan op <strong>Opslaan als sub-tests</strong> klikt.</div>
<form method="post">
<input type="hidden" name="action" value="save">
<?php foreach($_SESSION['ai_test_analysis']['generated']['subtests'] as $si=>$generatedSub):?>
<div class="card mb-4"><div class="card-body">
<div class="row g-2 mb-3">
<div class="col-md-8"><label class="form-label fw-semibold">Naam sub-test</label><input class="form-control" name="tests[<?=$si?>][title]" value="<?=e($generatedSub['title'])?>" required></div>
<div class="col-md-4"><label class="form-label fw-semibold">Beschrijving</label><input class="form-control" name="tests[<?=$si?>][description]" value="<?=e($generatedSub['description']??'')?>"></div>
</div>
<?php foreach(($generatedSub['questions']??[]) as $qi=>$q):?>
<div class="border-top pt-3 mt-3">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][type]" value="<?=e($q['type'])?>">
<div class="d-flex justify-content-between align-items-center mb-2"><strong>Vraag <?=($qi+1)?></strong><span class="badge text-bg-light"><?=e(strtoupper($q['type']))?></span></div>
<label class="form-label">Vraag</label>
<textarea class="form-control mb-2" name="tests[<?=$si?>][questions][<?=$qi?>][question]" rows="2" required><?=e($q['question'])?></textarea>
<?php if($q['type']==='mc'):?>
<div class="row g-2 mb-2">
<?php foreach(($q['options']??[]) as $oi=>$option):?><div class="col-md-6"><label class="form-label small">Antwoord <?=chr(65+$oi)?></label><input class="form-control" name="tests[<?=$si?>][questions][<?=$qi?>][options][<?=$oi?>]" value="<?=e($option)?>" required></div><?php endforeach;?>
</div>
<label class="form-label">Juiste antwoord</label>
<select class="form-select mb-2" name="tests[<?=$si?>][questions][<?=$qi?>][correct_option]">
<?php foreach(($q['options']??[]) as $oi=>$option):?><option value="<?=$oi?>" <?=$oi===(int)$q['correct_option']?'selected':''?>><?=chr(65+$oi)?> — <?=e($option)?></option><?php endforeach;?>
</select>
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][correct_answer]" value="<?=e($q['correct_answer'])?>">
<?php else:?>
<label class="form-label">Juiste antwoord(en)</label>
<input class="form-control mb-2" name="tests[<?=$si?>][questions][<?=$qi?>][accepted_answers]" value="<?=e(implode(' | ',(array)($q['accepted_answers']??[$q['correct_answer']])))?>" required>
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][correct_answer]" value="<?=e($q['correct_answer'])?>">
<?php endif;?>
<label class="form-label">Uitleg</label>
<textarea class="form-control mb-2" name="tests[<?=$si?>][questions][<?=$qi?>][explanation]" rows="2"><?=e($q['explanation']??'')?></textarea>
<div class="row g-2 align-items-end">
<div class="col-md-4"><label class="form-label small">Bronpagina</label><input class="form-control" type="number" min="1" max="10" name="tests[<?=$si?>][questions][<?=$qi?>][source_page]" value="<?=e((string)($q['source_page']??1))?>"></div>
<div class="col-md-8"><div class="form-check"><input class="form-check-input" type="checkbox" name="tests[<?=$si?>][questions][<?=$qi?>][use_image]" value="1" id="img<?=$si?>_<?=$qi?>" <?=$q['use_image']?'checked':''?>><label class="form-check-label" for="img<?=$si?>_<?=$qi?>">Gebruik afbeelding van deze bronpagina bij deze vraag</label></div></div>
</div>
</div>
<?php endforeach;?>
</div></div>
<?php endforeach;?>
<div class="d-flex gap-2 mb-3"><button class="btn btn-success btn-lg" type="submit">Opslaan als sub-tests</button><button class="btn btn-outline-secondary" type="submit" name="action" value="clear" formnovalidate>Annuleren</button></div>
</form><div class="alert alert-success mt-4 mb-0"><strong>Veilige tussenstap:</strong> de analyse en gegenereerde vragen staan alleen in deze sessie. De volgende stap kan de geselecteerde vragen laten aanpassen en pas daarna een nieuwe sub-test in de database aanmaken.</div>
<?php endif;?>
<?php endif;?>
</div></div></main>
<script>
const input=document.getElementById('pages'),list=document.getElementById('fileList');
if(input)input.addEventListener('change',()=>{list.textContent=[...input.files].map(f=>f.name+' ('+Math.round(f.size/1024)+' KB)').join(' · ')});
</script></body></html>