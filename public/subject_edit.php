<?php
require __DIR__.'/../app/bootstrap.php';
require_manager();

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:0;
$subject=null;
if(is_admin()){
    $owners=$pdo->query("SELECT id,name FROM users WHERE role IN ('student','beheerder','admin') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}else{
    $allowedIds=array_unique(array_merge([(int)$_SESSION['user']['id']],managed_student_ids()));
    $ph=implode(',',array_fill(0,count($allowedIds),'?'));
    $ownerQuery=$pdo->prepare("SELECT id,name FROM users WHERE id IN ($ph) ORDER BY name");
    $ownerQuery->execute(array_values($allowedIds));
    $owners=$ownerQuery->fetchAll(PDO::FETCH_ASSOC);
}
$ownerIds=array_map('intval',array_column($owners,'id'));
$ownerId=(int)($_POST['user_id']??($owners[0]['id']??0));
if($id){
    $x=$pdo->prepare("SELECT id,name,image_mime,user_id FROM subjects WHERE id=?");
    $x->execute([$id]);
    $subject=$x->fetch();
    if(!$subject){http_response_code(404);exit('Vak niet gevonden.');}
    require_subject_management($id);
    $ownerId=(int)$subject['user_id'];
}

$error=null;
$errorField=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'save';
    $postId=filter_var($_POST['id']??null,FILTER_VALIDATE_INT)?:0;
    if($postId)require_subject_management($postId);

    if($action==='delete'){
        if(!$postId){http_response_code(400);exit('Ongeldig ID.');}
        $pdo->beginTransaction();
        try{
            $x=$pdo->prepare("SELECT id FROM topics WHERE subject_id=?");
            $x->execute([$postId]);
            $topicIds=array_map('intval',$x->fetchAll(PDO::FETCH_COLUMN));

            if($topicIds){
                $ph=implode(',',array_fill(0,count($topicIds),'?'));
                $x=$pdo->prepare("DELETE FROM tests WHERE topic_id IN ($ph)");
                $x->execute($topicIds);
                $x=$pdo->prepare("DELETE FROM topics WHERE id IN ($ph)");
                $x->execute($topicIds);
            }

            $x=$pdo->prepare("DELETE FROM subjects WHERE id=?");
            $x->execute([$postId]);
            $pdo->commit();
            redirect('admin.php?deleted=subject');
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    $name=trim((string)($_POST['name']??''));
    $ownerId=(int)($_POST['user_id']??0);
    if($postId){
        $previousOwner=(int)$subject['user_id'];
        if($ownerId!==$previousOwner){
            $hasActivity=$pdo->prepare('SELECT 1 FROM topics tp LEFT JOIN tests t ON t.topic_id=tp.id LEFT JOIN attempts a ON a.test_id=t.id LEFT JOIN topic_grades g ON g.topic_id=tp.id WHERE tp.subject_id=? AND (a.id IS NOT NULL OR g.id IS NOT NULL) LIMIT 1');
            $hasActivity->execute([$postId]);
            if($hasActivity->fetchColumn()){http_response_code(409);exit('Dit vak bevat cijfers of toetsresultaten. Eigenaarschap wijzigen is geblokkeerd om bestaande leerlinggegevens te beschermen.');}
        }
    }
    if($postId && $postId!==$id){http_response_code(400);exit('Ongeldig vak.');}
    if(!in_array($ownerId,$ownerIds,true)){
        $error='Selecteer een geldige eigenaar.';
        $errorField='user_id';
    }elseif($name===''){
        $error='Vul een naam voor het vak in.';
        $errorField='name';
    }else{
        try{
            $createdNewSubject=false;
            if($postId){
                $x=$pdo->prepare("UPDATE subjects SET name=?,user_id=? WHERE id=?");
                $x->execute([$name,$ownerId,$postId]);
                $id=$postId;
            }else{
                $x=$pdo->prepare("INSERT INTO subjects(name,user_id) VALUES(?,?)");
                $x->execute([$name,$ownerId]);
                $id=(int)$pdo->lastInsertId();
                $createdNewSubject=true;
            }

            // Vak direct opslaan; AI-regels kunnen later via AI-instructies worden beheerd.
            $aiRulesCreated=false;

            if(isset($_FILES['image']) && $_FILES['image']['error']!==UPLOAD_ERR_NO_FILE){
                if($_FILES['image']['error']!==UPLOAD_ERR_OK){
                    $error='De afbeelding kon niet worden geüpload.';$errorField='image';
                }elseif((int)$_FILES['image']['size']>5*1024*1024){
                    $error='De afbeelding is groter dan 5 MB.';$errorField='image';
                }else{
                    $info=@getimagesize($_FILES['image']['tmp_name']);
                    $allowed=['image/jpeg','image/png','image/webp'];
                    $mime=$info['mime']??'';
                    if(!$info || !in_array($mime,$allowed,true)){
                        $error='Gebruik een JPG-, PNG- of WebP-afbeelding.';$errorField='image';
                    }else{
                        $data=file_get_contents($_FILES['image']['tmp_name']);
                        if($data===false){
                            $error='De afbeelding kon niet worden gelezen.';$errorField='image';
                        }else{
                            $x=$pdo->prepare("UPDATE subjects SET image_mime=?,image_data=? WHERE id=?");
                            $x->execute([$mime,$data,$id]);
                        }
                    }
                }
            }

            if(!$error){
                $query='';
                redirect('admin.php?saved=subject'.$query);
            }
            $x=$pdo->prepare("SELECT id,name,image_mime FROM subjects WHERE id=?");
            $x->execute([$id]);$subject=$x->fetch();
        }catch(PDOException $e){
            if((int)$e->errorInfo[1]===1062){$error='Er bestaat al een vak met deze naam.';$errorField='name';}
            else throw $e;
        }
    }
}
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $id?'Vak bewerken':'Nieuw vak' ?> - Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark">
<div class="container"><a class="navbar-brand" href="admin.php">Leren beheer</a></div>
</nav>
<main class="container py-4">
<a href="admin.php">&larr; Terug naar beheer</a>
<h1 class="mt-3"><?= $id?'Vak bewerken':'Nieuw vak' ?></h1>

<?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>

<div class="card shadow-sm">
<div class="card-body">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" value="<?=$id?>">

<div class="mb-3">
<label class="form-label">Naam</label>
<input class="form-control<?=$errorField==='name'?' is-invalid':''?>" name="name" value="<?=e($subject['name']??'')?>" required maxlength="150" placeholder="Bijvoorbeeld Engels">
</div>


<?php if(count($owners)>1):?>
<div class="mb-3"><label class="form-label" for="user_id">Leerling / eigenaar</label><select class="form-select<?=$errorField==='user_id'?' is-invalid':''?>" name="user_id" id="user_id" required><?php foreach($owners as $owner):?><option value="<?=(int)$owner['id']?>" <?=(int)($_POST['user_id']??$subject['user_id']??$ownerId)===(int)$owner['id']?'selected':''?>><?=e($owner['name'])?></option><?php endforeach;?></select></div>
<?php else:?>
<input type="hidden" name="user_id" value="<?=(int)($subject['user_id']??$ownerId)?>">
<?php endif;?>
<div class="mb-3">
<label class="form-label">Afbeelding</label>
<?php if(!empty($subject['image_mime'])):?>
<img src="subject_image.php?id=<?=$id?>" class="img-fluid rounded mb-2 d-block" style="max-height:220px;object-fit:cover" alt="">
<?php endif;?>
<input class="form-control<?=$errorField==='image'?' is-invalid':''?>" type="file" name="image" accept="image/jpeg,image/png,image/webp">
<div class="form-text">JPG, PNG of WebP. Maximaal 5 MB.</div>
</div>

<button class="btn btn-primary" type="submit">Opslaan</button>
<a class="btn btn-outline-secondary" href="admin.php">Annuleren</a>
</form>
</div>
</div>

<?php if($id):?>
<div class="card border-danger mt-4">
<div class="card-body">
<h2 class="h5 text-danger">Vak verwijderen</h2>
<p class="mb-3">Hiermee worden ook de overhoringen, testen, vragen en resultaten van dit vak verwijderd.</p>
<form method="post" onsubmit="return confirm('Weet u zeker dat u dit vak wilt verwijderen? Het vak verdwijnt uit de website. De gegevens blijven in de database bewaard.');">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="id" value="<?=$id?>">
<button class="btn btn-danger" type="submit">Vak verwijderen</button>
</form>
</div>
</div>
<?php endif;?>
</main>
</body>
</html>
