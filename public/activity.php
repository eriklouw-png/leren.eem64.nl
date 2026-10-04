<?php
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}

$action=$_POST['action']??'heartbeat';
$token=$_SESSION['study_session_token']??null;

if($action==='start'){
    $token=bin2hex(random_bytes(32));
    $_SESSION['study_session_token']=$token;
    $testId=filter_var($_POST['test_id']??null,FILTER_VALIDATE_INT);
    if(!$testId){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'missing_test']);exit;}
    $attemptId=filter_var($_POST['attempt_id']??null,FILTER_VALIDATE_INT)?:null;
    $s=$pdo->prepare("INSERT INTO study_sessions(session_token,test_id,attempt_id) VALUES(?,?,?)");
    $s->execute([$token,$testId,$attemptId]);
    echo json_encode(['ok'=>true]);
    exit;
}

if(!$token){echo json_encode(['ok'=>false,'error'=>'no_session']);exit;}

if($action==='heartbeat'){
    $active=($_POST['active']??'0')==='1';
    $s=$pdo->prepare("SELECT id,last_activity_at,ended_at FROM study_sessions WHERE session_token=?");
    $s->execute([$token]);$session=$s->fetch();
    if(!$session||$session['ended_at']!==null){echo json_encode(['ok'=>false]);exit;}
    $now=new DateTimeImmutable();
    $last=new DateTimeImmutable($session['last_activity_at']);
    $delta=max(0,min(30,$now->getTimestamp()-$last->getTimestamp()));
    $add=$active?$delta:0;
    $u=$pdo->prepare("UPDATE study_sessions SET last_activity_at=NOW(),active_seconds=active_seconds+? WHERE id=?");
    $u->execute([$add,$session['id']]);
    echo json_encode(['ok'=>true,'added'=>$add]);
    exit;
}

if($action==='end'){
    $s=$pdo->prepare("UPDATE study_sessions SET ended_at=NOW(),last_activity_at=NOW() WHERE session_token=? AND ended_at IS NULL");
    $s->execute([$token]);
    unset($_SESSION['study_session_token']);
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400);echo json_encode(['ok'=>false,'error'=>'invalid_action']);
