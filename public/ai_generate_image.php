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
$total=count($jobs);
if(!$jobs){
    $savedCount=(int)($state['saved_count']??0);
    unset($_SESSION['ai_image_jobs']);
    echo json_encode(['ok'=>true,'done'=>true,'completed'=>$total,'total'=>$total,'saved_count'=>$savedCount]);
    exit;
}

$job=array_shift($jobs);
$questionId=(int)($job['question_id']??0);
$prompt=trim((string)($job['prompt']??''));
if($questionId<1||$prompt===''){
    $_SESSION['ai_image_jobs']['jobs']=$jobs;
    $_SESSION['ai_image_jobs']['completed']=(int)($state['completed']??0)+1;
    echo json_encode(['ok'=>false,'error'=>'Een afbeeldingstaak is ongeldig.']);
    exit;
}

try{
    $questionCheck=$pdo->prepare("SELECT q.id,q.image_path,t.topic_id FROM questions q JOIN tests t ON t.id=q.test_id WHERE q.id=? AND t.topic_id=? LIMIT 1");
    $questionCheck->execute([$questionId,(int)($state['topic_id']??0)]);
    $question=$questionCheck->fetch();
    if(!$question)throw new RuntimeException('De vraag waarvoor de afbeelding bedoeld is bestaat niet meer.');

    $dir=__DIR__.'/uploads/questions';
    $filename=openai_generate_image($prompt,$dir);
    if(!$filename)throw new RuntimeException('OpenAI kon de afbeelding niet genereren.');

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
        'saved_count'=>$savedCount
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
