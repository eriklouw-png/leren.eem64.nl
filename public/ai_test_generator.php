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

function ai_archive_summary_images(int $topicId,int $summaryId,array $paths):array{
    $dir=__DIR__.'/../storage/summaries/'.(int)$topicId.'/'.(int)$summaryId;
    if(!is_dir($dir)&&!@mkdir($dir,0755,true))throw new RuntimeException('De map voor samenvattingspagina’s kon niet worden aangemaakt.');
    $archived=[];
    foreach($paths as $path){
        if(!is_string($path)||!is_file($path))continue;
        $mime=(string)(@mime_content_type($path)?:'');
        $ext=$mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
        $destination=$dir.'/'.date('Ymd_His').'_' .bin2hex(random_bytes(8)).'.'.$ext;
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
            if(count($requestedSpecs)>10){
                $errors[]='Je kunt maximaal tien sub-testen tegelijk genereren.';
            }
            if(!$errors){
                $useSummary=!empty($_POST['use_summary']);
                if($topicId){
                    $topicSummarySetting=$pdo->prepare("UPDATE topics SET use_summary=? WHERE id=?");
                    $topicSummarySetting->execute([$useSummary?1:0,$topicId]);
                }
                $summaryText=null;
                if($useSummary){
                    try{
                        $summaryPrompt='Maak een complete, zelfstandige samenvatting van deze geüploade schoolboekpagina’s voor deze overhoring. Deze samenvatting wordt één afzonderlijke samenvatting binnen de overhoring en mag dus alleen de informatie uit deze nieuwe upload bevatten. Gebruik uitsluitend informatie uit de pagina’s. Neem belangrijke begrippen, namen, processen, voorbeelden en jaartallen mee. Schrijf in duidelijk Nederlands op het niveau van ongeveer 12-15 jaar. Deel de samenvatting logisch op in duidelijke onderwerpen. IEDER nieuw onderwerp moet beginnen met een Markdown-kopje op exact deze manier: "## Onderwerp". Gebruik dus letterlijk twee hekjes, gevolgd door één spatie en daarna de titel van het onderwerp, bijvoorbeeld "## Stofwisseling". Gebruik geen andere Markdown-kopniveaus zoals # of ###. Zet onder ieder kopje de bijbehorende uitleg in korte, duidelijke alinea’s. Verzin niets en vul ontbrekende informatie niet aan.';
                        $summaryData=openai_generate_topic_summary($summaryPrompt,(array)($saved['images']??[]));
                        if(isset($summaryData['_leren_error']))throw new RuntimeException((string)$summaryData['_leren_error']);
                        $summaryJson=openai_output_json($summaryData);
                        $summaryText=trim((string)($summaryJson['summary']??''));
                        if($summaryText==='')throw new RuntimeException('De AI gaf geen bruikbare samenvatting terug.');
                    }catch(Throwable $summaryError){
                        $errors[]='De samenvatting kon niet worden gemaakt: '.$summaryError->getMessage();
                    }
                }
                if(!$errors)$_SESSION['ai_test_analysis']['summary_text']=$summaryText;
                $_SESSION['ai_test_analysis']['request']=['prefix'=>$prefix,'specs'=>$requestedSpecs];
                $requested=[];
                foreach($requestedSpecs as $i=>$spec){
                    $requested[]=['number'=>$i+1,'type'=>$spec['type'],'type_label'=>ai_type_label($spec['type']),'question_count'=>$spec['count']];
                }
                $prompt='Maak nu concrete oefentoetsvragen voor precies deze gevraagde sub-tests. Gebruik uitsluitend de informatie uit de schoolboekpagina’s. Probeer het gevraagde aantal daadwerkelijk te halen, ook als de eerdere analyse een lagere schatting van het aantal unieke vragen gaf. Gebruik de bron zo volledig mogelijk en maak binnen één sub-test verschillende vraagvormen en invalshoeken. Bij grammatica, tabellen, vervoegingen, begrippen en korte teksten mag dezelfde broninformatie meerdere keren worden bevraagd als de vraag wezenlijk anders is. Verzin geen informatie die niet uit de bron volgt; als het gevraagde aantal echt niet haalbaar is zonder verzinnen of vrijwel identieke vragen, maak dan zoveel goede vragen als verantwoord mogelijk en leg dat in de titel/description niet uit maar lever de vragen. Maak exact '.count($requested).' sub-tests met de gevraagde aantallen vragen. De gewenste opdrachten zijn: '.json_encode($requested,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'. Dezelfde leerstof en feiten mogen in verschillende sub-tests opnieuw worden gebruikt: een multiple-choice-sub-test en een open-vragen-sub-test mogen dus inhoudelijk op dezelfde broninformatie zijn gebaseerd. Ook mogen verschillende sub-tests dezelfde leerstof behandelen. Binnen iedere afzonderlijke sub-test moeten de vragen wel voldoende van elkaar verschillen en niet vrijwel identiek zijn. Bepaal per sub-test zelf een korte, duidelijke titel op basis van de leerstof; voeg geen prefix toe en gebruik geen algemene titels zoals "Toets 1" als een inhoudelijke titel mogelijk is. Bij meerdere vergelijkbare sub-tests moeten de titels uniek zijn. Maak per sub-test precies het gevraagde aantal vragen. Bij mc zijn alle vragen multiple choice met exact vier antwoorden en exact één correct antwoord. Bij open zijn alle vragen open en moet accepted_answers minimaal één inhoudelijk geldig antwoord bevatten. Bij combinatie moet je een evenwichtige mix van mc en open maken. Gebruik de broninformatie dus gerust opnieuw in een andere vraagvorm; probeer niet kunstmatig alle sub-tests samen tot één unieke vragenpool te beperken. source_page is de pagina uit de geüploade set waarop de vraag het duidelijkst gebaseerd is. Zet use_image alleen op true als een afbeelding, kaart, schema of foto op die pagina echt relevant is voor het beantwoorden van de vraag.';
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
        if($errors && isset($_SESSION['ai_test_analysis']['generated'])){
            unset($_SESSION['ai_test_analysis']['generated']);
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
                        $summaryName=trim((string)($saved['request']['prefix']??''));
                        $summaryName=$summaryName!==''?$summaryName.' Samenvatting':'Samenvatting';
                        $baseSummaryName=$summaryName;
                        $summarySuffix=2;
                        $summaryCheck=$pdo->prepare("SELECT id FROM topic_summaries WHERE topic_id=? AND name=? AND is_active=1 LIMIT 1");
                        while(true){
                            $summaryCheck->execute([$topicId,$summaryName]);
                            if(!$summaryCheck->fetchColumn())break;
                            $summaryName=$baseSummaryName.' ('.$summarySuffix.')';
                            $summarySuffix++;
                        }
                        $summaryIns=$pdo->prepare("INSERT INTO topic_summaries(topic_id,name,summary,is_active) VALUES(?,?,?,1)");
                        $summaryIns->execute([$topicId,$summaryName,(string)$saved['summary_text']]);
                        $summaryId=(int)$pdo->lastInsertId();
                        ai_archive_summary_images($topicId,$summaryId,$sourceImages);
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
            $prompt='Analyseer de geüploade schoolboekpagina’s voor het maken van oefentoetsen. Identificeer het vak en onderwerp, vat de stof kort samen en geef de belangrijkste leerpunten. Gebruik uitsluitend informatie uit de pagina’s. Bepaal daarnaast zo realistisch mogelijk hoeveel verschillende, inhoudelijk zinvolle vragen maximaal binnen één afzonderlijke sub-test uit deze bron kunnen worden gemaakt zonder leerstof te verzinnen. Kijk daarbij niet alleen naar unieke feiten, maar ook naar verschillende geldige vraagvormen en invalshoeken die de bron daadwerkelijk ondersteunt. Bij grammatica, vervoegingen, woordlijsten en tabellen mag bijvoorbeeld iedere relevante vorm afzonderlijk worden bevraagd en mag dezelfde leerstof worden getoetst via betekenis, persoonsvorm, invulling, herkenning, vertaling, correcte toepassing of een korte contextzin, zolang de vragen voor een leerling inhoudelijk duidelijk van elkaar verschillen. Tel zulke wezenlijk verschillende vraagvormen dus mee. Vermijd alleen vrijwel identieke vragen die alleen enkele woorden omwisselen. Dezelfde leerstof mag in meerdere sub-tests opnieuw worden gebruikt en mag bijvoorbeeld zowel als multiple-choicevraag als als open vraag worden bevraagd. Wees realistisch, maar niet onnodig conservatief: het doel is het maximale aantal goede oefenvragen dat deze specifieke bron daadwerkelijk ondersteunt. Stel ook enkele logische inhoudelijke sub-testtitels voor. Geef alleen JSON volgens het opgegeven schema.';
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
                    $_SESSION['ai_test_analysis']=[
                        'subject_id'=>$subjectId,
                        'topic_id'=>$topicId,
                        'analysis'=>$analysis,
                        'images'=>$valid,
                        'summary_text'=>null,
                        'request'=>['prefix'=>'','specs'=>[]],
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
<style>
.dropzone{border:2px dashed #adb5bd;border-radius:1rem;padding:2.5rem 1.25rem;text-align:center;background:#fff;cursor:pointer;transition:.15s}.dropzone:hover,.dropzone.dragover{border-color:#2f7d4a;background:#f8fbf9}
.upload-icon{font-size:3rem;line-height:1;margin-bottom:1rem}.upload-rules{display:flex;flex-wrap:wrap;justify-content:center;gap:.5rem}.upload-rules span{background:#f1f3f5;border-radius:999px;padding:.35rem .7rem;font-size:.8rem;color:#6c757d}
.upload-file-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.5rem}.upload-file{background:#fff;border:1px solid #dee2e6;border-radius:.6rem;padding:.65rem .75rem;display:flex;align-items:center;gap:.65rem}.upload-file-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ai-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem}.ai-step{background:#e9ecef;border-radius:.6rem;padding:.6rem .75rem;display:flex;align-items:center;gap:.5rem;color:#6c757d}.ai-step span{width:28px;height:28px;border-radius:50%;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700}.ai-step.active{background:#cfe2ff;color:#084298}.ai-step.done{background:#d1e7dd;color:#0f5132}
.generated-test-card{background:#fff;border:1px solid #dee2e6;border-radius:.9rem;overflow:hidden;box-shadow:0 .15rem .5rem rgba(0,0,0,.04)}.generated-test-header{padding:1rem;border-bottom:1px solid #dee2e6;background:#f8f9fa}.generated-question{padding:1rem;border-bottom:1px solid #e9ecef}.generated-question:last-child{border-bottom:0}.question-number{font-weight:700;color:#6c757d}.question-text{font-size:1.02rem;font-weight:600;line-height:1.5}.question-meta{display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap}
@media(max-width:576px){.container{padding-left:.75rem;padding-right:.75rem}.ai-steps{gap:.25rem}.ai-step{justify-content:center;padding:.5rem .25rem}.ai-step strong{display:none}.generated-test-header{padding:.85rem}.generated-question{padding:.85rem}.question-meta{align-items:flex-start;flex-direction:column}}
.ai-loading{position:fixed;inset:0;background:rgba(255,255,255,.88);z-index:9999;display:flex;align-items:center;justify-content:center}.ai-loading-card{background:#fff;border:1px solid #dee2e6;border-radius:1rem;box-shadow:0 .5rem 1.5rem rgba(0,0,0,.12);padding:2rem 2.5rem;text-align:center;min-width:320px}.ai-loading .spinner-border{width:3rem;height:3rem}
</style>
</head><body class="bg-light"><div id="aiLoading" class="ai-loading d-none" aria-live="polite" aria-busy="true"><div class="ai-loading-card"><div class="spinner-border text-primary mb-3" role="status"><span class="visually-hidden">Bezig...</span></div><div id="aiLoadingTitle" class="h5 mb-1">Bezig met AI...</div><div id="aiLoadingText" class="text-secondary">Even geduld.</div></div></div><main class="container py-4" style="max-width:1000px">
<a href="subject_manage.php?id=<?=$subjectId?>">&larr; <?=e($subjectName)?></a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<h1 class="h3 mb-1">AI toets maken</h1>
<div class="text-secondary mb-4">Vak: <strong><?=e($subjectName)?></strong> · Onderwerp: <strong><?=e($topicName)?></strong></div>
<?php foreach($errors as $error):?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach;?>

<?php
$hasGenerated=isset($_SESSION['ai_test_analysis']['generated']['subtests']) && is_array($_SESSION['ai_test_analysis']['generated']['subtests']);
$stage=$hasGenerated?3:($analysis?2:1);
?>
<div class="ai-steps mb-4">
<?php foreach([1=>'Pagina’s',2=>'Instellingen',3=>'Vragen'] as $stepNo=>$stepName):?>
<div class="ai-step <?=$stage===$stepNo?'active':($stage>$stepNo?'done':'')?>"><span><?=$stepNo?></span><strong><?=e($stepName)?></strong></div>
<?php endforeach;?>
</div>

<?php if($stage===1):?>
<div class="upload-intro mb-4">
<h2 class="h4 mb-2">1. Boekpagina’s toevoegen</h2>
<p class="text-secondary mb-0">Upload de relevante pagina’s uit het schoolboek. Op de volgende pagina kies je pas hoe de toets moet worden opgebouwd.</p>
</div>
<form method="post" enctype="multipart/form-data" id="analyzeForm" data-ai-loading="analyze">
<input type="hidden" name="action" value="analyze">
<label class="dropzone d-block mb-3" for="pages" id="dropzone">
<div class="upload-icon">📚</div>
<div class="fw-semibold fs-5">Sleep boekpagina’s hierheen</div>
<div class="text-secondary mt-1">of tik om foto’s te kiezen</div>
<div class="upload-rules mt-3"><span>JPG, PNG of WebP</span><span>Max. 10 pagina’s</span><span>Max. 5 MB per foto</span></div>
<input class="d-none" id="pages" type="file" name="pages[]" accept="image/jpeg,image/png,image/webp" multiple required>
</label>
<div id="fileList" class="upload-file-list mb-4"></div>
<div class="d-flex flex-column flex-sm-row gap-2">
<button class="btn btn-primary btn-lg" type="submit" id="analyzeButton" disabled>Ga naar instellingen</button>
<a class="btn btn-outline-secondary btn-lg" href="subject_manage.php?id=<?=$subjectId?>">Annuleren</a>
</div>
</form>

<?php elseif($stage===2):?>
<div class="analysis-overview card border-0 bg-light mb-4"><div class="card-body">
<div class="small text-uppercase text-secondary fw-semibold mb-1">Analyse voltooid</div>
<h2 class="h4 mb-1"><?=e($analysis['subject'])?> — <?=e($analysis['topic'])?></h2>
<p class="text-secondary mb-3"><?=e($analysis['summary'])?></p>
<?php if(!empty($analysis['learning_points'])):?><div class="small fw-semibold mb-1">Belangrijkste leerpunten</div><ul class="small mb-0 ps-3"><?php foreach(array_slice((array)$analysis['learning_points'],0,6) as $point):?><li><?=e($point)?></li><?php endforeach;?></ul><?php endif;?>
</div></div>
<div class="d-flex justify-content-between align-items-end gap-3 mb-3">
<div><h2 class="h4 mb-1">2. Toets instellen</h2><p class="text-secondary mb-0">Kies hier de instellingen. Je hoeft dit maar één keer te doen.</p></div>
<form method="post"><input type="hidden" name="action" value="clear"><button class="btn btn-outline-secondary btn-sm">Nieuwe foto’s</button></form>
</div>
<form method="post" id="generateForm" data-ai-loading="generate">
<input type="hidden" name="action" value="generate">
<div class="card mb-3"><div class="card-body">
<label class="form-label fw-semibold">Prefix voor de sub-testnaam</label>
<input class="form-control form-control-lg" name="prefix" value="<?=e((string)($savedRequest['prefix']??''))?>" placeholder="Bijvoorbeeld 1.1">
<div class="form-text">De AI maakt de inhoudelijke titel. De prefix wordt ervoor gezet.</div>
</div></div>
<div class="card mb-3"><div class="card-body">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="use_summary" value="1" id="useSummarySettings" <?=$useSummary?'checked':''?>>
<label class="form-check-label fw-semibold" for="useSummarySettings">Samenvatting maken</label>
<div class="small text-secondary mt-1">De AI maakt een aparte leersamenvatting van deze pagina’s.</div>
</div>
</div></div>
<div class="card mb-4"><div class="card-body">
<div class="d-flex justify-content-between align-items-center mb-3"><div><strong>Sub-testen</strong><div class="small text-secondary">Bepaal aantal en vraagtype per sub-test.</div></div><button type="button" class="btn btn-outline-secondary btn-sm" id="addSpecAfter">+ Sub-test</button></div>
<div id="specRowsAfter"></div><div class="small text-secondary mt-2">Totaal gevraagd: <strong id="specTotal">0</strong> vragen.</div>
</div></div>
<button class="btn btn-primary btn-lg w-100" type="submit" id="generateButton">Genereer vragen</button>
</form>

<?php else:?>
<div class="d-flex justify-content-between align-items-center gap-3 mb-4">
<div><h2 class="h4 mb-1">3. Vragen controleren</h2><p class="text-secondary mb-0">Controleer de gegenereerde sub-testen en sla ze daarna op.</p></div>
<form method="post"><input type="hidden" name="action" value="clear"><button class="btn btn-outline-secondary btn-sm">Opnieuw beginnen</button></form>
</div>
<form method="post">
<input type="hidden" name="action" value="save">
<?php foreach($_SESSION['ai_test_analysis']['generated']['subtests'] as $si=>$generatedSub):?>
<div class="generated-test-card mb-4">
<div class="generated-test-header">
<div class="small text-uppercase text-secondary fw-semibold mb-1">Sub-test <?=($si+1)?></div>
<input class="form-control form-control-lg fw-semibold mb-2" name="tests[<?=$si?>][title]" value="<?=e($generatedSub['title'])?>" required>
<input class="form-control" name="tests[<?=$si?>][description]" value="<?=e($generatedSub['description']??'')?>" placeholder="Beschrijving (optioneel)">
</div>
<div class="generated-question-list">
<?php foreach(($generatedSub['questions']??[]) as $qi=>$q):?>
<div class="generated-question">
<div class="d-flex justify-content-between align-items-start gap-2 mb-2"><span class="question-number">Vraag <?=($qi+1)?></span><span class="badge rounded-pill text-bg-light"><?=e($q['type']==='mc'?'Multiple choice':'Open')?></span></div>
<div class="question-text mb-3"><?=e($q['question'])?></div>
<div class="question-meta">
<div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="tests[<?=$si?>][questions][<?=$qi?>][use_image]" value="1" id="img<?=$si?>_<?=$qi?>" <?=$q['use_image']?'checked':''?>><label class="form-check-label small" for="img<?=$si?>_<?=$qi?>">Afbeelding gebruiken</label></div>
<span class="small text-secondary">Bronpagina <?=e((string)($q['source_page']??1))?></span>
</div>
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][type]" value="<?=e($q['type'])?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][source_page]" value="<?=e((string)($q['source_page']??1))?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][correct_answer]" value="<?=e($q['correct_answer']??'')?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][question]" value="<?=e($q['question'])?>">
<?php if($q['type']==='mc'):?><?php foreach(($q['options']??[]) as $oi=>$option):?><input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][options][<?=$oi?>]" value="<?=e($option)?>"><?php endforeach;?><input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][correct_option]" value="<?=e((string)($q['correct_option']??0))?>">
<?php else:?><input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][accepted_answers]" value="<?=e(implode(' | ',(array)($q['accepted_answers']??[$q['correct_answer']??''])))?>"><?php endif;?>
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][explanation]" value="<?=e($q['explanation']??'')?>">
</div>
<?php endforeach;?>
</div></div>
<?php endforeach;?>
<div class="d-flex flex-column flex-sm-row gap-2 mb-3"><button class="btn btn-success btn-lg" type="submit">Opslaan als sub-test<?=count($_SESSION['ai_test_analysis']['generated']['subtests'])===1?'':'s'?></button><button class="btn btn-outline-secondary btn-lg" type="submit" name="action" value="clear" formnovalidate>Opnieuw beginnen</button></div>
</form>
<?php endif;?>

</div></div></main>
<script>
const input=document.getElementById('pages'),list=document.getElementById('fileList'),dropzone=document.getElementById('dropzone'),analyzeButton=document.getElementById('analyzeButton');
function renderFiles(){if(!input||!list)return;const files=[...input.files];list.innerHTML='';if(!files.length){if(analyzeButton)analyzeButton.disabled=true;return;}files.forEach((file,index)=>{const item=document.createElement('div');item.className='upload-file';item.innerHTML='<span>🖼️</span><div class="flex-grow-1 min-w-0"><div class="upload-file-name">'+(index+1)+'. '+file.name.replace(/[<>&"]/g,'')+'</div><div class="small text-secondary">'+Math.round(file.size/1024)+' KB</div></div>';list.appendChild(item)});if(analyzeButton)analyzeButton.disabled=false}
input?.addEventListener('change',renderFiles);dropzone?.addEventListener('dragover',e=>{e.preventDefault();dropzone.classList.add('dragover')});dropzone?.addEventListener('dragleave',()=>dropzone.classList.remove('dragover'));dropzone?.addEventListener('drop',e=>{e.preventDefault();dropzone.classList.remove('dragover');if(input&&e.dataTransfer.files.length){input.files=e.dataTransfer.files;renderFiles()}});
const savedSpecs=<?=json_encode($savedRequest['specs']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;const specDefaults=savedSpecs.length?savedSpecs:[{type:'mixed',count:10}];
function addSpecRow(container,row,index){const wrap=document.createElement('div');wrap.className='row g-2 align-items-end mb-2 spec-row';wrap.innerHTML='<div class="col-5 col-md-3"><label class="form-label small">Aantal</label><input class="form-control" type="number" name="specs['+index+'][count]" min="1" max="100" value="'+(row.count||10)+'" required></div><div class="col-5 col-md-4"><label class="form-label small">Type</label><select class="form-select" name="specs['+index+'][type]"><option value="mc" '+(row.type==='mc'?'selected':'')+'>Multiple choice</option><option value="open" '+(row.type==='open'?'selected':'')+'>Open vragen</option><option value="mixed" '+(row.type==='mixed'?'selected':'')+'>Combinatie</option></select></div><div class="col-2 col-md-2"><button type="button" class="btn btn-outline-danger w-100 remove-spec">×</button></div>';container.appendChild(wrap);wrap.querySelector('.remove-spec').addEventListener('click',()=>{wrap.remove();updateTotals(container)})}
function fillSpecs(container,specs){container.innerHTML='';(specs.length?specs:[{type:'mixed',count:10}]).forEach((row,i)=>addSpecRow(container,row,i));updateTotals(container)}
function updateTotals(container){if(!container)return;let total=0;container.querySelectorAll('input[name$="[count]"]').forEach(el=>total+=Math.max(0,parseInt(el.value||'0',10)));const totalEl=document.getElementById('specTotal');if(totalEl)totalEl.textContent=total}
const afterContainer=document.getElementById('specRowsAfter');if(afterContainer){fillSpecs(afterContainer,specDefaults);document.getElementById('addSpecAfter')?.addEventListener('click',()=>{addSpecRow(afterContainer,{type:'mixed',count:10},afterContainer.children.length);updateTotals(afterContainer)});afterContainer.addEventListener('input',()=>updateTotals(afterContainer));afterContainer.addEventListener('change',()=>updateTotals(afterContainer))}
const aiLoading=document.getElementById('aiLoading'),aiLoadingTitle=document.getElementById('aiLoadingTitle'),aiLoadingText=document.getElementById('aiLoadingText');function showAiLoading(kind){if(!aiLoading)return;if(kind==='analyze'){aiLoadingTitle.textContent='Pagina’s analyseren...';aiLoadingText.textContent='De AI leest de boekpagina’s. Dit kan even duren.'}else{aiLoadingTitle.textContent='Toets maken...';aiLoadingText.textContent='De AI maakt de gevraagde vragen en eventuele samenvatting.'}aiLoading.classList.remove('d-none');document.body.style.overflow='hidden'}
document.getElementById('analyzeForm')?.addEventListener('submit',()=>showAiLoading('analyze'));document.getElementById('generateForm')?.addEventListener('submit',()=>showAiLoading('generate'));
</script></body></html>