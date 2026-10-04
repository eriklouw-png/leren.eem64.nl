<?php
require __DIR__.'/../app/bootstrap.php';
$_SESSION=[];if(ini_get('session.use_cookies')){setcookie(session_name(),'',['expires'=>time()-42000,'path'=>'','secure'=>!empty($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Lax']);}session_destroy();redirect('index.php');
