<?php
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/ai_source_archive.php';
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
$aiRules=ai_test_rules_for_subject($subjectId);
$aiRulesPrompt=trim(ai_subject_group_prompt($subjectId)."\n\n".ai_test_rules_prompt($aiRules));
$imageGenerationMode=false;
if(isset($_SESSION['ai_image_jobs'])&&is_array($_SESSION['ai_image_jobs'])&&($_SESSION['ai_image_jobs']['topic_id']??null)===$topicId){$imageGenerationMode=true;}
$prefix=trim((string)($_POST['prefix']??''));
$query=trim((string)($_POST['query']??''));
$requestedSpecs=$_POST['specs']??[];
$action=$_POST['action']??'';

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
        if(count($result)>=20)break;
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
$learningSubject=ai_subject_group_for_subject($subjectId)==='maatschappij';
$dualPracticeSubject=$learningSubject||(bool)preg_match('/^(natuurkunde|scheikunde|nask)$/iu',trim($subjectName));
function ai_dual_practice_specs(array $specs,bool $bundleMc=false):array{
    $out=[];$mcTotal=0;
    foreach($specs as $spec){
        $type=(string)($spec['type']??'open');
        $count=(int)($spec['count']??0);
        if($count<1)continue;
        if($type==='mixed'){
            $out[]=['type'=>'open','count'=>$count];
            if($bundleMc)$mcTotal+=$count;
            else $out[]=['type'=>'mc','count'=>$count];
        }elseif($type==='mc'&&$bundleMc){
            $mcTotal+=$count;
        }else{
            $out[]=['type'=>$type,'count'=>$count];
        }
    }
    if($bundleMc&&$mcTotal>0)$out[]=['type'=>'mc','count'=>min(100,$mcTotal)];
    return array_slice($out,0,20);
}

function ai_query_subject_label(string $query):string{
    $label=trim((string)(preg_replace('/\\s+/u',' ',$query)??$query));
    $patterns=[
        '/^maak\\s+(?:een\\s+)?(?:toets|overhoring|quiz)\\s+(?:over|van)\\s+/iu',
        '/^maak\\s+(?:een\\s+)?(?:toets|overhoring|quiz)\\s+(?:om te leren|voor)\\s+/iu',
        '/^(?:toets|overhoring|quiz)\\s+(?:over|van)\\s+/iu'
    ];
    foreach($patterns as $pattern)$label=preg_replace($pattern,'',$label,1);
    $label=trim($label," \\t\\n\\r\\\"'.,:;-");
    if($label==='')return '';

    // Houd het onderwerp op het scherm kort: maximaal twee inhoudelijke woorden.
    $stop=['de','het','een','en','van','voor','over','met','op','in','uit','bij','naar','toets','overhoring','quiz','maak','maken','leren','om','te'];
    $words=preg_split('/\\s+/u',$label,-1,PREG_SPLIT_NO_EMPTY)?:[];
    $meaningful=[];
    foreach($words as $word){
        $clean=trim($word," \\t\\n\\r\\\"'.,:;-");
        if($clean===''||in_array(mb_strtolower($clean,'UTF-8'),$stop,true))continue;
        $meaningful[]=$clean;
        if(count($meaningful)>=2)break;
    }
    if($meaningful)return implode(' ',$meaningful);
    return mb_substr($label,0,40);
}

function ai_summary_image_dir(int $topicId):string{
    return __DIR__.'/../storage/summaries/'.(int)$topicId;
}

function ai_archive_summary_images(int $topicId,int $summaryId,array $paths):array{
    $dir=__DIR__.'/../storage/summaries/'.(int)$topicId.'/'.(int)$summaryId;
    if(!is_dir($dir)&&!@mkdir($dir,0755,true))throw new RuntimeException('De map voor samenvattingspagina’s kon niet worden aangemaakt.');
    $archived=[];
    foreach($paths as $index=>$path){
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
        $vocabularyPairs=is_array($saved['analysis']['vocabulary_pairs']??null)?$saved['analysis']['vocabulary_pairs']:[];
        $isVocabularyList=!empty($saved['analysis']['is_vocabulary_list'])&&count($vocabularyPairs)>0;
        $isSentenceList=!empty($saved['analysis']['is_sentence_list'])&&count($vocabularyPairs)>0;
        $isPracticeList=$isVocabularyList||$isSentenceList;
        if($dualPracticeSubject&&!$isPracticeList)$requestedSpecs=ai_dual_practice_specs($requestedSpecs,$learningSubject);
        if($isPracticeList){
            $requestedSpecs=[['type'=>'open','count'=>count($vocabularyPairs)*2]];
            $prefix='';
        }
        if(!is_array($saved)||($saved['subject_id']??null)!==$subjectId||($saved['topic_id']??null)!==$topicId){
            $errors[]='De eerdere AI-analyse is verlopen. Analyseer de pagina’s opnieuw.';
        }elseif(!$isPracticeList&&!$requestedSpecs){
            $errors[]='Geef minimaal één test op.';
        }else{
            $capacity=max(0,(int)($saved['analysis']['max_unique_questions']??0));
            $requestedTotal=ai_requested_total($requestedSpecs);
            if(count($requestedSpecs)>10){
                $errors[]='Je kunt maximaal tien testen tegelijk genereren.';
            }
            if(!$errors){
                $summaryAllowed=true;
                $summaryDetectedTypes=[];
                if(is_array($saved['analysis']['subtests']??null)){
                    foreach($saved['analysis']['subtests'] as $summarySubtest){
                        $summaryType=trim((string)($summarySubtest['recognized_type']??''));
                        if($summaryType!=='')$summaryDetectedTypes[]=$summaryType;
                    }
                }
                foreach($summaryDetectedTypes as $summaryType){
                    $summaryRule=ai_test_rule_for_type($aiRules,$summaryType);
                    if($summaryRule!==null && !(int)$summaryRule['allow_summary']){
                        $summaryAllowed=false;
                        break;
                    }
                }
                if($aiRules&&!$summaryDetectedTypes){
                    $summaryAllowed=false;
                    foreach($aiRules as $summaryRule){
                        if((int)$summaryRule['allow_summary']){
                            $summaryAllowed=true;
                            break;
                        }
                    }
                }
                $useSummary=!$isPracticeList && $summaryAllowed && $query==='' && !empty($saved['images']);

                if($topicId){
                    $topicSummarySetting=$pdo->prepare("UPDATE topics SET use_summary=? WHERE id=?");
                    $topicSummarySetting->execute([$useSummary?1:0,$topicId]);
                }
                // Section summaries are generated after the teacher has reviewed and
                // optionally edited the sub-test titles, immediately before saving.
                $_SESSION['ai_test_analysis']['summary_text']=null;
                $_SESSION['ai_test_analysis']['request']=['prefix'=>$isPracticeList?'':$prefix,'specs'=>$requestedSpecs];
                $requested=[];
                foreach($requestedSpecs as $i=>$spec){
                    $requested[]=['number'=>$i+1,'type'=>$spec['type'],'type_label'=>ai_type_label($spec['type']),'question_count'=>$spec['count']];
                }
                if($isPracticeList){
                    /*
                     * Een herkende woorden-/zinnenlijst hoeft niet opnieuw door GPT te worden
                     * omgezet naar vragen. De analyse bevat de woordparen al. Bouw de twee
                     * richtingen lokaal op; zo voorkomen we een onnodige lange AI-aanroep
                     * (bijvoorbeeld 49 paren = 98 vragen) en daarmee time-outs.
                     */
                    $generatedQuestions=[];
                    foreach($vocabularyPairs as $pair){
                        $source=trim((string)($pair['source']??''));
                        $translation=trim((string)($pair['translation']??''));
                        if($source===''||$translation==='')continue;
                        $label=trim((string)($pair['grammatical_label']??''));
                        $sourceAnswer=$translation;
                        $translationAnswer=$source;
                        $learningLanguage=trim((string)($saved['analysis']['vocabulary_language']??''));
                        $sourceExplanation='Vertaal naar Nederlands.'.($label!==''?' '.$label.'.':'');
                        $reverseExplanation='Vertaal naar '.($learningLanguage!==''?$learningLanguage:'de andere taal').'.';
                        $generatedQuestions[]=[
                            'type'=>'open','question'=>$source,'learning_term'=>$source,'correct_answer'=>$sourceAnswer,
                            'options'=>[],'correct_option'=>0,'accepted_answers'=>[$sourceAnswer],
                            'explanation'=>$sourceExplanation,'vocab_direction'=>'left_to_right','source_page'=>1,'use_image'=>false,
                            'image_prompt'=>'','image_search_query'=>'','svg_code'=>'',
                            'image_method'=>'none','image_reason'=>'','grammar_label'=>$label
                        ];
                        $generatedQuestions[]=[
                            'type'=>'open','question'=>$translation,'learning_term'=>$source,'correct_answer'=>$translationAnswer,
                            'options'=>[],'correct_option'=>0,'accepted_answers'=>[$translationAnswer],
                            'explanation'=>$reverseExplanation,'vocab_direction'=>'right_to_left','source_page'=>1,'use_image'=>false,
                            'image_prompt'=>'','image_search_query'=>'','svg_code'=>'',
                            'image_method'=>'none','image_reason'=>'','grammar_label'=>$label
                        ];
                    }
                    // Bewaar expliciet dat deze generatie een woorden-/zinnenoefening is.
                    // De save-stap gebruikt dit om test_type correct als vocabulary/sentences
                    // op te slaan in plaats van terug te vallen naar "open".
                    $_SESSION['ai_test_analysis']['vocabulary_mode']=true;
                    $_SESSION['ai_test_analysis']['practice_mode']=$isSentenceList?'sentences':'vocabulary';
                    $_SESSION['ai_test_analysis']['generated']=[
                        'subtests'=>[[
                            'title'=>$isSentenceList?'Zinnen oefenen':'Woordjes oefenen',
                            'description'=>'','questions'=>$generatedQuestions
                        ]]
                    ];
                }else{
                    $learningPoints=array_values(array_filter(array_map('trim',(array)($saved['analysis']['learning_points']??[]))));
                    $prompt='KENNISDEKKING IS HET HOOFDDOEL. Controleer eerst alle leerdoelen uit de bronanalyse en verdeel de vragen zo dat ieder leerdoel minstens eenmaal inhoudelijk getoetst wordt. Bewuste herhaling van belangrijke kennis in meerdere vraagvormen is toegestaan. Als de gekozen aantallen onvoldoende zijn, geef prioriteit aan alle afzonderlijke leerdoelen en vermijd oppervlakkige vragen. Leerdoelen: '.json_encode($learningPoints,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'. Maak concrete oefentoetsvragen voor precies de gevraagde sub-tests. Voor Leerstofvakken: behoud de afzonderlijke open-vragensubtests per onderwerp, maar maak één overkoepelende multiplechoicesubtest die de belangrijkste leerdoelen uit alle onderwerpen samen oefent. Multiplechoicevragen moeten dezelfde leerstof behandelen, maar hoeven niet letterlijk dezelfde vragen of hetzelfde totale aantal als de open vragen te zijn. Gebruik uitsluitend de bron wanneer er boekpagina’s zijn aangeleverd; gebruik bij een query de gebruikersopdracht en algemene kennis. Pas de beheerde vak- en sub-testconfiguratie toe. Maak exact het gevraagde aantal sub-tests en vragen. Bij mc zijn er exact vier opties en één correct antwoord. Bij open zijn er inhoudelijk geldige accepted_answers. Bij combinatie een evenwichtige mix. Verzin geen informatie die niet uit de bron of opdracht volgt.'.$aiRulesPrompt.' De gewenste opdrachten zijn: '.json_encode($requested,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'.';
                    $data=openai_generate_test_questions($prompt,(array)($saved['images']??[]),$query!=='' && empty($saved['images']));
                    if(isset($data['_leren_error']))$errors[]=$data['_leren_error'];
                    else{
                        $generated=openai_output_json($data);
                        if(!$generated||!isset($generated['subtests'])||!is_array($generated['subtests'])||count($generated['subtests'])!==count($requested)){
                            $reason=(string)($data['incomplete_details']['reason']??'');
                            if(($data['status']??'')==='incomplete' && $reason!==''){
                                $errors[]='De AI-generatie van de vragen werd niet volledig afgerond ('.$reason.'). Verminder eventueel het aantal vragen per test en probeer het opnieuw.';
                            }else{
                                $errors[]='De AI gaf geen bruikbaar JSON-resultaat voor de vragen terug. Probeer dezelfde selectie opnieuw.';
                            }
                        }else{
                            $_SESSION['ai_test_analysis']['generated']=$generated;
                        }
                    }
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
            $isVocabularyMode=!empty($saved['vocabulary_mode']);
            $practiceMode=(string)($saved['practice_mode']??($isVocabularyMode?'vocabulary':''));
            // Fallback voor eerder gegenereerde sessies waarin de modus nog niet
            // expliciet was opgeslagen.
            if(!in_array($practiceMode,['vocabulary','sentences'],true)){
                $savedPairs=is_array($saved['analysis']['vocabulary_pairs']??null)?$saved['analysis']['vocabulary_pairs']:[];
                if(!empty($saved['analysis']['is_vocabulary_list'])&&$savedPairs){
                    $isVocabularyMode=true;
                    $practiceMode='vocabulary';
                }elseif(!empty($saved['analysis']['is_sentence_list'])&&$savedPairs){
                    $isVocabularyMode=true;
                    $practiceMode='sentences';
                }
            }
            $isPracticeMode=in_array($practiceMode,['vocabulary','sentences'],true);
            $validTests=[];
            foreach($posted as $si=>$test){                if(!isset($generated[$si])||!is_array($test))continue;
                $rawTitle=trim((string)($test['title']??''));
                $prefixToUse=trim((string)(($saved['request']['prefix']??'')??''));
                $title=$prefixToUse!==''?$prefixToUse.' '.$rawTitle:$rawTitle;
                $description=trim((string)($test['description']??''));
                $questions=$generated[$si]['questions']??[];
                if($title===''){ $errors[]='Elke test moet een titel hebben.'; continue; }
                if(!is_array($questions)||!$questions){$errors[]='Test "'.$title.'" bevat geen vragen.';continue;}
                $validQuestions=[];
                foreach($questions as $qi=>$q){
                    if(!isset($generated[$si]['questions'][$qi])||!is_array($q))continue;
                    $type=(string)($q['type']??'');
                    $question=trim((string)($q['question']??''));
                    $correct=trim((string)($q['correct_answer']??''));
                    $explanation=trim((string)($q['explanation']??''));
                    $sourcePage=max(1,(int)($q['source_page']??1));
                    $postedQuestion=(is_array($test['questions']??null)&&is_array($test['questions'][$qi]??null))?$test['questions'][$qi]:[];
                    $useImage=!empty($postedQuestion['use_image'])||(!isset($postedQuestion['use_image'])&&!empty($q['use_image']));
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
                    $validQuestions[]=['type'=>$type,'question'=>$question,'correct'=>$correct,'options'=>$options,'correct_option'=>$type==='mc'?(int)$q['correct_option']:0,'explanation'=>$explanation,'grammar_label'=>trim((string)($generatedQuestion['grammatical_label']??$generatedQuestion['grammar_label']??$q['grammatical_label']??$q['grammar_label']??'')),'source_page'=>$sourcePage,'use_image'=>$useImage,'image_prompt'=>trim((string)($generatedQuestion['image_prompt']??'')),'image_search_query'=>trim((string)($generatedQuestion['image_search_query']??'')),'svg_code'=>trim((string)($generatedQuestion['svg_code']??'')),'image_method'=>trim((string)($q['image_method']??$generatedQuestion['image_method']??'none')),'vocab_direction'=>($q['vocab_direction']??$generatedQuestion['vocab_direction']??null),'image_reason'=>trim((string)($generatedQuestion['image_reason']??''))];
                }
                if($validQuestions)$validTests[]=['title'=>$title,'description'=>$description,'questions'=>$validQuestions];
            }
            if(!$errors&&!$validTests)$errors[]='Er is geen geldige test om op te slaan.';
            // Generate one independent summary for each approved learning section.
            // Do this before the DB transaction to avoid long-running locks.
            $sectionSummaries=[];
            if(!$errors && $useSummary && $sourceImages){
                foreach($validTests as $sectionIndex=>$plannedTest){
                    try{
                        $sectionTitle=(string)$plannedTest['title'];
                        $sectionDescription=(string)$plannedTest['description'];
                        $summaryPrompt="Maak uitsluitend een zelfstandige Nederlandse leersamenvatting voor het leerstofonderdeel: ".$sectionTitle."\nOmschrijving: ".$sectionDescription."\nGebruik uitsluitend de meegestuurde boekpagina's. Behandel geen andere onderdelen. Geef alle relevante feiten, begrippen en verbanden, zonder informatie te verzinnen. Zet geen andere leerstofonderdelen in de samenvatting.";
                        $summaryData=openai_generate_topic_summary($summaryPrompt,$sourceImages);
                        if(isset($summaryData['_leren_error']))throw new RuntimeException((string)$summaryData['_leren_error']);
                        $summaryJson=openai_output_json($summaryData);
                        $sectionText=trim((string)($summaryJson['summary']??''));
                        if($sectionText==='')throw new RuntimeException('AI gaf geen tekst terug.');
                        $sectionSummaries[$sectionIndex]=$sectionText;
                    }catch(Throwable $summaryError){
                        $errors[]='Samenvatting voor "'.$sectionTitle.'" mislukt: '.$summaryError->getMessage();
                        break;
                    }
                }
            }

            if(!$errors){
                if($isPracticeMode){
                    $pdo->exec("ALTER TABLE tests MODIFY COLUMN test_type ENUM('vocabulary','sentences','multiple_choice','open','mixed') NOT NULL DEFAULT 'mixed'");
                }
                try{
                    // Archive tables require DDL; create them before opening a transaction.
                    if($sourceImages)ai_source_archive_tables($pdo);
                    $pdo->beginTransaction();
                    $qIns=$pdo->prepare("INSERT INTO questions(test_id,question_text,image_path,question_type,vocab_direction,explanation,grammar_label,sort_order) VALUES(?,?,?,?,?,?,?,?)");
                    $optIns=$pdo->prepare("INSERT INTO question_options(question_id,option_text,is_correct,sort_order) VALUES(?,?,?,?)");
                    $oaIns=$pdo->prepare("INSERT INTO open_question_answers(question_id,answer_text,sort_order) VALUES(?,?,?)");
                    $testIns=$pdo->prepare("INSERT INTO tests(topic_id,title,description,test_type,vocab_left_label,vocab_right_label,vocab_direction,is_active) VALUES(?,?,?,?,?,?,?,1)");
                    $savedCount=0;
                    $sourceArchive=['collection_id'=>null,'pages'=>[]];
                    if($sourceImages){
                        $archiveTitle=trim((string)($saved['analysis']['topic']??$topicName));
                        $archiveDescription=trim((string)($saved['analysis']['summary']??''));
                        $sourceArchive=ai_source_archive_create($pdo,$saved,$subjectId,$topicId,$archiveTitle!==''?$archiveTitle:$topicName,$archiveDescription);
                    }
                    $sourceCollectionId=(int)($sourceArchive['collection_id']??0);
                    $sourcePageIds=(array)($sourceArchive['pages']??[]);
                    $sectionIds=[];
                    if($sourceCollectionId){
                        $sectionInsert=$pdo->prepare("INSERT INTO ai_source_sections(collection_id,title,description,summary,sort_order) VALUES(?,?,?,?,?)");
                        foreach($validTests as $index=>$plannedTest){
                            $sectionInsert->execute([$sourceCollectionId,$plannedTest['title'],$plannedTest['description'],$sectionSummaries[$index]??null,$index+1]);
                            $sectionIds[$index]=(int)$pdo->lastInsertId();
                            // Preserve explicit page provenance for the section even when
                            // individual questions lack a page number.
                            $referencedPages=[];
                            foreach($plannedTest['questions'] as $sourceQuestion){
                                $pageNo=(int)($sourceQuestion['source_page']??0);
                                if(isset($sourcePageIds[$pageNo]))$referencedPages[$pageNo]=true;
                            }
                            if(!$referencedPages && count($sourcePageIds)===1){
                                $referencedPages[array_key_first($sourcePageIds)]=true;
                            }
                            foreach(array_keys($referencedPages) as $pageNo){
                                ai_source_archive_link($pdo,$sourceCollectionId,$sectionIds[$index],$sourcePageIds[$pageNo],null,null,'section_page');
                            }
                        }
                    }
                    $createdQuestionImages=[];
                    $pendingImageJobs=[];
                    $questionImageDir=__DIR__.'/uploads/questions';
                    if(!is_dir($questionImageDir)&&!@mkdir($questionImageDir,0755,true))throw new RuntimeException('De map uploads/questions kon niet worden aangemaakt.');
                    foreach($validTests as $testIndex=>$test){
                        $testType='multiple_choice';
                        $hasOpen=false;$hasMc=false;
                        foreach($test['questions'] as $q){$hasOpen=$hasOpen||$q['type']==='open';$hasMc=$hasMc||$q['type']==='mc';}
                        if($hasOpen&&$hasMc)$testType='mixed';elseif($hasOpen)$testType='open';
                        if($isPracticeMode)$testType=$practiceMode;
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
                        $vocabLanguage=$isPracticeMode?trim((string)($saved['analysis']['vocabulary_language']??'')):'';
                        $leftLabel=$isPracticeMode?($vocabLanguage!==''?$vocabLanguage:'Brontaal'):null;
                        $rightLabel=$isPracticeMode?'Nederlands':null;
                        $testIns->execute([$topicId,$title,$test['description'],$testType,$leftLabel,$rightLabel,'both']);
                        $testId=(int)$pdo->lastInsertId();
                        foreach($test['questions'] as $sort=>$q){
                            $imagePath=null;
                            if($q['use_image']){
                                $searchQuery=trim((string)($q['image_search_query']??''));
                                $svgCode=trim((string)($q['svg_code']??''));
                                $imagePrompt=trim((string)($q['image_prompt']??''));
                                $imageMethod=trim((string)($q['image_method']??'none'));
                                // Als GPT oorspronkelijk geen afbeelding wilde, maar de beheerder
                                // op pagina 3 alsnog "Afbeelding gebruiken" kiest, maak dan
                                // automatisch een GPT-afbeelding op basis van de vraag.
                                if($imageMethod==='none'){
                                    // Bronillustraties hebben voorrang op opnieuw gegenereerde beelden.
                                    $sourcePage=(int)($q['source_page']??0);
                                    if($sourcePage>=1 && isset($sourceImages[$sourcePage-1]) && is_file($sourceImages[$sourcePage-1])){
                                        $imageMethod='source';
                                    }else{
                                        $imageMethod='generate';
                                        $imagePrompt=$question;
                                    }
                                }
                                if(!in_array($imageMethod,['web','svg','generate','source'],true)){
                                    throw new RuntimeException('Ongeldige afbeeldingsmethode bij een gegenereerde vraag.');
                                }
                                if($imageMethod==='generate'&&$imagePrompt==='')$imagePrompt=$question;
                                if(($imageMethod==='web'&&$searchQuery==='')||($imageMethod==='svg'&&$svgCode==='')||($imageMethod==='generate'&&$imagePrompt==='')){
                                    throw new RuntimeException('De gekozen afbeeldingsmethode heeft geen bijbehorende afbeeldingsdata.');
                                }
                                $q['image_method']=$imageMethod;
                                $q['image_prompt']=$imagePrompt;
                            }
                            if($q['use_image'] && ($q['image_method']??'')==='source'){
                                $sourcePage=(int)($q['source_page']??0);
                                $sourceFile=$sourceImages[$sourcePage-1]??null;
                                if(!is_string($sourceFile)||!is_file($sourceFile))throw new RuntimeException('De bronpagina voor deze vraag ontbreekt.');
                                $questionImageDir=__DIR__.'/uploads/questions';
                                if(!is_dir($questionImageDir)&&!@mkdir($questionImageDir,0755,true))throw new RuntimeException('Afbeeldingsmap niet beschikbaar.');
                                $mime=(string)(@mime_content_type($sourceFile)?:'');
                                $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
                                if($ext===null)throw new RuntimeException('Ongeldig bronafbeeldingstype.');
                                $imagePath='source_'.bin2hex(random_bytes(12)).'.'.$ext;
                                if(!@copy($sourceFile,$questionImageDir.'/'.$imagePath))throw new RuntimeException('Bronafbeelding kopiëren mislukt.');
                                $createdQuestionImages[]=$questionImageDir.'/'.$imagePath;
                            }
                            $qIns->execute([$testId,$q['question'],$imagePath,$q['type']==='mc'?'multiple_choice':'open',$q['vocab_direction']??null,$q['explanation'],$q['grammar_label']??null,$sort+1]);
                            $qid=(int)$pdo->lastInsertId();
                            if($sourceCollectionId){
                                $pageNo=(int)($q['source_page']??0);
                                $pageId=$sourcePageIds[$pageNo]??null;
                                ai_source_archive_link($pdo,$sourceCollectionId,$sectionIds[$testIndex]??null,$pageId,$qid,null,'question');
                            }
                            if($q['use_image'] && $imagePath===null && (string)(!empty($q['use_image']))){
                                $pendingImageJobs[]=[
                                    'question_id'=>$qid,
                                    'question'=>(string)$q['question'],
                                    'correct_answer'=>(string)$q['correct'],
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
                    $pdo->commit();
                    // Region detection is best-effort: saved tests must never be rolled back
                    // when the vision service is unavailable or returns no regions.
                    if($sourceCollectionId>0 && $sourceImages){
                        try{
                            require_once __DIR__.'/../app/ai_source_auto_regions.php';
                            $regionResult=ai_source_auto_detect($pdo,$sourceCollectionId);
                            if(!empty($regionResult['errors'])){
                                error_log('AI source regions collection '.$sourceCollectionId.': '.implode('; ',array_slice($regionResult['errors'],0,5)));
                            }
                        }catch(Throwable $regionError){
                            error_log('AI source regions collection '.$sourceCollectionId.' failed: '.$regionError->getMessage());
                        }
                    }
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

        if(!$errors && $query==='' && $valid){
            $validationData=openai_validate_test_source_images($subjectName,$valid);
            if(isset($validationData['_leren_error'])){
                foreach($valid as $path)@unlink($path);
                if($sessionDir&&is_dir($sessionDir))@rmdir($sessionDir);
                $errors[]=$validationData['_leren_error'];
            }else{
                $validation=openai_output_json($validationData);
                $validationImages=is_array($validation['images']??null)?$validation['images']:[];
                if(!$validation||empty($validation['all_valid'])||count($validationImages)!==count($valid)){
                    $rejected=[];
                    foreach($validationImages as $index=>$imageResult){
                        if(empty($imageResult['valid'])){
                            $reason=trim((string)($imageResult['reason']??''));
                            $rejected[]='Foto '.((int)$index+1).($reason!==''?': '.$reason:'.');
                        }
                    }
                    $errors[]='De geüploade foto is niet geschikt als bron voor '.$subjectName.'.'.($rejected?' '.$rejected[0]:' Controleer of het een duidelijke schoolboekpagina of werkblad voor dit vak is.');
                    foreach($valid as $path)@unlink($path);
                    if($sessionDir&&is_dir($sessionDir))@rmdir($sessionDir);
                    $valid=[];
                }
            }
        }

        if(!$errors){
            if($query!==''){
                $prompt='Analyseer de gebruikersopdracht voor deze oefentoets.';
                $data=openai_generate_text_analysis($prompt,$aiRulesPrompt);
            }else{
                $prompt='Analyseer de geüploade schoolboekpagina’s voor deze oefentoets.';
                $data=openai_generate_with_images($prompt,$valid,$aiRulesPrompt);
            }

            if(isset($data['_leren_error'])){
                foreach($valid as $path)@unlink($path);
                if($sessionDir&&is_dir($sessionDir))@rmdir($sessionDir);
                $errors[]=$data['_leren_error'];
            }else{
                $analysis=openai_output_json($data);
                if($analysis&&is_array($analysis)&&!empty($analysis['is_vocabulary_list'])&&is_array($analysis['vocabulary_pairs']??null)&&count($analysis['vocabulary_pairs'])>0){
                    $pairCount=count($analysis['vocabulary_pairs']);
                    $language=trim((string)($analysis['vocabulary_language']??''));
                    $analysis['subtests']=[[
                        'title'=>'Woordjes oefenen','recognized_type'=>'vocabulary',
                        'description'=>'Automatisch herkende woordenlijst'.($language!==''?' ('.$language.')':'').' met '.$pairCount.' woordparen.',
                        'question_count'=>$pairCount,
                        'recommended_types'=>['open']
                    ]];
                    $analysis['max_unique_questions']=$pairCount;
                    $analysis['summary']='Er is een woordenlijst herkend met '.$pairCount.' woordparen.'.($language!==''?' Brontaal: '.$language.'.':'');
                }
                if($analysis&&is_array($analysis)&&!empty($analysis['is_sentence_list'])&&is_array($analysis['vocabulary_pairs']??null)&&count($analysis['vocabulary_pairs'])>0){
                    $pairCount=count($analysis['vocabulary_pairs']);$language=trim((string)($analysis['vocabulary_language']??''));
                    $analysis['subtests']=[['title'=>'Zinnen oefenen','recognized_type'=>'sentences','description'=>'Automatisch herkende zinnenlijst'.($language!==''?' ('.$language.')':'').' met '.$pairCount.' zinnen.','question_count'=>$pairCount,'recommended_types'=>['open']]];
                    $analysis['max_unique_questions']=$pairCount;
                    $analysis['summary']='Er is een zinnenlijst herkend met '.$pairCount.' zinnen.'.($language!==''?' Brontaal: '.$language.'.':'');
                }
                // Voorkom mini-toetsen: maak het toetsplan minimaal even uitgebreid
                // als de geïnventariseerde leerdoelen, met ruimte voor toepassing.
                if($analysis && empty($analysis['is_vocabulary_list']) && empty($analysis['is_sentence_list'])){
                    $points=array_values(array_filter(array_map('trim',(array)($analysis['learning_points']??[]))));
                    $subtests=(array)($analysis['subtests']??[]);
                    $total=array_sum(array_map(static fn($st)=>max(0,(int)($st['question_count']??0)),$subtests));
                    $target=max(count($points), (int)ceil(count($points)*1.5));
                    if($subtests && $target>$total){
                        $remaining=$target-$total;
                        while($remaining>0){
                            $changed=false;
                            foreach($subtests as &$subtest){
                                if($remaining<=0)break;
                                if((int)($subtest['question_count']??0)>=50)continue;
                                $subtest['question_count']=(int)($subtest['question_count']??0)+1;
                                $remaining--;
                                $changed=true;
                            }
                            unset($subtest);
                            if(!$changed)break;
                        }
                        $analysis['subtests']=$subtests;
                    }
                    $analysis['recommended_question_count']=array_sum(array_map(static fn($st)=>(int)($st['question_count']??0),(array)($analysis['subtests']??[])));
                }
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
$queryLabel=ai_query_subject_label($query);
$displayTopicName=$queryLabel!==''?$queryLabel:$topicName;
$savedRequest=$_SESSION['ai_test_analysis']['request']??['prefix'=>$prefix,'specs'=>$requestedSpecs];
$detectedVocabulary=!empty($analysis['is_vocabulary_list'])&&is_array($analysis['vocabulary_pairs']??null)&&count($analysis['vocabulary_pairs'])>0;
$detectedSentenceList=!empty($analysis['is_sentence_list'])&&is_array($analysis['vocabulary_pairs']??null)&&count($analysis['vocabulary_pairs'])>0;
$detectedPracticeList=$detectedVocabulary||$detectedSentenceList;
$isPracticeList=$detectedPracticeList;$detectedTypes=[]; if(is_array($analysis['subtests']??null)){foreach($analysis['subtests'] as $st){$t=trim((string)($st['recognized_type']??''));if($t!=='')$detectedTypes[]=$t;}}
$ruleSummaryAllowed=true;
foreach($detectedTypes as $type){$rule=ai_test_rule_for_type($aiRules,$type);if($rule!==null && !(int)$rule['allow_summary']){$ruleSummaryAllowed=false;break;}}
if($aiRules&&!$detectedTypes){$ruleSummaryAllowed=false;foreach($aiRules as $rule){if((int)$rule['allow_summary']){$ruleSummaryAllowed=true;break;}}}

if($detectedPracticeList && empty($_SESSION['ai_test_analysis']['request']['specs'])){
    $savedRequest['specs']=[['type'=>'open','count'=>count($analysis['vocabulary_pairs'])*2]];
}
$savedSummaryText=(string)($_SESSION['ai_test_analysis']['summary_text']??'');
?>
<!doctype html>
<html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI toets maken</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
/* Elke gegenereerde sub-test is een zelfstandig blok; geen visuele overlap. */
#saveForm .generated-test-card{position:relative;display:block;clear:both;margin:0 0 1rem!important;border:1px solid rgba(150,170,155,.32);border-radius:12px;overflow:hidden;box-shadow:none;transform:none}
#saveForm .generated-test-header{position:static;padding:1rem;border-bottom:1px solid rgba(150,170,155,.25)}
#saveForm .generated-question-list{position:static;display:block;margin:0;padding:0}
#saveForm .generated-question{position:relative;display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.85rem 1rem;margin:0;border-bottom:1px solid rgba(150,170,155,.22);min-height:0}
#saveForm .generated-question:last-child{border-bottom:0}
#saveForm .ai-question-list-main{flex:1;min-width:0}
#saveForm .ai-question-side{flex-shrink:0}
#saveForm .generated-test-card::after{content:"";display:block;clear:both}
/* Houd de afsluitende acties buiten de subtest-kaders, met consistente ruimte. */
#saveForm .generated-test-card{margin-bottom:1rem!important}
#saveForm .ai-save-actions{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;clear:both;margin:1.5rem 0 0;padding:0;border:0;background:transparent;box-shadow:none}
#saveForm .ai-save-actions .btn{min-width:0;white-space:normal;line-height:1.25;padding:.85rem 1rem;border-radius:.75rem}
@media(max-width:575.98px){#saveForm .ai-save-actions{grid-template-columns:1fr}}

</style>
</head><body class="bg-light"><div id="aiLoading" class="ai-loading d-none" aria-live="polite" aria-busy="true"><div class="ai-loading-card"><div class="spinner-border text-primary mb-3" role="status"><span class="visually-hidden">Bezig...</span></div><div id="aiLoadingTitle" class="h5 mb-1">Bezig met AI...</div><div id="aiLoadingText" class="text-secondary">Even geduld.</div></div></div><main class="container py-4">
<a href="subject_manage.php?id=<?=$subjectId?>">&larr; <?=e($subjectName)?></a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<h1 class="h3 mb-1">AI toets maken</h1>
<div class="text-secondary mb-4">Vak: <strong><?=e($subjectName)?></strong> · <strong><?=e($displayTopicName)?></strong></div>
<?php foreach($errors as $error):?><div class="ai-source-error alert alert-danger"><?=e($error)?></div><?php endforeach;?>

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
<p class="text-secondary mb-3">Kies eerst hoe je de toets wilt laten maken.</p>
</div>

<div id="sourceChoice" class="row g-3 mb-4">
<div class="col-md-6">
<button type="button" class="btn btn-outline-primary w-100 text-start p-4 h-100 source-choice-card" data-source="query">
<div class="fs-2 mb-2">✏️</div>
<div class="fs-5 fw-semibold">QUERY</div>
<div class="small text-secondary mt-1">Geef een onderwerp of beschrijf precies wat je wilt oefenen.</div>
</button>
</div>
<div class="col-md-6">
<button type="button" class="btn btn-outline-primary w-100 text-start p-4 h-100 source-choice-card" data-source="book">
<div class="fs-2 mb-2">📚</div>
<div class="fs-5 fw-semibold">BOEKFOTO</div>
<div class="small text-secondary mt-1">Upload één of meerdere foto’s van de pagina’s uit je schoolboek.</div>
</button>
</div>
</div>

<form method="post" enctype="multipart/form-data" id="analyzeForm" data-ai-loading="analyze">
<input type="hidden" name="action" value="analyze">
<div id="querySource" class="source-panel d-none">
<div class="card bg-light border-0 mb-3"><div class="card-body">
<label class="form-label fw-semibold" for="query">Onderwerp of opdracht</label>
<textarea class="form-control" id="query" name="query" rows="3" placeholder="Bijvoorbeeld: Maak een toets over de Griekse stadstaten, met aandacht voor Athene, Sparta en de democratie."><?=e($query)?></textarea>
<div class="form-text">Je kunt hier ook precies beschrijven wat je wilt oefenen. Zonder boekpagina’s gebruikt de AI haar algemene kennis.</div>
</div></div>
</div>

<div id="bookSource" class="source-panel d-none">
<label class="dropzone d-block mb-3" for="pages" id="dropzone">
<div class="upload-icon">📚</div>
<div class="fw-semibold fs-5">Sleep boekpagina’s hierheen</div>
<div class="text-secondary mt-1">of tik om foto’s te kiezen</div>
<div class="upload-rules mt-3"><span>JPG, PNG of WebP</span><span>Max. 10 pagina’s</span><span>Max. 5 MB per foto</span></div>
<input class="d-none" id="pages" type="file" name="pages[]" accept="image/jpeg,image/png,image/webp" multiple>
</label>
<div id="photoCounter" class="small text-secondary mb-2" aria-live="polite">0 van 10 foto’s toegevoegd</div>
<div id="fileList" class="upload-file-list mb-3"></div>
<button type="button" id="addMorePhotos" class="btn btn-outline-primary mb-4">+ Nog een foto toevoegen</button>
</div>

<div id="sourceActions" class="d-none d-flex flex-column flex-sm-row gap-2">
<button class="btn btn-primary btn-lg" type="submit" id="analyzeButton">Start met verwerken</button>
<a class="btn btn-outline-secondary btn-lg" href="subject_manage.php?id=<?=$subjectId?>">Annuleren</a>
</div>
</form>

<?php elseif($stage===2):?>
<div class="analysis-overview card border-0 bg-light mb-4"><div class="card-body">
<div class="small text-uppercase text-secondary fw-semibold mb-1">Analyse voltooid</div>
<h2 class="h4 mb-1"><?=e($analysis['subject'])?> — <?=e($analysis['topic'])?></h2>
</div></div>
<div class="mb-3">
<h2 class="h4 mb-1">2. Toets instellen</h2><p class="text-secondary mb-0">Kies hier de instellingen. Je hoeft dit maar één keer te doen.</p>
</div>
<form method="post" id="generateForm" data-ai-loading="generate">
<input type="hidden" name="action" value="generate">

<?php if(!$detectedPracticeList):?><div class="card mb-3"><div class="card-body">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="use_summary" value="1" id="useSummarySettings" <?=$useSummary?'checked':''?> <?=($query!==''||!$ruleSummaryAllowed?'disabled':'')?>>
<label class="form-check-label fw-semibold" for="useSummarySettings">Samenvatting maken</label>
<div class="small text-secondary mt-1"><?=($query!==''?'Bij een query-toets wordt de samenvatting overgeslagen; er zijn geen boekpagina’s als bron.':(!$ruleSummaryAllowed?'Voor dit vak/type is in de AI-configuratie geen samenvatting toegestaan.':'De AI maakt een aparte leersamenvatting van deze pagina’s.'))?></div>
</div>
</div></div><?php endif;?>
<?php if(!$detectedPracticeList):?><div class="alert alert-info mb-3"><strong>Voorgesteld toetsplan</strong><p class="mb-2">AI bepaalt per boekpagina de leerdoelen en stelt toetsvormen en aantallen voor. Je kunt het plan hieronder aanpassen voordat je de vragen maakt.</p><?php if(!empty($analysis['learning_points'])):?><details><summary>Leerdoelen uit de bron (<?=count($analysis['learning_points'])?>)</summary><ul class="mt-2 mb-0"><?php foreach($analysis['learning_points'] as $point):?><li><?=e((string)$point)?></li><?php endforeach;?></ul></details><?php endif;?></div><?php endif;?>
<?php if($detectedPracticeList):?><div class="alert alert-success mb-4"><strong><?= $detectedSentenceList?'Zinnenlijst':'Woordenlijst' ?> herkend</strong><br><?=count($analysis['vocabulary_pairs'])?> <?= $detectedSentenceList?'zinnen':'woordparen' ?> gevonden. Leren maakt automatisch één test <strong><?= $detectedSentenceList?'Zinnen oefenen':'Woordjes oefenen' ?></strong> met beide richtingen.</div><?php else:?><div class="card mb-4"><div class="card-body">
<div class="mb-3"><strong>Toetsplan</strong><div class="small text-secondary">Pas desgewenst de door AI voorgestelde aantallen en toetsvormen aan. Verlaag aantallen alleen als de leerdoelen nog voldoende getoetst kunnen worden.</div></div>
<div id="specRowsAfter"></div>
</div></div><?php endif;?>
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
<h2 class="h4 mb-1">3. Vragen controleren</h2><p class="text-secondary mb-0">Controleer de gegenereerde testen en sla ze daarna op.</p>
</div>
<form method="post" id="saveForm">
<input type="hidden" name="action" value="save">
<?php foreach($_SESSION['ai_test_analysis']['generated']['subtests'] as $si=>$generatedSub):?>
<div class="generated-test-card mb-4">
<div class="generated-test-header">
<div class="small text-uppercase text-secondary fw-semibold mb-1">Test <?=($si+1)?></div>
<input class="form-control form-control-lg fw-semibold mb-2" name="tests[<?=$si?>][title]" value="<?=e($generatedSub['title'])?>" required>
<input class="form-control" name="tests[<?=$si?>][description]" value="<?=e($generatedSub['description']??'')?>" placeholder="Beschrijving (optioneel)">
</div>
<div class="generated-question-list">
<?php $visibleQuestionNumber=0; ?>
<?php foreach(($generatedSub['questions']??[]) as $qi=>$q):?>
<?php if($isPracticeList && ($qi % 2)!==0) continue; ?>
<?php $visibleQuestionNumber++; ?>
<div class="generated-question ai-question-list-item">
<div class="ai-question-list-main">
<div class="question-text"><span class="question-number"><?=($isPracticeList?$visibleQuestionNumber:($qi+1))?>:</span> <?=e($isPracticeList ? ($q['learning_term']??$q['question']) : $q['question'])?></div>
<?php
$grammarLabel=mb_strtolower(trim((string)($q['grammatical_label']??$q['grammar_label']??'')));
$grammarParts=[];
if(str_contains($grammarLabel,'mannelijk'))$grammarParts[]='m';
if(str_contains($grammarLabel,'vrouwelijk'))$grammarParts[]='v';
if(str_contains($grammarLabel,'meervoud'))$grammarParts[]='mv';
if(str_contains($grammarLabel,'enkelvoud'))$grammarParts[]='ev';
$grammarShort=implode(' · ',$grammarParts);
?>
</div>
<div class="ai-question-side">
<?php if($grammarShort!==''):?><span class="ai-question-meta" title="<?=e($grammarLabel)?>"><?=$grammarShort?></span><?php endif;?>
<label class="ai-image-check" title="Afbeelding gebruiken">
<input type="checkbox" name="tests[<?=$si?>][questions][<?=$qi?>][use_image]" value="1" <?=(!empty($q['use_image'])&&(!empty($q['use_image'])))?'checked':''?>>
<span aria-hidden="true">✓</span>
<span class="visually-hidden">Afbeelding <?=(!empty($q['use_image'])&&(!empty($q['use_image'])))?'gebruiken':'niet gebruiken'?></span>
</label>
</div>
</div>
<?php endforeach;?>
</div>
</div><!-- /.generated-test-card -->
<?php endforeach;?>
<div class="ai-save-actions">
<button class="btn btn-success btn-lg" type="submit">Opslaan als test<?=count($_SESSION['ai_test_analysis']['generated']['subtests'])===1?'':'s'?></button>
<button class="btn btn-outline-secondary btn-lg" type="submit" name="action" value="clear" formnovalidate>Annuleren</button>
</div>
</form>
<?php endif;?>

</div></div></main>
<script>
const input=document.getElementById('pages'),list=document.getElementById('fileList'),dropzone=document.getElementById('dropzone'),analyzeButton=document.getElementById('analyzeButton'),queryInput=document.getElementById('query'),sourceChoice=document.getElementById('sourceChoice'),querySource=document.getElementById('querySource'),bookSource=document.getElementById('bookSource'),sourceActions=document.getElementById('sourceActions'),analyzeForm=document.getElementById('analyzeForm');
let selectedSource='';
function updateAnalyzeButton(){if(!analyzeButton)return;const hasQuery=!!queryInput&&queryInput.value.trim()!=='';const hasFiles=!!input&&!!input.files&&input.files.length>0;analyzeButton.disabled=selectedSource==='query'?!hasQuery:selectedSource==='book'?!hasFiles:true}
function selectSource(source){selectedSource=source;sourceChoice?.classList.add('d-none');querySource?.classList.toggle('d-none',source!=='query');bookSource?.classList.toggle('d-none',source!=='book');sourceActions?.classList.remove('d-none');if(queryInput)queryInput.disabled=source!=='query';if(input)input.disabled=source!=='book';updateAnalyzeButton();if(source==='query')queryInput?.focus()}
document.querySelectorAll('[data-source]').forEach(btn=>btn.addEventListener('click',()=>selectSource(btn.dataset.source)));
let selectedPhotos=[];
function syncPhotos(){
 if(!input)return;
 const transfer=new DataTransfer();
 selectedPhotos.forEach(file=>transfer.items.add(file));
 input.files=transfer.files;
 updateAnalyzeButton();
}
function addPhotos(files){
 const additions=[...files];
 for(const file of additions){
  if(!['image/jpeg','image/png','image/webp'].includes(file.type)){alert('Alleen JPG, PNG en WebP zijn toegestaan.');continue;}
  if(file.size>5*1024*1024){alert('Foto '+file.name+' is groter dan 5 MB.');continue;}
  if(selectedPhotos.length>=10){alert('Je kunt maximaal 10 boekpagina’s toevoegen.');break;}
  selectedPhotos.push(file);
 }
 syncPhotos();renderFiles();
}
function renderFiles(){
 if(!list)return;
 list.replaceChildren();
 selectedPhotos.forEach((file,index)=>{
  const item=document.createElement('div');item.className='upload-file d-flex align-items-center gap-2';
  const thumb=document.createElement('img');thumb.alt='';thumb.style.cssText='width:54px;height:54px;object-fit:cover;border-radius:6px;flex-shrink:0';
  const url=URL.createObjectURL(file);thumb.src=url;thumb.onload=()=>URL.revokeObjectURL(url);
  const details=document.createElement('div');details.className='flex-grow-1 min-w-0';
  const name=document.createElement('div');name.className='upload-file-name text-truncate';name.textContent=(index+1)+'. '+file.name;
  const size=document.createElement('div');size.className='small text-secondary';size.textContent=Math.round(file.size/1024)+' KB';
  details.append(name,size);
  const remove=document.createElement('button');remove.type='button';remove.className='btn btn-danger btn-sm flex-shrink-0';remove.textContent='×';remove.style.cssText='font-size:22px;line-height:1;width:34px;height:34px';remove.setAttribute('aria-label','Verwijder foto '+(index+1));
  remove.addEventListener('click',()=>{selectedPhotos.splice(index,1);syncPhotos();renderFiles()});
  item.append(thumb,details,remove);list.appendChild(item);
 });
 const counter=document.getElementById('photoCounter');
 if(counter)counter.textContent=selectedPhotos.length+' van 10 foto’s toegevoegd';
 updateAnalyzeButton();
}
input?.addEventListener('change',()=>{
 const files=[...input.files];
 // Laat de native iOS camera/kiezer opnieuw openen zonder de bestaande selectie te verliezen.
 addPhotos(files);
});
queryInput?.addEventListener('input',updateAnalyzeButton);
dropzone?.addEventListener('dragover',e=>{e.preventDefault();dropzone.classList.add('dragover')});
dropzone?.addEventListener('dragleave',()=>dropzone.classList.remove('dragover'));
dropzone?.addEventListener('drop',e=>{e.preventDefault();dropzone.classList.remove('dragover');addPhotos(e.dataTransfer.files)});

document.getElementById('addMorePhotos')?.addEventListener('click',()=>input?.click());
const savedSpecs=<?=json_encode($savedRequest['specs']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;const proposedSpecs=<?=json_encode(array_values(array_filter(array_map(static function($st){$types=(array)($st['recommended_types']??[]);return ['type'=>count($types)>1?'mixed':(in_array('mc',$types,true)?'mc':'open'),'count'=>max(1,min(100,(int)($st['question_count']??10)))];},(array)($analysis['subtests']??[])),static fn($x)=>$x['count']>0)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;const dualPracticeSubject=<?=json_encode($dualPracticeSubject)?>;const learningSubject=<?=json_encode($learningSubject)?>;
const expandDualSpecs=rows=>{if(!dualPracticeSubject)return rows;let mc=0;const out=[];for(const row of rows){if(row.type==='mixed'){out.push({type:'open',count:row.count});if(learningSubject)mc+=Number(row.count)||0;else out.push({type:'mc',count:row.count});}else if(row.type==='mc'&&learningSubject){mc+=Number(row.count)||0;}else out.push(row);}if(learningSubject&&mc>0)out.push({type:'mc',count:Math.min(100,mc)});return out;};
const specDefaults=savedSpecs.length?expandDualSpecs(savedSpecs):expandDualSpecs(proposedSpecs.length?proposedSpecs:[{type:'mixed',count:10}]);
function fillSpecs(container,rows){container.innerHTML='';rows.slice(0,20).forEach((row,i)=>{const wrap=document.createElement('div');wrap.className='row g-2 align-items-end spec-row mb-2';wrap.innerHTML='<div class="col-6 col-md-4"><label class="form-label small">Aantal vragen</label><input class="form-control" type="number" name="specs['+i+'][count]" min="1" max="100" value="'+Math.max(1,Math.min(100,Number(row.count)||10))+'" required></div><div class="col-6 col-md-5"><label class="form-label small">Type</label><select class="form-select" name="specs['+i+'][type]"><option value="mc" '+(row.type==='mc'?'selected':'')+'>Multiple choice</option><option value="open" '+(row.type==='open'?'selected':'')+'>Open vragen</option>'+(dualPracticeSubject?'':'<option value="mixed" '+(row.type==='mixed'?'selected':'')+'>Combinatie</option>')+'</select></div><div class="col-3"><button type="button" class="btn btn-outline-danger remove-spec" aria-label="Verwijder toetsvorm">×</button></div>';wrap.querySelector('.remove-spec').addEventListener('click',()=>{wrap.remove();renumberSpecs()});container.appendChild(wrap)})}
function renumberSpecs(){document.querySelectorAll('#specRowsAfter .spec-row').forEach((row,i)=>row.querySelectorAll('[name]').forEach(el=>el.name=el.name.replace(/specs\\[\\d+\\]/,'specs['+i+']')))}
const afterContainer=document.getElementById('specRowsAfter');if(afterContainer){fillSpecs(afterContainer,specDefaults);const add=document.createElement('button');add.type='button';add.className='btn btn-outline-secondary mt-2';add.textContent='+ Toetsvorm toevoegen';add.addEventListener('click',()=>{if(afterContainer.querySelectorAll('.spec-row').length>=20)return;const rows=[...afterContainer.querySelectorAll('.spec-row')].map(row=>({count:row.querySelector('[type=number]').value,type:row.querySelector('select').value}));rows.push({type:dualPracticeSubject?'open':'mixed',count:10});fillSpecs(afterContainer,rows)});afterContainer.after(add)}
const aiLoading=document.getElementById('aiLoading'),aiLoadingTitle=document.getElementById('aiLoadingTitle'),aiLoadingText=document.getElementById('aiLoadingText');let aiLoadingTimer=null;function showAiLoading(kind){if(!aiLoading)return;clearInterval(aiLoadingTimer);if(kind==='analyze'){const queryField=document.getElementById('query');const hasQuery=!!queryField&&queryField.value.trim()!=='';const hasPages=!!input&&!!input.files&&input.files.length>0;if(hasQuery&&!hasPages){aiLoadingTitle.textContent='Opdracht analyseren...';aiLoadingText.textContent='De AI werkt je onderwerp of opdracht uit. Dit kan even duren.'}else if(hasQuery&&hasPages){aiLoadingTitle.textContent='Toetsbron analyseren...';aiLoadingText.textContent='De AI verwerkt je opdracht en de boekpagina’s. Dit kan even duren.'}else{aiLoadingTitle.textContent='Boekpagina’s analyseren...';aiLoadingText.textContent='De AI leest de boekpagina’s. Dit kan even duren.'}}else if(kind==='generate'){aiLoadingTitle.textContent='Toets maken...';aiLoadingText.textContent='AI maakt de vragen en eventuele samenvatting.'}else{aiLoadingTitle.textContent='Toets opslaan...';const messages=['Leren verwerkt de gegenereerde vragen...','Leren controleert de gegenereerde SVG technisch...','Daarna worden de afbeeldingen veilig opgeslagen...','De afbeeldingen worden opgeslagen bij de vragen...','Bijna klaar — Leren rondt de toets af...'];let i=0;aiLoadingText.textContent=messages[0];aiLoadingTimer=setInterval(()=>{i=(i+1)%messages.length;aiLoadingText.textContent=messages[i]},2800)}aiLoading.classList.remove('d-none');document.body.style.overflow='hidden'}
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
      else if(data.method==='generate')text.textContent='Geen geschikte afbeelding gevonden — AI maakt een afbeelding.';

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