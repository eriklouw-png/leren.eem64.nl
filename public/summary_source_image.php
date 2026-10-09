<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';
require_login();

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
$page=filter_input(INPUT_GET,'page',FILTER_VALIDATE_INT);
if(!$id||!$page||$page<1||$page>10){http_response_code(400);exit;}
$stmt=$pdo->prepare("SELECT ts.id,tp.id topic_id FROM topic_summaries ts JOIN topics tp ON tp.id=ts.topic_id WHERE ts.id=? AND ts.is_active=1 AND tp.is_active=1");
$stmt->execute([$id]);
$row=$stmt->fetch();
if(!$row){http_response_code(404);exit;}
$directory=__DIR__.'/../storage/summaries/'.(int)$row['topic_id'].'/'.(int)$row['id'];
$files=[];
foreach(glob($directory.'/*')?:[] as $file){
    if(!is_file($file))continue;
    $mime=(string)(@mime_content_type($file)?:'');
    if(in_array($mime,['image/jpeg','image/png','image/webp'],true))$files[]=['file'=>$file,'mime'=>$mime];
}
sort($files);
$selected=$files[$page-1]??null;
if(!$selected){http_response_code(404);exit;}
header('Content-Type: '.$selected['mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($selected['file']);
