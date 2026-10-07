<?php
require __DIR__.'/../app/bootstrap.php';
require_login();

$currentUser=current_user();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:0;
if(!$id)$id=(int)$currentUser['id'];

if($currentUser['role']!=='admin' && $id!==(int)$currentUser['id']){
    http_response_code(403);
    exit('Geen toegang.');
}
$student=null;
if($id){
    $x=$pdo->prepare("SELECT id,name,email,image_mime FROM users WHERE id=? AND role='student'");
    $x->execute([$id]);
    $student=$x->fetch();
    if(!$student){http_response_code(404);exit('Student niet gevonden.');}
}

$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'save';
    $postId=filter_var($_POST['id']??null,FILTER_VALIDATE_INT)?:0;
    if(!$postId){http_response_code(400);exit('Ongeldig ID.');}

    if($action==='delete_image'){
        $x=$pdo->prepare("UPDATE users SET image_mime=NULL,image_data=NULL WHERE id=? AND role='student'");
        $x->execute([$postId]);
        redirect('user_edit.php?id='.$postId);
    }

    $name=trim((string)($_POST['name']??''));
    $email=trim((string)($_POST['email']??''));
    if($name===''){
        $error='Vul een naam in.';
    }elseif($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)){
        $error='Vul een geldig e-mailadres in.';
    }else{
        try{
            $x=$pdo->prepare("UPDATE users SET name=?,email=? WHERE id=? AND role='student'");
            $x->execute([$name,$email,$postId]);

            if(isset($_FILES['image']) && $_FILES['image']['error']!==UPLOAD_ERR_NO_FILE){
                if($_FILES['image']['error']!==UPLOAD_ERR_OK){
                    $error='De afbeelding kon niet worden geüpload.';
                }elseif((int)$_FILES['image']['size']>5*1024*1024){
                    $error='De afbeelding is groter dan 5 MB.';
                }else{
                    $info=@getimagesize($_FILES['image']['tmp_name']);
                    $allowed=['image/jpeg','image/png','image/webp'];
                    $mime=$info['mime']??'';
                    if(!$info || !in_array($mime,$allowed,true)){
                        $error='Gebruik een JPG-, PNG- of WebP-afbeelding.';
                    }else{
                        $data=file_get_contents($_FILES['image']['tmp_name']);
                        if($data===false){
                            $error='De afbeelding kon niet worden gelezen.';
                        }else{
                            $x=$pdo->prepare("UPDATE users SET image_mime=?,image_data=? WHERE id=? AND role='student'");
                            $x->execute([$mime,$data,$postId]);
                        }
                    }
                }
            }

            if(!$error)redirect('student.php?id='.$postId);
            $x=$pdo->prepare("SELECT id,name,email,image_mime FROM users WHERE id=? AND role='student'");
            $x->execute([$postId]);$student=$x->fetch();
        }catch(PDOException $e){
            if((int)($e->errorInfo[1]??0)===1062)$error='Dit e-mailadres is al in gebruik.';
            else throw $e;
        }
    }
}
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gebruiker bewerken - Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark">
<div class="container"><a class="navbar-brand" href="admin.php">Leren beheer</a></div>
</nav>
<main class="container py-4" style="max-width:800px">
<a href="student.php?id=<?=$id?>">&larr; Terug naar student</a>
<h1 class="mt-3">Gebruiker bewerken</h1>

<?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>

<div class="card shadow-sm">
<div class="card-body">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" value="<?=$id?>">

<div class="mb-3">
<label class="form-label">Naam</label>
<input class="form-control" name="name" value="<?=e($student['name']??'')?>" required maxlength="150">
</div>

<div class="mb-3">
<label class="form-label">E-mailadres</label>
<input class="form-control" type="email" name="email" value="<?=e($student['email']??'')?>" required maxlength="190">
</div>

<div class="mb-3">
<label class="form-label">Afbeelding</label>
<?php if(!empty($student['image_mime'])):?>
<div class="mb-2">
<img src="student_image.php?id=<?=$id?>" class="rounded-circle" style="width:140px;height:140px;object-fit:cover" alt="">
</div>
<?php endif;?>
<input class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp">
<div class="form-text">JPG, PNG of WebP. Maximaal 5 MB.</div>
<?php if(!empty($student['image_mime'])):?>
<button class="btn btn-outline-danger btn-sm mt-2" type="submit" name="action" value="delete_image" formnovalidate>Afbeelding verwijderen</button>
<?php endif;?>
</div>

<button class="btn btn-primary" type="submit">Opslaan</button>
<a class="btn btn-outline-secondary" href="student.php?id=<?=$id?>">Annuleren</a>
</form>
</div>
</div>
</main>
</body>
</html>