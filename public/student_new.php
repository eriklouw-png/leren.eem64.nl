<?php
require __DIR__.'/../app/bootstrap.php';
require_manager();

$error=null;

$managers=[];
if(is_admin()){
    $managers=$pdo->query("SELECT id,name,email FROM users WHERE role='beheerder' ORDER BY name")->fetchAll();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $name=trim((string)($_POST['name']??''));
    $email=trim((string)($_POST['email']??''));
    $password=(string)($_POST['password']??'');
    $passwordConfirm=(string)($_POST['password_confirm']??'');
    $managerId=filter_var($_POST['manager_id']??null,FILTER_VALIDATE_INT)?:0;

    if($name===''){
        $error='Vul een naam in.';
    }elseif($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)){
        $error='Vul een geldig e-mailadres in.';
    }elseif(strlen($password)<8){
        $error='Het wachtwoord moet minimaal 8 tekens bevatten.';
    }elseif($password!==$passwordConfirm){
        $error='De wachtwoorden komen niet overeen.';
    }elseif(is_admin() && $managerId>0){
        $x=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='beheerder'");
        $x->execute([$managerId]);
        if(!$x->fetchColumn())$error='De gekozen beheerder bestaat niet.';
    }

    if(!$error){
        try{
            $pdo->beginTransaction();

            $x=$pdo->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'student')");
            $x->execute([$name,mb_strtolower($email,'UTF-8'),password_hash($password,PASSWORD_DEFAULT)]);
            $studentId=(int)$pdo->lastInsertId();

            if(is_admin() && $managerId>0){
                $x=$pdo->prepare("INSERT INTO manager_students(manager_id,student_id) VALUES(?,?)");
                $x->execute([$managerId,$studentId]);
            }elseif(is_manager()){
                $managerId=(int)($_SESSION['user']['id']??0);
                if($managerId){
                    $x=$pdo->prepare("INSERT INTO manager_students(manager_id,student_id) VALUES(?,?)");
                    $x->execute([$managerId,$studentId]);
                }
            }

            $pdo->commit();
            redirect('user_edit.php?id='.$studentId.'&created=1');
        }catch(PDOException $e){
            if($pdo->inTransaction())$pdo->rollBack();
            if((int)($e->errorInfo[1]??0)===1062){
                $error='Dit e-mailadres is al in gebruik.';
            }else{
                throw $e;
            }
        }
    }
}
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nieuwe student - Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-4">
<a href="admin.php">&larr; Terug naar beheer</a>

<div class="d-flex justify-content-between align-items-center mt-3 mb-4">
<h1 class="mb-0">Nieuwe student</h1>
</div>

<?php if($error):?>
<div class="alert alert-danger"><?=e($error)?></div>
<?php endif;?>

<section class="leren-section">
<div class="card">
<div class="card-body">
<form method="post" autocomplete="off">
<div class="mb-3">
<label class="form-label" for="name">Naam</label>
<input class="form-control" id="name" name="name" value="<?=e($_POST['name']??'')?>" maxlength="120" required>
</div>

<div class="mb-3">
<label class="form-label" for="email">E-mailadres</label>
<input class="form-control" id="email" name="email" type="email" value="<?=e($_POST['email']??'')?>" maxlength="190" autocomplete="email" required>
</div>

<div class="mb-3">
<label class="form-label" for="password">Wachtwoord</label>
<input class="form-control" id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
<div class="form-text">Minimaal 8 tekens.</div>
</div>

<div class="mb-3">
<label class="form-label" for="password_confirm">Wachtwoord opnieuw</label>
<input class="form-control" id="password_confirm" name="password_confirm" type="password" minlength="8" autocomplete="new-password" required>
</div>

<?php if(is_admin()):?>
<div class="mb-4">
<label class="form-label" for="manager_id">Beheerder</label>
<select class="form-select" id="manager_id" name="manager_id">
<option value="0">Nog niet gekoppeld</option>
<?php foreach($managers as $manager):?>
<option value="<?=e((string)$manager['id'])?>" <?=((int)($_POST['manager_id']??0)===(int)$manager['id']?'selected':'')?>><?=e($manager['name'])?> — <?=e($manager['email'])?></option>
<?php endforeach;?>
</select>
<div class="form-text">Je kunt de student ook later via Gebruikers &amp; rechten aan een beheerder koppelen.</div>
</div>
<?php endif;?>

<div class="d-flex flex-wrap gap-2">
<button class="btn btn-primary" type="submit">Student aanmaken</button>
<a class="btn btn-outline-secondary" href="admin.php">Annuleren</a>
</div>
</form>
</div>
</div>
</section>
</main>
</body>
</html>