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
$imageGenerationMode=false;
if(isset($_SESSION['ai_image_jobs'])&&is_array($_SESSION['ai_image_jobs'])&&($_SESSION['ai_image_jobs']['topic_id']??null)===$topicId){$imageGenerationMode=true;}
$prefix=trim((string)($_POST['prefix']??''));
$query=trim((string)($_POST['query']??''));
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
        $query=trim((string)($saved['query']??$query));
    }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    if($action==='clear'){
        unset($_SESSION['ai_image_jobs']);
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
                $useSummary=!empty($_POST['use_summary']) && !empty($saved['images']);

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
                $prompt='Maak nu concrete oefentoetsvragen voor precies deze gevraagde sub-tests. ';
                if($query!=='')$prompt.='De gebruiker gaf de volgende opdracht/het volgende onderwerp: '.$query.'. Gebruik dit als inhoudelijke basis en gebruik algemene kennis. Er zijn geen schoolboekpagina’s aangeleverd. ';
                else $prompt.='Gebruik uitsluitend de informatie uit de schoolboekpagina’s. ';
                $prompt.='Probeer het gevraagde aantal daadwerkelijk te halen, ook als de eerdere analyse een lagere schatting van het aantal unieke vragen gaf. Gebruik de bron zo volledig mogelijk en maak binnen één sub-test verschillende vraagvormen en invalshoeken. ';
                $prompt.='Bij grammatica, tabellen, vervoegingen, begrippen en korte teksten mag dezelfde broninformatie meerdere keren worden bevraagd als de vraag wezenlijk anders is. Verzin geen informatie die niet uit de bron volgt; als het gevraagde aantal echt niet haalbaar is zonder verzinnen of vrijwel identieke vragen, maak dan zoveel goede vragen als verantwoord mogelijk. ';
                $prompt.='Maak exact '.count($requested).' sub-tests met de gevraagde aantallen vragen. De gewenste opdrachten zijn: '.json_encode($requested,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'. ';
                $prompt.='Dezelfde leerstof en feiten mogen in verschillende sub-tests opnieuw worden gebruikt. Binnen iedere afzonderlijke sub-test moeten de vragen voldoende van elkaar verschillen. ';
                $prompt.='Bepaal per sub-test zelf een korte, duidelijke inhoudelijke titel zonder prefix. Bij meerdere vergelijkbare sub-tests moeten de titels uniek zijn. Maak per sub-test precies het gevraagde aantal vragen. ';
                $prompt.='Bij mc zijn alle vragen multiple choice met exact vier antwoorden en exact één correct antwoord. Bij open zijn alle vragen open en moet accepted_answers minimaal één inhoudelijk geldig antwoord bevatten. Bij combinatie moet je een evenwichtige mix van mc en open maken. ';
                $prompt.='Gebruik de broninformatie gerust opnieuw in een andere vraagvorm. source_page is de pagina uit de geüploade set waarop de vraag het duidelijkst gebaseerd is. ';
                $prompt.='Gebruik afbeeldingen zeer spaarzaam: alleen als een afbeelding de vraag inhoudelijk echt helpt of noodzakelijk is. Bepaal voor iedere vraag met use_image=true exact één image_method: none, web, svg of generate. Gebruik none als geen afbeelding nodig is. Gebruik svg voor een eenvoudige, nauwkeurige schematische/vectorafbeelding die je zelf als veilige SVG kunt beschrijven, bijvoorbeeld een tijdlijn, eenvoudige geometrie, diagram, klok, tabelachtige visualisatie of vergelijkbare educatieve illustratie. SVG-kwaliteit is belangrijk: maak geen ruwe schets. Bouw de geometrie bewust en mathematisch op, gebruik bij herhaalde of radiale elementen gelijke afstanden, lijn elementen uit op een logisch raster, houd de compositie gecentreerd en geef rondom voldoende marge. Gebruik exact viewBox=\"0 0 1000 1000\", width=\"1000\" en height=\"1000\". Gebruik consistente lijndiktes, bij voorkeur round linecaps/linejoins, duidelijke contrasten en een rustig sans-serif lettertype. Houd tekst en belangrijke onderdelen volledig binnen de viewBox en maak tekst groot genoeg voor een mobiele telefoon. Gebruik geen onnodige decoratie, 3D-effecten of willekeurige handmatige plaatsing wanneer een berekening mogelijk is. Controleer intern vóór het teruggeven of de SVG volledig gesloten, logisch gecentreerd en visueel leesbaar is. Gebruik web wanneer een echte foto, historische afbeelding, kunstwerk, kaart of andere bestaande bronafbeelding inhoudelijk beter is. Gebruik generate alleen als web en SVG niet geschikt zijn. Geef image_reason kort aan waarom de gekozen methode inhoudelijk passend is. Bij image_method=web geef je een concrete image_search_query en laat je svg_code en image_prompt leeg. Bij image_method=svg geef je complete direct bruikbare svg_code en laat je image_search_query en image_prompt leeg. Bij image_method=generate geef je een zelfstandige image_prompt en laat je image_search_query en svg_code leeg. Bij image_method=none zijn alle drie de afbeeldingsvelden leeg. Bij een query zonder bronpagina’s is source_page altijd 1.';
                $data=openai_generate_test_questions($prompt,(array)($saved['images']??[]),$query!=='' && empty($saved['images']));
                if(isset($data['_leren_error']))$errors[]=$data['_leren_error'];
                else{
                    $generated=openai_output_json($data);
                    if(!$generated||!isset($generated['subtests'])||!is_array($generated['subtests'])||count($generated['subtests'])!==count($requested)){
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
            foreach($posted as $si=>$test){                if(!isset($generated[$si])||!is_array($test))continue;
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
                    $generatedQuestion=$generated[$si]['questions'][$qi];
                    $validQuestions[]=['type'=>$type,'question'=>$question,'correct'=>$correct,'options'=>$options,'correct_option'=>$type==='mc'?(int)$q['correct_option']:0,'explanation'=>$explanation,'source_page'=>$sourcePage,'use_image'=>$useImage,'image_prompt'=>trim((string)($generatedQuestion['image_prompt']??'')),'image_search_query'=>trim((string)($generatedQuestion['image_search_query']??'')),'svg_code'=>trim((string)($generatedQuestion['svg_code']??'')),'image_method'=>trim((string)($generatedQuestion['image_method']??'none')),'image_reason'=>trim((string)($generatedQuestion['image_reason']??''))];
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
                    $pendingImageJobs=[];
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
                                $searchQuery=trim((string)($q['image_search_query']??''));
                                $svgCode=trim((string)($q['svg_code']??''));
                                $imagePrompt=trim((string)($q['image_prompt']??''));
                                $imageMethod=trim((string)($q['image_method']??'none'));
                                if($imageMethod==='none'){
                                    $q['use_image']=false;
                                }elseif(!in_array($imageMethod,['web','svg','generate'],true)){
                                    throw new RuntimeException('Ongeldige afbeeldingsmethode bij een gegenereerde vraag.');
                                }elseif(($imageMethod==='web'&&$searchQuery==='')||($imageMethod==='svg'&&$svgCode==='')||($imageMethod==='generate'&&$imagePrompt==='')){
                                    throw new RuntimeException('De gekozen afbeeldingsmethode heeft geen bijbehorende afbeeldingsdata.');
                                }
                            }
                            $qIns->execute([$testId,$q['question'],$imagePath,$q['type']==='mc'?'multiple_choice':'open',$q['explanation'],$sort+1]);
                            $qid=(int)$pdo->lastInsertId();
                            if($q['use_image'] && $imagePath===null && (string)($q['image_method']??'none')!=='none'){
                                $pendingImageJobs[]=[
                                    'question_id'=>$qid,
                                    'method'=>(string)($q['image_method']??'none'),
                                    'reason'=>(string)($q['image_reason']??''),
                                    'search_query'=>(string)($q['image_search_query']??''),
                                    'svg_code'=>(string)($q['svg_code']??''),
                                    'prompt'=>(string)($q['image_prompt']??'')
                                ];
                            }
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
                    if($pendingImageJobs){
                        $_SESSION['ai_image_jobs']=[
                            'topic_id'=>$topicId,
                            'subject_id'=>$subjectId,
                            'jobs'=>$pendingImageJobs,
                            'total'=>count($pendingImageJobs),
                            'completed'=>0,
                            'saved_count'=>$savedCount
                        ];
                        redirect('ai_test_generator.php?topic_id='.$topicId.'&ai_images=1');
                    }
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
        $query=trim((string)($_POST['query']??''));
        $base=sys_get_temp_dir().'/leren_ai_test_pages';
        $sessionDir=null;

        if(!$errors && $files && isset($files['name']) && is_array($files['name'])){
            if(!is_dir($base)&&!@mkdir($base,0700,true))$errors[]='De tijdelijke opslagmap voor AI-pagina’s kon niet worden aangemaakt.';
            if(!$errors){
                $sessionDir=$base.'/'.bin2hex(random_bytes(16));
                if(!@mkdir($sessionDir,0700,true))$errors[]='De tijdelijke opslagmap voor deze AI-analyse kon niet worden aangemaakt.';
            }
            $allowed=['image/jpeg','image/png','image/webp'];
            if(!$errors)foreach($files['name'] as $i=>$name){
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
        }

        if(!$errors && $query==='' && !$valid){
            $errors[]='Vul een onderwerp/opdracht in of selecteer minimaal één boekpagina.';
        }

        if(!$errors){
            if($query!==''){
                $prompt='Analyseer het onderwerp/de opdracht van de gebruiker voor het maken van een oefentoets. De gebruiker wil de volgende leerstof of opdracht: "'.$query.'". Gebruik algemene kennis om het onderwerp af te bakenen, formuleer de belangrijkste leerpunten en bepaal welke verschillende zinvolle vragen over dit onderwerp kunnen worden gemaakt. Stel enkele logische inhoudelijke sub-testtitels voor. Geef alleen JSON volgens het opgegeven schema.';
                $data=openai_generate_text_analysis($prompt);
            }else{
                $prompt='Analyseer de geüploade schoolboekpagina’s voor het maken van oefentoetsen. Identificeer het vak en onderwerp, vat de stof kort samen en geef de belangrijkste leerpunten. Gebruik uitsluitend informatie uit de pagina’s. Bepaal daarnaast zo realistisch mogelijk hoeveel verschillende, inhoudelijk zinvolle vragen maximaal binnen één afzonderlijke sub-test uit deze bron kunnen worden gemaakt zonder leerstof te verzinnen. Kijk daarbij niet alleen naar unieke feiten, maar ook naar verschillende geldige vraagvormen en invalshoeken die de bron daadwerkelijk ondersteunt. Bij grammatica, vervoegingen, woordlijsten en tabellen mag bijvoorbeeld iedere relevante vorm afzonderlijk worden bevraagd en mag dezelfde leerstof worden getoetst via betekenis, persoonsvorm, invulling, herkenning, vertaling, correcte toepassing of een korte contextzin, zolang de vragen voor een leerling inhoudelijk duidelijk van elkaar verschillen. Tel zulke wezenlijk verschillende vraagvormen dus mee. Vermijd alleen vrijwel identieke vragen die alleen enkele woorden omwisselen. Dezelfde leerstof mag in meerdere sub-tests opnieuw worden gebruikt en mag bijvoorbeeld zowel als multiple-choicevraag als als open vraag worden bevraagd. Wees realistisch, maar niet onnodig conservatief: het doel is het maximale aantal goede oefenvragen dat deze specifieke bron daadwerkelijk ondersteunt. Stel ook enkele logische inhoudelijke sub-testtitels voor. Geef alleen JSON volgens het opgegeven schema.';
                $data=openai_generate_with_images($prompt,$valid);
            }

            if(isset($data['_leren_error'])){
                foreach($valid as $path)@unlink($path);
                if($sessionDir&&is_dir($sessionDir))@rmdir($sessionDir);
                $errors[]=$data['_leren_error'];
            }else{
                $analysis=openai_output_json($data);
                if(!$analysis||!isset($analysis['subtests'])||!is_array($analysis['subtests'])||count($analysis['subtests'])===0){
                    foreach($valid as $path)@unlink($path);                    if($sessionDir&&is_dir($sessionDir))@rmdir($sessionDir);
                    $reason=(string)($data['incomplete_details']['reason']??'');
                    $errors[]=(($data['status']??'')==='incomplete'&&$reason!=='')
                        ? 'De AI-analyse werd niet volledig afgerond ('.$reason.'). Probeer het opnieuw.'
                        : 'De AI gaf geen bruikbaar analyse-resultaat terug. Probeer het opnieuw.';
                    $analysis=null;
                }else{
                    $_SESSION['ai_test_analysis']=[
                        'subject_id'=>$subjectId,
                        'topic_id'=>$topicId,
                        'analysis'=>$analysis,
                        'images'=>$valid,
                        'query'=>$query,
                        'summary_text'=>null,
                        'request'=>['prefix'=>'','specs'=>[]],
                        'created_at'=>time()
                    ];
                }
            }
        }
        if($errors&&$sessionDir&&is_dir($sessionDir)){
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
.generated-test-card{background:#fff;border:1px solid #dee2e6;border-radius:.9rem;overflow:hidden;box-shadow:0 .15rem .5rem rgba(0,0,0,.04)}.generated-test-header{padding:1rem;border-bottom:1px solid #dee2e6;background:#f8f9fa}.generated-question{padding:1rem;border-bottom:1px solid #e9ecef}.generated-question:last-child{border-bottom:0}.question-number{font-weight:700;color:#6c757d}.question-text{font-size:1.02rem;font-weight:600;line-height:1.5}.question-meta{display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap}body.leren-admin .dropzone{background:#252e28;color:#fff;border-color:#56635a}body.leren-admin .dropzone:hover,body.leren-admin .dropzone.dragover{background:#303b34}body.leren-admin .upload-rules span{background:#303b34;color:#cbd5cf}.body.leren-admin .upload-file,body.leren-admin .generated-test-card{background:#202722!important;border-color:#465149!important;color:#fff!important}body.leren-admin .upload-file{background:#252e28!important}body.leren-admin .generated-test-header{background:#252e28!important;border-color:#465149!important}body.leren-admin .generated-question{border-color:#3d4841!important}body.leren-admin .question-number{color:#cbd5cf!important}body.leren-admin .ai-step{background:#252e28;color:#cbd5cf;border:1px solid #465149}body.leren-admin .ai-step span{background:#dce7df;color:#173522}body.leren-admin .ai-step.active{background:#294f38;color:#fff;border-color:#527b60}body.leren-admin .ai-step.done{background:#31583d;color:#fff;border-color:#527b60}body.leren-admin .ai-loading{background:rgba(20,25,22,.94)}body.leren-admin .ai-loading-card{background:#202722;border-color:#465149;color:#fff;box-shadow:0 .5rem 1.5rem rgba(0,0,0,.4)}body.leren-admin .ai-loading-card .text-secondary{color:#cbd5cf!important}
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
$stage=$imageGenerationMode?3:($hasGenerated?3:($analysis?2:1));
?>
<div class="ai-steps mb-4">
<?php foreach([1=>'Pagina’s',2=>'Instellingen',3=>'Vragen'] as $stepNo=>$stepName):?>
<div class="ai-step <?=$stage===$stepNo?'active':($stage>$stepNo?'done':'')?>"><span><?=$stepNo?></span><strong><?=e($stepName)?></strong></div>
<?php endforeach;?>
</div>

<?php if($imageGenerationMode):?>
<div class="ai-image-progress text-center py-5">
<div class="display-5 mb-3">🖼️</div>
<h2 class="h4 mb-2">Afbeeldingen maken</h2>
<p class="text-secondary mb-4">De toets is opgeslagen. Leren maakt nu alleen de afbeeldingen die echt nodig zijn.</p>
<div class="progress mb-3" style="height:12px"><div id="imageProgressBar" class="progress-bar" style="width:0%"></div></div>
<div id="imageProgressText" class="text-secondary">Afbeelding 1 van <?= (int)($_SESSION['ai_image_jobs']['total']??0) ?> wordt gemaakt...</div>
<form method="post" class="mt-4">
<input type="hidden" name="action" value="clear">
<button class="btn btn-outline-secondary btn-lg" type="submit">Annuleren</button>
</form>
</div>
<?php elseif($stage===1):?>
<div class="upload-intro mb-4">
<h2 class="h4 mb-2">1. Toetsbron kiezen</h2>
<p class="text-secondary mb-3">Upload boekpagina’s óf geef hieronder een onderwerp/opdracht. De AI gebruikt daarna de gekozen bron om de toets te maken.</p>
</div>
<form method="post" enctype="multipart/form-data" id="analyzeForm" data-ai-loading="analyze">
<input type="hidden" name="action" value="analyze"><div class="card bg-light border-0 mb-3"><div class="card-body">
<label class="form-label fw-semibold" for="query">Onderwerp of opdracht</label>
<textarea class="form-control" id="query" name="query" rows="3" placeholder="Bijvoorbeeld: Maak een toets over de Griekse stadstaten, met aandacht voor Athene, Sparta en de democratie."><?=e($query)?></textarea>
<div class="form-text">Je kunt hier ook precies beschrijven wat je wilt oefenen. Zonder boekpagina’s gebruikt de AI haar algemene kennis.</div>
</div></div>
<label class="dropzone d-block mb-3" for="pages" id="dropzone">
<div class="upload-icon">📚</div>
<div class="fw-semibold fs-5">Sleep boekpagina’s hierheen</div>
<div class="text-secondary mt-1">of tik om foto’s te kiezen</div>
<div class="upload-rules mt-3"><span>JPG, PNG of WebP</span><span>Max. 10 pagina’s</span><span>Max. 5 MB per foto</span></div>
<input class="d-none" id="pages" type="file" name="pages[]" accept="image/jpeg,image/png,image/webp" multiple>
</label>
<div id="fileList" class="upload-file-list mb-4"></div>
<div class="d-flex flex-column flex-sm-row gap-2">
<button class="btn btn-primary btn-lg" type="submit" id="analyzeButton">Start met verwerken</button>
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
<div class="mb-3">
<h2 class="h4 mb-1">2. Toets instellen</h2><p class="text-secondary mb-0">Kies hier de instellingen. Je hoeft dit maar één keer te doen.</p>
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
<input class="form-check-input" type="checkbox" name="use_summary" value="1" id="useSummarySettings" <?=$useSummary?'checked':''?> <?=($query!==''?'disabled':'')?>>
<label class="form-check-label fw-semibold" for="useSummarySettings">Samenvatting maken</label>
<div class="small text-secondary mt-1"><?=($query!==''?'Bij een query-toets wordt de samenvatting overgeslagen; er zijn geen boekpagina’s als bron.':'De AI maakt een aparte leersamenvatting van deze pagina’s.')?></div>
</div>
</div></div>
<div class="card mb-4"><div class="card-body">
<div class="d-flex justify-content-between align-items-center mb-3"><div><strong>Sub-testen</strong><div class="small text-secondary">Bepaal aantal en vraagtype per sub-test.</div></div><button type="button" class="btn btn-outline-secondary btn-sm" id="addSpecAfter">+ Sub-test</button></div>
<div id="specRowsAfter"></div><div class="small text-secondary mt-2">Totaal gevraagd: <strong id="specTotal">0</strong> vragen.</div>
</div></div>
<div class="d-flex flex-column flex-sm-row-reverse gap-2">
<button class="btn btn-primary btn-lg flex-grow-1" type="submit" id="generateButton">Genereer vragen</button>
</form>
<form method="post" class="m-0">
<input type="hidden" name="action" value="clear">
<button class="btn btn-outline-secondary btn-lg w-100" type="submit">Annuleren</button>
</form>
</div>
</form>

<?php else:?>
<div class="mb-4">
<h2 class="h4 mb-1">3. Vragen controleren</h2><p class="text-secondary mb-0">Controleer de gegenereerde sub-testen en sla ze daarna op.</p>
</div>
<form method="post" id="saveForm">
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
<div>
<div class="small fw-semibold mb-1">Afbeelding bij deze vraag</div>
<div class="d-flex flex-wrap gap-3">
<div class="form-check">
<input class="form-check-input" type="radio" name="tests[<?=$si?>][questions][<?=$qi?>][use_image]" value="0" id="imgNo<?=$si?>_<?=$qi?>" <?=(!$q['use_image']||($q['image_method']??'none')==='none')?'checked':''?>>
<label class="form-check-label small" for="imgNo<?=$si?>_<?=$qi?>">Geen afbeelding</label>
</div>
<div class="form-check">
<input class="form-check-input" type="radio" name="tests[<?=$si?>][questions][<?=$qi?>][use_image]" value="1" id="imgYes<?=$si?>_<?=$qi?>" <?=($q['use_image']&&($q['image_method']??'none')!=='none')?'checked':''?> <?=($q['image_method']??'none')==='none'?'disabled':''?>>
<label class="form-check-label small" for="imgYes<?=$si?>_<?=$qi?>">Afbeelding gebruiken</label>
</div>
</div>
<div class="small text-secondary mt-1">
<?php $methodLabel=['web'=>'Internetafbeelding','svg'=>'SVG','generate'=>'GPT-afbeelding','none'=>'Geen afbeelding'][$q['image_method']??'none']??'Onbekend';?>
Voorgestelde methode: <?=e($methodLabel)?><?=($q['image_reason']??'')!==''?' · '.e((string)$q['image_reason']):''?>
</div>
</div>
<span class="small text-secondary">Bronpagina <?=e((string)($q['source_page']??1))?></span>
</div>
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][type]" value="<?=e($q['type'])?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][source_page]" value="<?=e((string)($q['source_page']??1))?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][image_prompt]" value="<?=e((string)($q['image_prompt']??''))?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][image_search_query]" value="<?=e((string)($q['image_search_query']??''))?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][svg_code]" value="<?=e((string)($q['svg_code']??''))?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][image_method]" value="<?=e((string)($q['image_method']??'none'))?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][image_reason]" value="<?=e((string)($q['image_reason']??''))?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][correct_answer]" value="<?=e($q['correct_answer']??'')?>">
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][question]" value="<?=e($q['question'])?>">
<?php if($q['type']==='mc'):?><?php foreach(($q['options']??[]) as $oi=>$option):?><input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][options][<?=$oi?>]" value="<?=e($option)?>"><?php endforeach;?><input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][correct_option]" value="<?=e((string)($q['correct_option']??0))?>">
<?php else:?><input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][accepted_answers]" value="<?=e(implode(' | ',(array)($q['accepted_answers']??[$q['correct_answer']??''])))?>"><?php endif;?>
<input type="hidden" name="tests[<?=$si?>][questions][<?=$qi?>][explanation]" value="<?=e($q['explanation']??'')?>">
</div>
<?php endforeach;?>
</div></div>
<?php endforeach;?>
<div class="d-flex flex-column flex-sm-row gap-2 mb-3"><button class="btn btn-success btn-lg" type="submit">Opslaan als sub-test<?=count($_SESSION['ai_test_analysis']['generated']['subtests'])===1?'':'s'?></button><button class="btn btn-outline-secondary btn-lg" type="submit" name="action" value="clear" formnovalidate>Annuleren</button></div>
</form>
<?php endif;?>

</div></div></main>
<script>
const input=document.getElementById('pages'),list=document.getElementById('fileList'),dropzone=document.getElementById('dropzone'),analyzeButton=document.getElementById('analyzeButton');
function renderFiles(){if(!input||!list)return;const files=[...input.files];list.innerHTML='';if(!files.length){if(analyzeButton)analyzeButton.disabled=true;return;}files.forEach((file,index)=>{const item=document.createElement('div');item.className='upload-file';item.innerHTML='<span>🖼️</span><div class="flex-grow-1 min-w-0"><div class="upload-file-name">'+(index+1)+'. '+file.name.replace(/[<>&"]/g,'')+'</div><div class="small text-secondary">'+Math.round(file.size/1024)+' KB</div></div>';list.appendChild(item)});if(analyzeButton)analyzeButton.disabled=false}
input?.addEventListener('change',renderFiles);
document.getElementById('query')?.addEventListener('input',()=>{const q=document.getElementById('query').value.trim();if(analyzeButton)analyzeButton.disabled=!q && !(input?.files?.length);});dropzone?.addEventListener('dragover',e=>{e.preventDefault();dropzone.classList.add('dragover')});dropzone?.addEventListener('dragleave',()=>dropzone.classList.remove('dragover'));dropzone?.addEventListener('drop',e=>{e.preventDefault();dropzone.classList.remove('dragover');if(input&&e.dataTransfer.files.length){input.files=e.dataTransfer.files;renderFiles()}});
const savedSpecs=<?=json_encode($savedRequest['specs']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;const specDefaults=savedSpecs.length?savedSpecs:[{type:'mixed',count:10}];
function addSpecRow(container,row,index){const wrap=document.createElement('div');wrap.className='row g-2 align-items-end mb-2 spec-row';wrap.innerHTML='<div class="col-5 col-md-3"><label class="form-label small">Aantal</label><input class="form-control" type="number" name="specs['+index+'][count]" min="1" max="100" value="'+(row.count||10)+'" required></div><div class="col-5 col-md-4"><label class="form-label small">Type</label><select class="form-select" name="specs['+index+'][type]"><option value="mc" '+(row.type==='mc'?'selected':'')+'>Multiple choice</option><option value="open" '+(row.type==='open'?'selected':'')+'>Open vragen</option><option value="mixed" '+(row.type==='mixed'?'selected':'')+'>Combinatie</option></select></div><div class="col-2 col-md-2"><button type="button" class="btn btn-outline-danger w-100 remove-spec">×</button></div>';container.appendChild(wrap);wrap.querySelector('.remove-spec').addEventListener('click',()=>{wrap.remove();updateTotals(container)})}
function fillSpecs(container,specs){container.innerHTML='';(specs.length?specs:[{type:'mixed',count:10}]).forEach((row,i)=>addSpecRow(container,row,i));updateTotals(container)}
function updateTotals(container){if(!container)return;let total=0;container.querySelectorAll('input[name$="[count]"]').forEach(el=>total+=Math.max(0,parseInt(el.value||'0',10)));const totalEl=document.getElementById('specTotal');if(totalEl)totalEl.textContent=total}
const afterContainer=document.getElementById('specRowsAfter');if(afterContainer){fillSpecs(afterContainer,specDefaults);document.getElementById('addSpecAfter')?.addEventListener('click',()=>{addSpecRow(afterContainer,{type:'mixed',count:10},afterContainer.children.length);updateTotals(afterContainer)});afterContainer.addEventListener('input',()=>updateTotals(afterContainer));afterContainer.addEventListener('change',()=>updateTotals(afterContainer))}
const aiLoading=document.getElementById('aiLoading'),aiLoadingTitle=document.getElementById('aiLoadingTitle'),aiLoadingText=document.getElementById('aiLoadingText');let aiLoadingTimer=null;function showAiLoading(kind){if(!aiLoading)return;clearInterval(aiLoadingTimer);if(kind==='analyze'){const queryField=document.getElementById('query');const hasQuery=!!queryField&&queryField.value.trim()!=='';const hasPages=!!input&&!!input.files&&input.files.length>0;if(hasQuery&&!hasPages){aiLoadingTitle.textContent='Opdracht analyseren...';aiLoadingText.textContent='De AI werkt je onderwerp of opdracht uit. Dit kan even duren.'}else if(hasQuery&&hasPages){aiLoadingTitle.textContent='Toetsbron analyseren...';aiLoadingText.textContent='De AI verwerkt je opdracht en de boekpagina’s. Dit kan even duren.'}else{aiLoadingTitle.textContent='Boekpagina’s analyseren...';aiLoadingText.textContent='De AI leest de boekpagina’s. Dit kan even duren.'}}else if(kind==='generate'){aiLoadingTitle.textContent='Toets maken...';aiLoadingText.textContent='GPT maakt de vragen en eventuele samenvatting.'}else{aiLoadingTitle.textContent='Toets opslaan...';const messages=['Leren verwerkt de gegenereerde vragen...','Leren controleert de gegenereerde SVG technisch...','Daarna worden de afbeeldingen veilig opgeslagen...','De afbeeldingen worden opgeslagen bij de vragen...','Bijna klaar — Leren rondt de toets af...'];let i=0;aiLoadingText.textContent=messages[0];aiLoadingTimer=setInterval(()=>{i=(i+1)%messages.length;aiLoadingText.textContent=messages[i]},2800)}aiLoading.classList.remove('d-none');document.body.style.overflow='hidden'}
document.getElementById('analyzeForm')?.addEventListener('submit',()=>showAiLoading('analyze'));document.getElementById('generateForm')?.addEventListener('submit',()=>showAiLoading('generate'));document.getElementById('saveForm')?.addEventListener('submit',e=>{const submit=e.submitter;if(submit&&submit.name==='action'&&submit.value==='clear')return;showAiLoading('save');if(submit){submit.disabled=true;submit.dataset.originalText=submit.textContent;submit.textContent='Opslaan...'}});
</script>
<?php if($imageGenerationMode):?>
<script>
(async function(){
  const bar=document.getElementById('imageProgressBar');
  const text=document.getElementById('imageProgressText');
  const total=<?= (int)($_SESSION['ai_image_jobs']['total']??0) ?>;
  let completed=<?= (int)($_SESSION['ai_image_jobs']['completed']??0) ?>;

  async function nextImage(){
    try{
      const nextNumber=completed+1;
      text.textContent='Afbeelding '+nextNumber+' van '+total+' wordt gemaakt...';
      const controller=new AbortController();
      const timeout=setTimeout(()=>controller.abort(),110000);
      const response=await fetch('ai_generate_image.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:'action=next',signal:controller.signal});
      clearTimeout(timeout);
      const raw=await response.text();
      let data;
      try{data=raw?JSON.parse(raw):null}catch(parseError){throw new Error('De server gaf geen geldige JSON terug. Controleer de serverrespons.');}
      if(!data)throw new Error('De server gaf een lege respons terug.');
      if(!response.ok||!data.ok)throw new Error(data.error||'De afbeelding kon niet worden gemaakt.');

      completed=Number(data.completed||completed);
      const serverTotal=Number(data.total||total);
      const percent=serverTotal?Math.round(completed/serverTotal*100):100;
      bar.style.width=percent+'%';

      if(data.method==='web')text.textContent='Geschikte afbeelding gevonden op internet.';
      else if(data.method==='svg')text.textContent='Eenvoudige SVG-afbeelding gemaakt.';
      else if(data.method==='generate')text.textContent='Geen geschikte afbeelding gevonden — GPT maakt een afbeelding.';

      if(data.done){
        text.textContent='Alle afbeeldingen zijn klaar. De toets wordt geopend...';
        setTimeout(()=>{window.location.href='subject_manage.php?id=<?= (int)$subjectId ?>&ai_saved='+(data.saved_count||0)},700);
        return;
      }

      setTimeout(()=>{text.textContent='Afbeelding '+(completed+1)+' van '+serverTotal+' wordt gemaakt...';},900);
      setTimeout(nextImage,250);
    }catch(error){
      text.textContent=error.name==='AbortError'?'De afbeelding duurt te lang. Je kunt het opnieuw proberen.':(error.message||'Er ging iets mis.');
      bar.classList.add('bg-danger');
      const retry=document.createElement('button');
      retry.className='btn btn-primary mt-3';
      retry.textContent='Opnieuw proberen';
      retry.onclick=()=>{retry.remove();bar.classList.remove('bg-danger');nextImage()};
      document.querySelector('.ai-image-progress')?.appendChild(retry);
    }
  }
  nextImage();
})();
</script>
<?php endif;?>
</body></html>