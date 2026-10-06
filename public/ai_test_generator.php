<?php
require __DIR__.'/../app/bootstrap.php';
require_admin();

$topicId=filter_input(INPUT_GET,'topic_id',FILTER_VALIDATE_INT);
$subjectId=filter_input(INPUT_GET,'subject_id',FILTER_VALIDATE_INT);
if(!$topicId && !$subjectId){redirect('admin.php');}

if($topicId){
    $x=$pdo->prepare("SELECT tp.id topic_id,tp.name topic_name,tp.use_summary,tp.summary,tp.summary_updated_at,s.id subject_id,s.name subject_name FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
    $x->execute([$topicId]);$context=$x->fetch();
    if(!$context){http_response_code(404);exit('Overhoring niet gevonden.');}
    $topicId=(int)$context['topic_id'];$subjectId=(int)$context['subject_id'];
    $topicName=$context['topic_name'];$subjectName=$context['subject_name'];$useSummary=(int)($context['use_summary']??0)===1;
}else{
    $x=$pdo->prepare("SELECT id,name FROM subjects WHERE id=?");
    $x->execute([$subjectId]);$subject=$x->fetch();
    if(!$subject){http_response_code(404);exit('Vak niet gevonden.');}
    $subjectName=$subject['name'];$topicName='Algemeen';$useSummary=false;
}

$errors=[];$analysis=null;
$prefix=trim((string)($_POST['prefix']??''));
$requestedSpecs=$_POST['specs']??[];

function ai_requested_specs(mixed $input):array{
    $result=[];
    if(!is_array($input))return $result;
    foreach($input as $row){
        if(!is_array($row))continue;
        $type=(string)($row['type']??'mixed');
        if(!in_array($type,['mc','open','mixed'],true))$type='mixed';
        $count=(int)($row['count']??0);
        if($count<1)continue;
        $result[]=['type'=>$type,'count'=>min(100,$count)];
        if(count($result)>=10)break;
    }
    return $result;
}
function ai_requested_total(array $specs):int{
    return array_sum(array_map(fn($spec)=>(int)($spec['count']??0),$specs));
}
function ai_type_label(string $type):string{
    return ['mc'=>'Multiple choice','open'=>'Open vragen','mixed'=>'Combinatie'][$type]??'Combinatie';
}
$requestedSpecs=ai_requested_specs($requestedSpecs);

function ai_summary_image_dir(int $topicId):string{
    return __DIR__.'/../storage/summaries/'.(int)$topicId;
}

function ai_archive_summary_images(int $topicId,array $paths):array{
    $dir=ai_summary_image_dir($topicId);
    if(!is_dir($dir)&&!@mkdir($dir,0755,true))throw new RuntimeException('De map voor samenvattingspagina’s kon niet worden aangemaakt.');
    $archived=[];
    foreach($paths as $path){
        if(!is_string($path)||!is_file($path))continue;
        $mime=(string)(@mime_content_type($path)?:'');
        $ext=$mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
        $destination=$dir.'/'.date('Ymd_His').'_'.bin2hex(random_bytes(8)).'.'.$ext;
        if(!@copy($path,$destination))throw new RuntimeException('Een boekpagina kon niet voor de samenvatting worden bewaard.');
        $archived[]=$destination;
    }
    return $archived;
}

function ai_all_summary_images(int $topicId):array{
    $dir=ai_summary_image_dir($topicId);
    if(!is_dir($dir))return [];
    $files=glob($dir.'/*.{jpg,jpeg,png,webp}',GLOB_BRACE);
    if(!$files)return [];
    sort($files,SORT_NATURAL);
    return array_values(array_filter($files,'is_file'));
}

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
        $postedSpecs=ai_requested_specs($_POST['specs']??[]);
        $postedPrefix=trim((string)($_POST['prefix']??''));
        if($postedSpecs)$requestedSpecs=$postedSpecs;
        if($postedPrefix!=='')$prefix=$postedPrefix;
        if(!is_array($saved)||($saved['subject_id']??null)!==$subjectId||($saved['topic_id']??null)!==$topicId){
            $errors[]='De eerdere AI-analyse is verlopen. Analyseer de pagina’s opnieuw.';
        }elseif(!$requestedSpecs){
            $errors[]='Geef minimaal één sub-test op.';
        }else{
            $capacity=max(0,(int)($saved['analysis']['max_unique_questions']??0));
            $requestedTotal=ai_requested_total($requestedSpecs);
            if($capacity<1){
                $errors[]='De AI kon geen betrouwbare maximale hoeveelheid vragen bepalen. Analyseer de pagina’s opnieuw.';
            }elseif(count($requestedSpecs)>10){
                $errors[]='Je kunt maximaal tien sub-testen tegelijk genereren.';
            }else{
                foreach($requestedSpecs as $spec){
                    if((int)$spec['count']>$capacity){
                        $errors[]='Op basis van deze foto’s kunnen maximaal '.$capacity.' verschillende vragen per sub-test worden gemaakt. Je hebt '.$spec['count'].' vragen gevraagd in een sub-test. Verminder het aantal vragen.';
                        break;
                    }
                }
            }
            if(!$errors){
                $_SESSION['ai_test_analysis']['request']=['prefix'=>$prefix,'specs'=>$requestedSpecs];
                $requested=[];
                foreach($requestedSpecs as $i=>$spec){
                    $requested[]=['number'=>$i+1,'type'=>$spec['type'],'type_label'=>ai_type_label($spec['type']),'question_count'=>$spec['count']];
                }
                $prompt='Maak nu concrete oefentoetsvragen voor precies deze gevraagde sub-tests. Gebruik uitsluitend de informatie uit de schoolboekpagina’s. Maak exact '.count($requested).' sub-tests met de gevraagde aantallen vragen. De gewenste opdrachten zijn: '.json_encode($requested,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'. Dezelfde leerstof en feiten mogen in verschillende sub-tests opnieuw worden gebruikt: een multiple-choice-sub-test en een open-vragen-sub-test mogen dus inhoudelijk op dezelfde broninformatie zijn gebaseerd. Ook mogen verschillende sub-tests dezelfde leerstof behandelen. Binnen iedere afzonderlijke sub-test moeten de vragen wel voldoende van elkaar verschillen en niet vrijwel identiek zijn. Bepaal per sub-test zelf een korte, duidelijke titel op basis van de leerstof; voeg geen prefix toe en gebruik geen algemene titels zoals "Toets 1" als een inhoudelijke titel mogelijk is. Bij meerdere vergelijkbare sub-tests moeten de titels uniek zijn. Maak per sub-test precies het gevraagde aantal vragen. Bij mc zijn alle vragen multiple choice met exact vier antwoorden en exact één correct antwoord. Bij open zijn alle vragen open en moet accepted_answers minimaal één inhoudelijk geldig antwoord bevatten. Bij combinatie moet je een evenwichtige mix van mc en open maken. Gebruik de broninformatie dus gerust opnieuw in een andere vraagvorm; probeer niet kunstmatig alle sub-tests samen tot één unieke vragenpool te beperken. source_page is de pagina uit de geüploade set waarop de vraag het duidelijkst gebaseerd is. Zet use_image alleen op true als een afbeelding, kaart, schema of foto op die pagina echt relevant is voor het beantwoorden van de vraag.';
                $data=openai_generate_test_questions($prompt,(array)($saved['images']??[]));
                if(isset($data['_leren_error']))$errors[]=$data['_leren_error'];
                else{
                    $generated=openai_output_json($data);
                    if(!$generated||!isset($generated['subtests'])||!is_array($generated['subtests'])){
                        $reason=(string)($data['incomplete_details']['reason']??'');
                        if(($data['status']??'')==='incomplete' && $reason!==''){
                            $errors[]='De AI-generatie van de vragen werd niet volledig afgerond ('.$reason.'). Verminder eventueel het aantal vragen per sub-test en probeer het opnieuw.';
                        }else{
                            $errors[]='De AI gaf geen bruikbaar JSON-resultaat voor de vragen terug. Probeer dezelfde selectie opnieuw.';
                        }
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
                $rawTitle=trim((string)($test['title']??''));
                $prefixToUse=trim((string)(($saved['request']['prefix']??'')??''));
                $title=$prefixToUse!==''?$prefixToUse.' '.$rawTitle:$rawTitle;
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
                    $testIns=$pdo->prepare("INSERT INTO tests(topic_id,title,description,test_type,vocab_left_label,vocab_right_label,vocab_direction,is_active) VALUES(?,?,?,?,?,?,?,1)");
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
                        $baseTitle=$test['title'];
                        $title=$baseTitle;
                        $suffix=2;
                        while(true){
                            $check->execute([$topicId,$title]);
                            if(!$check->fetchColumn())break;
                            $title=$baseTitle.' ('.$suffix.')';
                            $suffix++;
                        }
                        $testIns->execute([$topicId,$title,$test['description'],$testType,null,null,'both']);
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
                    if($useSummary && !empty($saved['summary_text'])){
                        $summaryUpdate=$pdo->prepare("UPDATE topics SET summary=?,summary_updated_at=NOW() WHERE id=?");
                        $summaryUpdate->execute([(string)$saved['summary_text'],$topicId]);
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
        if($topicId){
            $useSummary=!empty($_POST['use_summary']);
            $topicSummarySetting=$pdo->prepare("UPDATE topics SET use_summary=? WHERE id=?");
            $topicSummarySetting->execute([$useSummary?1:0,$topicId]);
        }
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
            $prompt='Analyseer de geüploade schoolboekpagina’s voor het maken van oefentoetsen. Identificeer het vak en onderwerp, vat de stof kort samen en geef de belangrijkste leerpunten. Gebruik uitsluitend informatie uit de pagina’s. Bepaal daarnaast zo realistisch mogelijk hoeveel verschillende, inhoudelijk zinvolle vragen maximaal binnen één afzonderlijke sub-test uit deze bron kunnen worden gemaakt zonder leerstof te verzinnen of dezelfde vraag onnodig te herhalen. Dezelfde leerstof mag in meerdere sub-tests opnieuw worden gebruikt en mag bijvoorbeeld zowel als multiple-choicevraag als als open vraag worden bevraagd. Wees conservatief binnen één sub-test: tel alleen vragen mee die echt van elkaar verschillen. Stel ook enkele logische inhoudelijke sub-testtitels voor. Geef alleen JSON volgens het opgegeven schema.';
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
                    $reason=(string)($data['incomplete_details']['reason']??'');
                    if(($data['status']??'')==='incomplete' && $reason!==''){
                        $errors[]='De AI-analyse werd niet volledig afgerond ('.$reason.'). Probeer het opnieuw.';
                    }else{
                        $errors[]='De AI gaf geen bruikbaar analyse-resultaat terug. Probeer het opnieuw.';
                    }
                    $analysis=null;
                }else{
                    $summaryText=null;
                    if($useSummary){
                        try{
                            $archived=ai_archive_summary_images($topicId,$valid);
                            $summaryImages=ai_all_summary_images($topicId);
                            $summaryPrompt='Maak één complete, doorlopende samenvatting van alle geüploade schoolboekpagina’s voor deze overhoring. Dit zijn alle bronpagina’s die tot nu toe voor deze overhoring zijn bewaard. Gebruik uitsluitend informatie uit de pagina’s. Neem belangrijke begrippen, namen, processen, voorbeelden en jaartallen mee. Verwijder dubbele informatie waar nodig, maar laat inhoudelijke details niet weg. Schrijf in duidelijk Nederlands op het niveau van ongeveer 12-15 jaar. Gebruik korte kopjes en alinea’s, zodat een leerling de tekst als leersamenvatting kan gebruiken. Verzin niets en vul ontbrekende informatie niet aan.';
                            $summaryData=openai_generate_topic_summary($summaryPrompt,$summaryImages);
                            if(isset($summaryData['_leren_error']))throw new RuntimeException((string)$summaryData['_leren_error']);
                            $summaryJson=openai_output_json($summaryData);
                            $summaryText=trim((string)($summaryJson['summary']??''));
                            if($summaryText==='')throw new RuntimeException('De AI gaf geen bruikbare samenvatting terug.');
                        }catch(Throwable $summaryError){
                            foreach($valid as $path)@unlink($path);
                            $errors[]='De vragenanalyse is gelukt, maar de samenvatting kon niet worden gemaakt: '.$summaryError->getMessage();
                        }
                    }
                    if(!$errors)$_SESSION['ai_test_analysis']=[
                        'subject_id'=>$subjectId,
                        'topic_id'=>$topicId,
                        'analysis'=>$analysis,
                        'images'=>$valid,
                        'summary_text'=>$summaryText,
                        'request'=>['prefix'=>$prefix,'specs'=>$requestedSpecs],
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
$savedRequest=$_SESSION['ai_test_analysis']['request']??['prefix'=>$prefix,'specs'=>$requestedSpecs];
$savedSummaryText=(string)($_SESSION['ai_test_analysis']['summary_text']??'');
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
<div class="alert alert-info">Upload foto’s van de relevante pagina’s uit het boek. Geef meteen aan welke sub-testen je wilt maken. De AI controleert na het analyseren hoeveel verschillende vragen de bron maximaal ondersteunt.</div>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="analyze">
<div class="row g-3 mb-3">
<div class="col-md-6"><label class="form-label fw-semibold">Prefix voor de Sub-Testnaam</label><input class="form-control" name="prefix" value="<?=e($prefix)?>" placeholder="Bijvoorbeeld 1.1"><div class="form-text">De AI levert de inhoudelijke naam; de app zet de prefix ervoor.</div></div>
</div>
<div class="form-check mb-3">
<input class="form-check-input" type="checkbox" name="use_summary" value="1" id="useSummaryUpload" <?=$useSummary?'checked':''?>>
<label class="form-check-label" for="useSummaryUpload"><strong>Samenvatting gebruiken voor deze overhoring</strong><br><span class="text-secondary">Als dit aanstaat, worden deze en toekomstige geüploade boekpagina’s bewaard en samengevoegd tot één samenvatting die leerlingen op de overhoringpagina kunnen lezen.</span></label>
</div>
<div class="card bg-light border-0 mb-3"><div class="card-body">
<div class="d-flex justify-content-between align-items-center mb-2"><strong>Gewenste Sub-Testen</strong><button type="button" class="btn btn-outline-secondary btn-sm" id="addSpec">+ Sub-test</button></div>
<div id="specRows"></div>
<div class="small text-secondary">Bijvoorbeeld: 1 × 40 Multiple choice en 1 × 14 Open vragen, of 3 × 53 Combinatie.</div>
</div></div>
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
<?php if($savedSummaryText!==''):?>
<div class="card mt-4 border-success"><div class="card-body">
<h3 class="h5">Samenvatting</h3>
<div class="small text-secondary mb-2">Deze samenvatting wordt bij het opslaan gekoppeld aan de overhoring.</div>
<div><?=nl2br(e($savedSummaryText))?></div>
</div></div>
<?php endif;?>
<div class="alert alert-success mt-4"><strong>Analyse voltooid.</strong> Op basis van deze foto’s kunnen maximaal <strong><?=e((string)($analysis['max_unique_questions']??0))?> verschillende vragen</strong> worden gemaakt zonder leerstof te verzinnen of vragen onnodig te herhalen.</div>
<h3 class="h5 mt-4">Sub-Testen genereren</h3>
<form method="post" id="generateForm">
<input type="hidden" name="action" value="generate">
<div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label fw-semibold">Prefix voor de Sub-Testnaam</label><input class="form-control" name="prefix" value="<?=e((string)($savedRequest['prefix']??$prefix))?>" placeholder="Bijvoorbeeld 1.1"></div></div>
<div class="card bg-light border-0 mb-3"><div class="card-body">
<div class="d-flex justify-content-between align-items-center mb-2"><strong>Gewenste Sub-Testen</strong><button type="button" class="btn btn-outline-secondary btn-sm" id="addSpecAfter">+ Sub-test</button></div>
<div id="specRowsAfter"></div>
<div class="small text-secondary">Totaal gevraagd: <strong id="specTotal">0</strong> vragen · maximaal beschikbaar: <strong><?=e((string)($analysis['max_unique_questions']??0))?></strong></div>
</div></div>
<button class="btn btn-primary" type="submit" id="generateButton">Genereer vragen met AI</button>
</form>
<?php if(isset($_SESSION['ai_test_analysis']['generated']['subtests'])):?>
<hr class="my-4">
<h3 class="h5">Gegenereerde vragen</h3>
<div class="alert alert-info">Controleer alleen welke vragen en vraagtypes zijn aangemaakt. Je hoeft de inhoud niet meer één voor één te beoordelen. Een aangevinkte afbeelding wordt bij het opslaan aan de vraag gekoppeld.</div>
<form method="post">
<input type="hidden" name="action" value="save">
<?php foreach($_SESSION['ai_test_analysis']['generated']['subtests'] as $si=>$generatedSub):?>
<div class="card mb-4"><div class="card-body">
<div class="row g-2 mb-3">
<div class="col-md-8"><label class="form-label fw-semibold">Naam sub-test</label><input class="form-control" name="tests[<?=$si?>][title]" value="<?=e($generatedSub['title'])?>" required></div>
<div class="col-md-4"><label class="form-label fw-semibold">Beschrijving</label><input class="form-control" name="tests[<?=$si?>][description]" value="<?=e($generatedSub['description']??'')?>"></div>
</div>

<div class="list-group">
<?php foreach(($generatedSub['questions']??[]) as $qi=>$q):?>
<div class="list-group-item">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][type]" value="<?=e($q['type'])?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][source_page]" value="<?=e((string)($q['source_page']??1))?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][correct_answer]" value="<?=e($q['correct_answer']??'')?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][question]" value="<?=e($q['question'])?>">
<?php if($q['type']==='mc'):?>
<?php foreach(($q['options']??[]) as $oi=>$option):?>
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][options][<?=$oi?>]" value="<?=e($option)?>">
<?php endforeach;?>
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][correct_option]" value="<?=e((string)($q['correct_option']??0))?>">
<?php else:?>
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][accepted_answers]" value="<?=e(implode(' | ',(array)($q['accepted_answers']??[$q['correct_answer']??''])))?>">
<?php endif;?>
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][explanation]" value="<?=e($q['explanation']??'')?>">
<div class="d-flex align-items-start gap-3">
<div class="fw-semibold text-secondary" style="min-width:3.5rem">Vraag <?=($qi+1)?></div>
<div class="flex-grow-1"><?=e($q['question'])?></div>
<span class="badge text-bg-light"><?=e($q['type']==='mc'?'Multiple choice':'Open')?></span>
<div class="form-check ms-2">
<input class="form-check-input" type="checkbox" name="tests[<?=$si?>][questions][<?=$qi?>][use_image]" value="1" id="img<?=$si?>_<?=$qi?>" <?=$q['use_image']?'checked':''?>>
<label class="form-check-label" for="img<?=$si?>_<?=$qi?>" title="Gebruik de afbeelding van de bronpagina bij deze vraag">Afbeelding</label>
</div>
</div>
</div>
<?php endforeach;?>
</div>
</div></div>
<?php endforeach;?>
<div class="d-flex gap-2 mb-3"><button class="btn btn-success btn-lg" type="submit">Opslaan als sub-tests</button><button class="btn btn-outline-secondary" type="submit" name="action" value="clear" formnovalidate>Annuleren</button></div>
</form>
<div class="alert alert-success mt-4 mb-0"><strong>Veilige tussenstap:</strong> de analyse en gegenereerde vragen staan alleen in deze sessie. De volgende stap kan de geselecteerde vragen laten aanpassen en pas daarna een nieuwe sub-test in de database aanmaken.</div>
<?php endif;?>
<?php endif;?>
</div></div></main>
<script>
const input=document.getElementById('pages'),list=document.getElementById('fileList');
if(input)input.addEventListener('change',()=>{list.textContent=[...input.files].map(f=>f.name+' ('+Math.round(f.size/1024)+' KB)').join(' · ')});
const initialSpecs=<?=json_encode($requestedSpecs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const savedSpecs=<?=json_encode($savedRequest['specs']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const specDefaults=savedSpecs.length?savedSpecs:(initialSpecs.length?initialSpecs:[{type:'mixed',count:10}]);
function addSpecRow(container,row,index){
 const wrap=document.createElement('div');wrap.className='row g-2 align-items-end mb-2 spec-row';
 wrap.innerHTML='<div class="col-5 col-md-3"><label class="form-label small">Aantal</label><input class="form-control" type="number" name="specs['+index+'][count]" min="1" max="100" value="'+(row.count||10)+'" required></div>'+
 '<div class="col-5 col-md-4"><label class="form-label small">Type</label><select class="form-select" name="specs['+index+'][type]"><option value="mc" '+(row.type==='mc'?'selected':'')+'>Multiple choice</option><option value="open" '+(row.type==='open'?'selected':'')+'>Open vragen</option><option value="mixed" '+(row.type==='mixed'?'selected':'')+'>Combinatie</option></select></div>'+
 '<div class="col-2 col-md-2"><button type="button" class="btn btn-outline-danger w-100 remove-spec">×</button></div>';
 container.appendChild(wrap);
 wrap.querySelector('.remove-spec').addEventListener('click',()=>{wrap.remove();updateTotals(container)});
}
function fillSpecs(container,specs){
 container.innerHTML='';
 (specs.length?specs:[{type:'mixed',count:10}]).forEach((row,i)=>addSpecRow(container,row,i));
 updateTotals(container);
}
function updateTotals(container){
 if(!container)return;
 let total=0;container.querySelectorAll('input[name$="[count]"]').forEach(el=>total+=Math.max(0,parseInt(el.value||'0',10)));
 const totalEl=document.getElementById(container.id==='specRowsAfter'?'specTotal':null);
 if(totalEl)totalEl.textContent=total;
}
const initialContainer=document.getElementById('specRows');
if(initialContainer){
 fillSpecs(initialContainer,specDefaults);
 document.getElementById('addSpec').addEventListener('click',()=>{addSpecRow(initialContainer,{type:'mixed',count:10},initialContainer.children.length);updateTotals(initialContainer)});
}
const afterContainer=document.getElementById('specRowsAfter');
if(afterContainer){
 fillSpecs(afterContainer,specDefaults);
 document.getElementById('addSpecAfter').addEventListener('click',()=>{addSpecRow(afterContainer,{type:'mixed',count:10},afterContainer.children.length);updateTotals(afterContainer)});
 afterContainer.addEventListener('input',()=>updateTotals(afterContainer));
}
</script></body></html>