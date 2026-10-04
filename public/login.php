<?php
require __DIR__.'/../app/bootstrap.php';
if(is_admin())redirect('admin.php');
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 $s=$pdo->prepare("SELECT id,name,email,password_hash,role FROM users WHERE email=? AND role='admin' LIMIT 1");$s->execute([trim($_POST['email']??'')]);$user=$s->fetch();
 if($user&&password_verify($_POST['password']??'',$user['password_hash'])){session_regenerate_id(true);$_SESSION['user']=$user;redirect('admin.php');}
 $error='E-mailadres of wachtwoord is onjuist.';
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Beheer login</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-5" style="max-width:480px"><div class="card shadow-sm"><div class="card-body p-4"><h1 class="h3">Beheer</h1><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><form method="post"><label class="form-label">E-mail</label><input class="form-control mb-3" type="email" name="email" required autofocus><label class="form-label">Wachtwoord</label><input class="form-control mb-3" type="password" name="password" required><button class="btn btn-primary">Inloggen</button></form><a class="d-inline-block mt-3" href="index.php">Terug naar toetsen</a></div></div></main></body></html>
