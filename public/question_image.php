<?php
require __DIR__.'/../app/bootstrap.php';

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id){http_response_code(400);exit;}

$s=$pdo->prepare("SELECT image_path FROM questions WHERE id=?");
$s->execute([$id]);
$path=$s->fetchColumn();

if(!$path || str_contains($path,'..') || str_starts_with($path,'/') || !preg_match('/^[A-Za-z0-9._\/-]+$/',(string)$path)){
    http_response_code(404);exit;
}

$file=__DIR__.'/uploads/questions/'.str_replace('/','/',(string)$path);
if(!is_file($file)){http_response_code(404);exit;}

$finfo=new finfo(FILEINFO_MIME_TYPE);
$mime=$finfo->file($file);
$allowed=['image/jpeg','image/png','image/webp','image/gif'];
if(!in_array($mime,$allowed,true)){http_response_code(404);exit;}

header('Content-Type: '.$mime);
header('Cache-Control: public, max-age=86400');
readfile($file);
