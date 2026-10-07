<?php
require __DIR__.'/../app/bootstrap.php';
require_admin();

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
$searchQuery=trim((string)($job['search_query']??''));
$svgCode=trim((string)($job['svg_code']??''));
$prompt=trim((string)($job['prompt']??''));

if($questionId<1||($searchQuery===''&&$svgCode===''&&$prompt==='')){
    $_SESSION['ai_image_jobs']['jobs']=$jobs;
    echo json_encode([
        'ok'=>false,
        'error'=>'Een afbeeldingstaak bevat geen zoekopdracht, SVG of image_prompt.',
        'completed'=>(int)($state['completed']??0),
        'total'=>$total
    ]);
    exit;
}

try{
    $questionCheck=$pdo->prepare("SELECT q.id,q.image_path,t.topic_id FROM questions q JOIN tests t ON t.id=q.test_id WHERE q.id=? AND t.topic_id=? LIMIT 1");
    $questionCheck->execute([$questionId,(int)($state['topic_id']??0)]);
    $question=$questionCheck->fetch();
    if(!$question)throw new RuntimeException('De vraag waarvoor de afbeelding bedoeld is bestaat niet meer.');

    $dir=__DIR__.'/uploads/questions';
    $filename=null;
    $method='';

    // 1. Eerst op internet zoeken. Dit is de voorkeursroute voor echte,
    // inhoudelijk passende afbeeldingen.
    if($filename===null&&$searchQuery!==''){
        $webImage=openai_search_image($searchQuery);
        if(is_array($webImage)){
            $imageUrl=trim((string)($webImage['image_url']??''));
            if($imageUrl!==''){
                $filename=openai_download_web_image($imageUrl,$dir);
                if($filename!==null)$method='web';
            }
        }
    }

    // 2. Als er geen geschikte internetafbeelding gevonden of gedownload
    // kon worden, probeer een eenvoudige veilige SVG.
    if($filename===null&&$svgCode!==''){
        $filename=ai_save_svg($svgCode,$dir);
        if($filename!==null)$method='svg';
    }

    // 3. Laatste fallback: een nieuwe afbeelding met GPT genereren.
    if($filename===null){
        if($prompt==='')throw new RuntimeException('Er is geen geschikte internetafbeelding gevonden en er is geen image_prompt voor de laatste fallback.');
        $filename=openai_generate_image($prompt,$dir);
        if($filename!==null)$method='generate';
    }

    if($filename===null)throw new RuntimeException('Er kon geen geschikte afbeelding worden gevonden of gemaakt.');

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
