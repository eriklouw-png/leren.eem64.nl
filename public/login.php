<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';

if(is_logged_in())redirect(is_manager()?'admin.php':'index.php');

$error='';
$next=(string)($_GET['next']??$_POST['next']??'index.php');
if($next==='' || !str_starts_with($next,'/'))$next='index.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $email=trim((string)($_POST['email']??''));
    $password=(string)($_POST['password']??'');
    if($email===''||$password===''){
        $error='Vul je e-mailadres en wachtwoord in.';
    }elseif(!auth_login_by_email($email,$password)){
        $error='E-mailadres of wachtwoord is niet juist.';
    }else{
        redirect($next);
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inloggen</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width:520px">
<div class="card shadow-sm">
<div class="card-body p-4">
<h1 class="h3 mb-4">Inloggen</h1>
<?php if($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="next" value="<?=e($next)?>">
<div class="mb-3">
<label class="form-label" for="email">E-mailadres</label>
<input class="form-control" id="email" name="email" type="email" autocomplete="email" required value="<?=e($_POST['email']??'')?>">
</div>
<div class="mb-3">
<label class="form-label" for="password">Wachtwoord</label>
<input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
</div>
<button class="btn btn-primary w-100" type="submit">Inloggen</button>
</form>
</div>
</div>
</main>
</body>
</html>
