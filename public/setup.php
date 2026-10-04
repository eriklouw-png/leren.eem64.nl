<?php
require __DIR__.'/../app/bootstrap.php';
$count=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
if($count>0){http_response_code(403);exit('Er bestaat al een admin-account. Verwijder setup.php of log in via login.php.');}
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 $name=trim($_POST['name']??'');$email=trim($_POST['email']??'');$password=$_POST['password']??'';
 if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<8)$error='Vul alle velden correct in. Het wachtwoord moet minimaal 8 tekens bevatten.';
 else{$s=$pdo->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'admin')");$s->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);redirect('login.php');}
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Eerste admin</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-5" style="max-width:520px"><div class="card shadow-sm"><div class="card-body p-4"><h1 class="h3">Eerste beheerder</h1><p class="text-secondary">Maak het eerste admin-account aan.</p><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><form method="post"><label class="form-label">Naam</label><input class="form-control mb-3" name="name" required><label class="form-label">E-mail</label><input class="form-control mb-3" type="email" name="email" required><label class="form-label">Wachtwoord</label><input class="form-control mb-3" type="password" name="password" minlength="8" required><button class="btn btn-primary">Admin aanmaken</button></form></div></div></main></body></html>
