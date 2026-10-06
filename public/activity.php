<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}

$currentUser=current_user();
$studentId=(int)$currentUser['id'];
$action=$_POST['action']??'heartbeat';
$token=$_SESSION['study_session_token']??null;

if($action==='start'){
    $token=bin2hex(random_bytes(32));
    $_SESSION['study_session_token']=$token;

    $testId=filter_var($_POST['test_id']??null,FILTER_VALIDATE_INT)?:null;
    $summaryId=filter_var($_POST['summary_id']??null,FILTER_VALIDATE_INT)?:null;
    $attemptId=filter_var($_POST['attempt_id']??null,FILTER_VALIDATE_INT)?:null;

    if($testId && $summaryId){
        http_response_code(400);echo json_encode(['ok'=>false,'error'=>'multiple_activity_targets']);exit;
    }

    if($testId){
        $x=$pdo->prepare("
            SELECT t.id
            FROM tests t
            LEFT JOIN attempts a ON a.id=?
            WHERE t.id=? AND (t.is_active=1 OR a.mode='mistakes')
        ");
        $x->execute([$attemptId,$testId]);
        if(!$x->fetch()){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'test_not_found']);exit;}

        $s=$pdo->prepare("INSERT INTO study_sessions(session_token,student_id,test_id,activity_type,summary_id,attempt_id) VALUES(?,?,?,'test',NULL,?)");
        $s->execute([$token,$studentId,$testId,$attemptId]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if($summaryId){
        $x=$pdo->prepare("
            SELECT ts.id
            FROM topic_summaries ts
            JOIN topics tp ON tp.id=ts.topic_id
            WHERE ts.id=? AND ts.is_active=1 AND tp.is_active=1
        ");
        $x->execute([$summaryId]);
        if(!$x->fetch()){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'summary_not_found']);exit;}

        $s=$pdo->prepare("INSERT INTO study_sessions(session_token,student_id,test_id,activity_type,summary_id,attempt_id) VALUES(?,?,NULL,'summary',?,NULL)");
        $s->execute([$token,$studentId,$summaryId]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    http_response_code(400);echo json_encode(['ok'=>false,'error'=>'missing_activity_target']);exit;
}

if(!$token){echo json_encode(['ok'=>false,'error'=>'no_session']);exit;}

if($action==='heartbeat'){
    $active=($_POST['active']??'0')==='1';
    $s=$pdo->prepare("SELECT id,last_activity_at,ended_at FROM study_sessions WHERE session_token=? AND student_id=?");
    $s->execute([$token,$studentId]);$session=$s->fetch();
    if(!$session||$session['ended_at']!==null){echo json_encode(['ok'=>false]);exit;}

    $now=new DateTimeImmutable();
    $last=new DateTimeImmutable($session['last_activity_at']);
    $delta=max(0,min(30,$now->getTimestamp()-$last->getTimestamp()));
    $add=$active?$delta:0;

    $u=$pdo->prepare("UPDATE study_sessions SET last_activity_at=NOW(),active_seconds=active_seconds+? WHERE id=? AND student_id=?");
    $u->execute([$add,$session['id'],$studentId]);
    echo json_encode(['ok'=>true,'added'=>$add]);
    exit;
}

if($action==='end'){
    $s=$pdo->prepare("UPDATE study_sessions SET ended_at=NOW(),last_activity_at=NOW() WHERE session_token=? AND student_id=? AND ended_at IS NULL");
    $s->execute([$token,$studentId]);
    unset($_SESSION['study_session_token']);
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400);echo json_encode(['ok'=>false,'error'=>'invalid_action']);
