<?php
require __DIR__.'/../app/bootstrap.php';

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id){http_response_code(400);exit;}

$s=$pdo->prepare("SELECT image_mime,image_data FROM subjects WHERE id=?");
$s->execute([$id]);
$row=$s->fetch();

if(!$row || !$row['image_data'] || !$row['image_mime']){
    http_response_code(404);exit;
}

header('Content-Type: '.(string)$row['image_mime']);
header('Cache-Control: public, max-age=86400');
echo $row['image_data'];
