<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';
require_login();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
$collectionId=filter_input(INPUT_GET,'collection',FILTER_VALIDATE_INT);
if(!$id||!$collectionId){http_response_code(400);exit;}
$stmt=$pdo->prepare("SELECT r.x,r.y,r.width,r.height,p.image_path FROM ai_source_regions r JOIN ai_source_pages p ON p.id=r.page_id JOIN ai_source_collections c ON c.id=p.collection_id JOIN topics t ON t.id=c.topic_id WHERE r.id=? AND c.id=? AND t.is_active=1");
$stmt->execute([$id,$collectionId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$row){http_response_code(404);exit;}
$base=realpath(__DIR__.'/uploads/ai_sources');
$relative=(string)$row['image_path'];
$path=realpath(__DIR__.'/'.$relative);
if(!str_starts_with($relative,'uploads/ai_sources/')||!$base||!$path||!str_starts_with($path,$base.DIRECTORY_SEPARATOR)){http_response_code(404);exit;}
if(!extension_loaded('gd')){
    // Preserve a usable image on PHP installations without the GD extension.
    $mime=(string)(@mime_content_type($path)?:'');
    if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){http_response_code(404);exit;}
    header('Content-Type: '.$mime);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
$mime=(string)(@mime_content_type($path)?:'');
$src=match($mime){'image/jpeg'=>@imagecreatefromjpeg($path),'image/png'=>@imagecreatefrompng($path),'image/webp'=>@imagecreatefromwebp($path),default=>false};
if(!$src){http_response_code(404);exit;}
$iw=imagesx($src);$ih=imagesy($src);
$x=max(0,(int)floor((float)$row['x']*$iw));$y=max(0,(int)floor((float)$row['y']*$ih));
$w=min($iw-$x,max(1,(int)ceil((float)$row['width']*$iw)));
$h=min($ih-$y,max(1,(int)ceil((float)$row['height']*$ih)));
$crop=imagecrop($src,['x'=>$x,'y'=>$y,'width'=>$w,'height'=>$h]);
imagedestroy($src);
if(!$crop){http_response_code(404);exit;}
header('Content-Type: image/jpeg');header('Cache-Control: private, max-age=3600');header('X-Content-Type-Options: nosniff');
imagejpeg($crop,null,88);imagedestroy($crop);
