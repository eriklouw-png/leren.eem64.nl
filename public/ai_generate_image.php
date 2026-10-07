<?php
require __DIR__.'/../app/bootstrap.php';
require_admin();

// Dit endpoint communiceert uitsluitend via JSON; PHP-waarschuwingen mogen de JSON-respons niet vervuilen.
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || ($_POST['action']??'')!=='next'){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Ongeldige aanvraag.']);
    exit;
}

$state=$_SESSION['ai_image_jobs']??null;
if(!is_array($state)||!isset($state['jobs'])||!is_array($state['jobs'])){
    echo json_encode(['ok'=>true,'done'=>true,'completed'=>0,'total'=>0,'saved_count'=>(int)($state['saved_count']??0)]);
    exit;
}

$jobs=$state['jobs'];
$total=(int)($state['total']??count($jobs));
if(!$jobs){
    $savedCount=(int)($state['saved_count']??0);
    unset($_SESSION['ai_image_jobs']);
    echo json_encode(['ok'=>true,'done'=>true,'completed'=>(int)($state['completed']??$total),'total'=>$total,'saved_count'=>$savedCount]);
    exit;
}

$job=array_shift($jobs);
$questionId=(int)($job['question_id']??0);
$questionText=trim((string)($job['question']??''));
$correctAnswer=trim((string)($job['correct_answer']??''));
$method=trim((string)($job['method']??''));
$searchQuery=trim((string)($job['search_query']??''));
$svgCode=trim((string)($job['svg_code']??''));
$prompt=trim((string)($job['prompt']??''));

if($questionId<1||!in_array($method,['web','svg','generate'],true)){
    $_SESSION['ai_image_jobs']['jobs']=$jobs;
    echo json_encode([
        'ok'=>false,
        'error'=>'Ongeldige afbeeldingsmethode in de taak.',
        'completed'=>(int)($state['completed']??0),
        'total'=>$total
    ]);
    exit;
}

function ai_validate_svg_quality(string $svg):bool{
    $svg=trim($svg);
    if($svg===''||strlen($svg)>150000)return false;
    if(stripos($svg,'<svg')===false||stripos($svg,'</svg>')===false)return false;
    foreach(['<script','<iframe','<object','<embed','<foreignobject','javascript:','onload=','onclick=','onerror=','url(','href="http://','href="https://','href=\'http://','href=\'https://'] as $blocked){
        if(stripos($svg,$blocked)!==false)return false;
    }

    if(!preg_match("/\\bviewBox\\s*=\\s*[\\\"']\\s*0\\s+0\\s+([0-9]+(?:\\.[0-9]+)?)\\s+([0-9]+(?:\\.[0-9]+)?)\\s*[\\\"']/i",$svg,$viewBox))return false;
    $viewWidth=(float)$viewBox[1];
    $viewHeight=(float)$viewBox[2];
    if($viewWidth<=0||$viewHeight<=0||$viewWidth>100000||$viewHeight>100000)return false;

    if(!preg_match("/<svg\\b[^>]*\\bwidth\\s*=\\s*[\\\"']1000[\\\"']/is",$svg))return false;
    if(!preg_match("/<svg\\b[^>]*\\bheight\\s*=\\s*[\\\"']1000[\\\"']/is",$svg))return false;

    if(class_exists('DOMDocument')){
        $previous=libxml_use_internal_errors(true);
        $dom=new DOMDocument('1.0','UTF-8');
        $loaded=$dom->loadXML($svg,LIBXML_NONET|LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if(!$loaded||!$dom->documentElement||strtolower($dom->documentElement->localName)!=='svg')return false;

        $drawable=0;
        foreach(['circle','ellipse','line','polyline','polygon','path','rect','text','g'] as $tag){
            $drawable+=$dom->getElementsByTagName($tag)->length;
        }
        if($drawable<2)return false;
    }
    return true;
}

try{
    $questionCheck=$pdo->prepare("SELECT q.id,q.image_path,t.topic_id FROM questions q JOIN tests t ON t.id=q.test_id WHERE q.id=? AND t.topic_id=? LIMIT 1");
    $questionCheck->execute([$questionId,(int)($state['topic_id']??0)]);
    $question=$questionCheck->fetch();
    if(!$question)throw new RuntimeException('De vraag waarvoor de afbeelding bedoeld is bestaat niet meer.');

    $dir=__DIR__.'/uploads/questions';
    $filename=null;

    // Voer uitsluitend de door de AI gekozen methode uit.
    if($method==='web'){
        if($searchQuery==='')throw new RuntimeException('De web-afbeeldingstaak bevat geen zoekopdracht.');
        $webImage=openai_search_image($searchQuery);
        if(is_array($webImage)){
            $imageUrl=trim((string)($webImage['image_url']??''));
            if($imageUrl!=='')$filename=openai_download_web_image($imageUrl,$dir);
        }
        if($filename===null)throw new RuntimeException('Geen geschikte internetafbeelding gevonden of gedownload.');
    }elseif($method==='svg'){
        if($svgCode==='')throw new RuntimeException('De SVG-afbeeldingstaak bevat geen SVG-code.');
        if(!ai_validate_svg_quality($svgCode))throw new RuntimeException('De gegenereerde SVG voldoet niet aan de technische kwaliteitscontroles. Probeer deze afbeelding opnieuw.');
        $filename=ai_save_svg($svgCode,$dir);
        if($filename===null)throw new RuntimeException('De gegenereerde SVG kon niet veilig worden opgeslagen.');
    }elseif($method==='generate'){
        if($prompt==='')throw new RuntimeException('De AI-afbeeldingstaak bevat geen image_prompt.');
        $filename=openai_generate_image($prompt,$dir);
        if($filename===null)throw new RuntimeException('De AI-afbeelding kon niet worden gemaakt.');
    }

    if($filename===null)throw new RuntimeException('Er kon geen geschikte afbeelding worden gevonden of gemaakt.');

    // Naast de technische controle controleren we nu inhoudelijk of de afbeelding
    // daadwerkelijk bij de vraag en het juiste antwoord past.
    if($questionText!==''&&$correctAnswer!==''){
        $validation=openai_validate_educational_image($questionText,$correctAnswer,$dir.'/'.$filename,$method);
        if(isset($validation['_leren_error'])&&$validation['_leren_error']!==''){
            @unlink($dir.'/'.$filename);
            throw new RuntimeException($validation['_leren_error']);
        }
        if(empty($validation['valid'])){
            $reason=trim((string)($validation['reason']??''));
            $rejectedFile=$dir.'/'.$filename;

            // Corrigeer eerst de bestaande afbeelding gericht. GPT krijgt daarmee
            // zowel de afbeelding als de concrete foutmelding. Dat is veel
            // betrouwbaarder voor bijvoorbeeld kaarten, klokken en diagrammen.
            $editPrompt='Corrigeer deze educatieve afbeelding. Behoud de bestaande afbeelding en verander alleen wat inhoudelijk fout is. Maak geen willekeurige nieuwe afbeelding en voeg geen onnodige decoratie toe. De uiteindelijke afbeelding moet exact overeenkomen met de schoolvraag en het juiste antwoord.';
            if($reason!=='')$editPrompt.=' De controle vond deze fout: '.$reason;
            $editPrompt.=' VRAAG: '.$questionText.' JUISTE ANTWOORD: '.$correctAnswer;

            $edited=openai_edit_image($rejectedFile,$editPrompt,$dir);
            if($edited!==null){
                $editedValidation=openai_validate_educational_image($questionText,$correctAnswer,$dir.'/'.$edited,'generate');
                if(!empty($editedValidation['valid'])){
                    @unlink($rejectedFile);
                    $filename=$edited;
                    $method='generate';
                }else{
                    @unlink($dir.'/'.$edited);
                    $edited=null;
                }
            }

            if($edited===null){
                @unlink($rejectedFile);

                // Alleen als gericht bewerken niet lukt, proberen we één volledig
                // nieuwe afbeelding te maken met de concrete afkeuringsreden.
                $fallbackPrompt=trim($prompt);
                if($fallbackPrompt==='')$fallbackPrompt='Maak een eenvoudige educatieve afbeelding die deze schoolvraag correct ondersteunt: '.$questionText.' Het juiste antwoord is: '.$correctAnswer.'. Zorg dat alle relevante posities, relaties, labels en geografische of numerieke informatie inhoudelijk correct zijn. Vermijd decoratie die niet nodig is.';
                $fallbackPrompt.=' De vorige afbeelding werd inhoudelijk afgekeurd. Corrigeer expliciet deze fout: '.($reason!==''?$reason:'de afbeelding moet inhoudelijk exact overeenkomen met de vraag en het juiste antwoord').'.';

                $fallback=openai_generate_image($fallbackPrompt,$dir);
                if($fallback!==null){
                    $fallbackValidation=openai_validate_educational_image($questionText,$correctAnswer,$dir.'/'.$fallback,'generate');
                    if(!empty($fallbackValidation['valid'])){
                        $filename=$fallback;
                        $method='generate';
                    }else{
                        @unlink($dir.'/'.$fallback);
                        $fallbackReason=trim((string)($fallbackValidation['reason']??''));
                        throw new RuntimeException('De afbeelding werd inhoudelijk afgekeurd en de vervangende afbeelding voldeed ook niet aan de controle.'.($fallbackReason!==''?' '.$fallbackReason:''));
                    }
                }else{
                    throw new RuntimeException('De afbeelding werd inhoudelijk afgekeurd. '.($reason!==''?$reason:'De afbeelding past niet betrouwbaar bij de vraag.'));
                }
            }
        }
    }

    $update=$pdo->prepare("UPDATE questions SET image_path=? WHERE id=?");
    $update->execute([$filename,$questionId]);

    $completed=(int)($state['completed']??0)+1;
    $_SESSION['ai_image_jobs']['jobs']=$jobs;
    $_SESSION['ai_image_jobs']['completed']=$completed;

    $done=count($jobs)===0;
    $savedCount=(int)($state['saved_count']??0);
    if($done)unset($_SESSION['ai_image_jobs']);

    echo json_encode([
        'ok'=>true,
        'done'=>$done,
        'completed'=>$completed,
        'total'=>$total,
        'saved_count'=>$savedCount,
        'method'=>$method
    ]);
}catch(Throwable $e){
    // Put the current job back so the user can retry without losing progress.
    array_unshift($jobs,$job);
    $_SESSION['ai_image_jobs']['jobs']=$jobs;
    echo json_encode([
        'ok'=>false,
        'error'=>$e->getMessage(),
        'completed'=>(int)($state['completed']??0),
        'total'=>$total
    ]);
}
