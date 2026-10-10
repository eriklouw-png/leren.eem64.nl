<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';
require_login();
$user=current_user();
$studentId=(int)$user['id'];
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Methode niet toegestaan.');}
if(!hash_equals((string)($_SESSION['grade_csrf']??''),(string)($_POST['csrf']??''))){http_response_code(403);exit('Ongeldige sessie.');}
$action=(string)($_POST['action']??'');
$topicId=filter_var($_POST['topic_id']??null,FILTER_VALIDATE_INT);
$back=(string)($_POST['return_to']??'index.php');
if(!preg_match('~^(index\.php|subject\.php\?id=[0-9]+)$~',$back))$back='index.php';
if($topicId){
    $ownerCheck=$pdo->prepare('SELECT s.user_id FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?');
    $ownerCheck->execute([$topicId]);
    if((int)$ownerCheck->fetchColumn()!==$studentId){http_response_code(403);exit('Geen toegang.');}
}
if($action==='later' && $topicId){
    $_SESSION['grade_later'][(int)$topicId]=date('Y-m-d');
    redirect($back);
}
if($action==='save' && $topicId){
    $gradeText=str_replace(',','.',trim((string)($_POST['grade']??'')));
    if(!preg_match('/^(?:[1-9](?:\.[0-9])?|10(?:\.0)?|[0](?:\.[0-9])?)$/',$gradeText)){
        http_response_code(422);exit('Vul een cijfer tussen 1,0 en 10,0 in (maximaal één decimaal).');
    }
    $check=$pdo->prepare("SELECT id FROM topics WHERE id=? AND is_active=1 AND test_date<CURDATE()");
    $check->execute([$topicId]);
    if(!$check->fetchColumn()){http_response_code(400);exit('Deze overhoring is niet afgerond.');}
    $save=$pdo->prepare("INSERT INTO topic_grades(topic_id,student_id,grade) VALUES(?,?,?) ON DUPLICATE KEY UPDATE grade=VALUES(grade),recorded_at=CURRENT_TIMESTAMP");
    $save->execute([$topicId,$studentId,(float)$gradeText]);
    unset($_SESSION['grade_later'][(int)$topicId]);
    redirect($back);
}
if($action==='historical'){
    $subjectId=filter_var($_POST['subject_id']??null,FILTER_VALIDATE_INT);
    $name=trim((string)($_POST['topic_name']??''));
    $date=(string)($_POST['test_date']??'');
    $gradeText=str_replace(',','.',trim((string)($_POST['grade']??'')));
    if(!$subjectId || $name==='' || mb_strlen($name)>150 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)) || $date>=date('Y-m-d') || !preg_match('/^(?:[1-9](?:\.[0-9])?|10(?:\.0)?|[0](?:\.[0-9])?)$/',$gradeText)){
        http_response_code(422);exit('Controleer titel, datum in het verleden en cijfer (0,0 t/m 10,0).');
    }
    $check=$pdo->prepare('SELECT id FROM subjects WHERE id=? AND user_id=?');$check->execute([$subjectId,$studentId]);
    if(!$check->fetchColumn()){http_response_code(404);exit('Vak niet gevonden.');}
    $pdo->beginTransaction();
    try{
        $create=$pdo->prepare("INSERT INTO topics(subject_id,name,test_date,is_active) VALUES(?,?,?,1)");
        $create->execute([$subjectId,$name,$date]);
        $newTopic=(int)$pdo->lastInsertId();
        $save=$pdo->prepare("INSERT INTO topic_grades(topic_id,student_id,grade) VALUES(?,?,?)");
        $save->execute([$newTopic,$studentId,(float)$gradeText]);
        $pdo->commit();
    }catch(Throwable $e){
        $pdo->rollBack();
        if($e instanceof PDOException && $e->getCode()==='23000'){http_response_code(409);exit('Deze overhoring bestaat al binnen dit vak.');}
        throw $e;
    }
    redirect('subject.php?id='.$subjectId);
}
http_response_code(400);exit('Ongeldige actie.');
