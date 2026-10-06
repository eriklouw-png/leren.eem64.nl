<?php
require __DIR__.'/../app/bootstrap.php';
require_admin();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id){http_response_code(400);exit('Ongeldig ID.');}
$x=$pdo->prepare("SELECT image_mime,image_data FROM users WHERE id=? AND role='student'");
$x->execute([$id]);$user=$x->fetch();
if(!$user || !$user['image_mime'] || !$user['image_data']){http_response_code(404);exit;}
header('Content-Type: '.$user['image_mime']);
header('Cache-Control: private, max-age=3600');
echo $user['image_data'];
