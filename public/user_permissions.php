<?php
require __DIR__.'/../app/bootstrap.php';
require_admin();

if(!is_admin()){
    http_response_code(403);
    exit('Geen toegang.');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';

    if($action==='save_role'){
        $userId=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT)?:0;
        $role=(string)($_POST['role']??'');
        if(!$userId || !in_array($role,['admin','beheerder','student'],true)){
            http_response_code(400);exit('Ongeldige gebruiker of rol.');
        }
        if($userId===(int)($_SESSION['user']['id']??0) && $role!=='admin'){
            http_response_code(400);exit('Je kunt je eigen adminrol niet verwijderen.');
        }
        $x=$pdo->prepare("SELECT role FROM users WHERE id=?");
        $x->execute([$userId]);
        if(!$x->fetch()){http_response_code(404);exit('Gebruiker niet gevonden.');}

        $pdo->beginTransaction();
        try{
            $x=$pdo->prepare("UPDATE users SET role=? WHERE id=?");
            $x->execute([$role,$userId]);
            if($role!=='beheerder'){
                $x=$pdo->prepare("DELETE FROM manager_students WHERE manager_id=?");
                $x->execute([$userId]);
            }
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        redirect('user_permissions.php?saved=role');
    }

    if($action==='save_students'){
        $managerId=filter_var($_POST['manager_id']??null,FILTER_VALIDATE_INT)?:0;
        if(!$managerId){http_response_code(400);exit('Ongeldige beheerder.');}

        $x=$pdo->prepare("SELECT role FROM users WHERE id=?");
        $x->execute([$managerId]);
        if($x->fetchColumn()!=='beheerder'){http_response_code(400);exit('Deze gebruiker is geen beheerder.');}

        $studentIds=$_POST['student_ids']??[];
        if(!is_array($studentIds))$studentIds=[];
        $studentIds=array_values(array_unique(array_filter(array_map('intval',$studentIds),static fn($id)=>$id>0)));

        if($studentIds){
            $ph=implode(',',array_fill(0,count($studentIds),'?'));
            $x=$pdo->prepare("SELECT id FROM users WHERE role='student' AND id IN ($ph)");
            $x->execute($studentIds);
            $validIds=array_map('intval',$x->fetchAll(PDO::FETCH_COLUMN));
        }else{
            $validIds=[];
        }

        $pdo->beginTransaction();
        try{
            $x=$pdo->prepare("DELETE FROM manager_students WHERE manager_id=?");
            $x->execute([$managerId]);
            if($validIds){
                $x=$pdo->prepare("INSERT INTO manager_students(manager_id,student_id) VALUES(?,?)");
                foreach($validIds as $studentId)$x->execute([$managerId,$studentId]);
            }
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        redirect('user_permissions.php?saved=students');
    }

    http_response_code(400);
    exit('Ongeldige actie.');
}

$users=$pdo->query("
    SELECT u.id,u.name,u.email,u.role,u.created_at,
           COUNT(ms.student_id) AS student_count
    FROM users u
    LEFT JOIN manager_students ms ON ms.manager_id=u.id
    GROUP BY u.id,u.name,u.email,u.role,u.created_at
    ORDER BY FIELD(u.role,'admin','beheerder','student'),u.name
")->fetchAll();

$managers=array_values(array_filter($users,static fn($u)=>$u['role']==='beheerder'));
$students=array_values(array_filter($users,static fn($u)=>$u['role']==='student'));

$assignments=[];
if($managers){
    $managerIds=array_map('intval',array_column($managers,'id'));
    $ph=implode(',',array_fill(0,count($managerIds),'?'));
    $x=$pdo->prepare("SELECT manager_id,student_id FROM manager_students WHERE manager_id IN ($ph)");
    $x->execute($managerIds);
    foreach($x->fetchAll() as $row){
        $assignments[(int)$row['manager_id']][]=(int)$row['student_id'];
    }
}
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gebruikers &amp; rechten - Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-4">
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="mb-1">Gebruikers &amp; rechten</h1>
        <p class="text-secondary mb-0">Beheer rollen en bepaal welke studenten onder een beheerder vallen.</p>
    </div>
    <a class="btn btn-outline-light" href="admin.php">Terug naar beheer</a>
</div>

<?php if(isset($_GET['saved'])):?>
<div class="alert alert-success">
    <?=($_GET['saved']==='role'?'De rol is opgeslagen.':'De studenten onder de beheerder zijn opgeslagen.')?>
</div>
<?php endif;?>

<section class="leren-section">
<h2 class="leren-section-title">Gebruikers</h2>
<div class="leren-list">
<?php foreach($users as $user):?>
<div class="leren-list-item">
    <div class="leren-list-item-main">
        <div class="leren-list-item-content">
            <div class="leren-list-item-heading">
                <div class="leren-list-item-title"><?=e($user['name'])?></div>
            </div>
            <div class="leren-list-item-subtitle"><?=e($user['email'])?></div>
            <?php if($user['role']==='beheerder'):?>
                <div class="leren-list-item-description"><?=e((string)$user['student_count'])?> student<?=((int)$user['student_count']===1?'':'en')?> onder beheer</div>
            <?php endif;?>
        </div>
    </div>
    <div class="d-flex align-items-center px-3 gap-2">
        <form method="post" class="d-flex align-items-center gap-2">
            <input type="hidden" name="action" value="save_role">
            <input type="hidden" name="user_id" value="<?=e((string)$user['id'])?>">
            <select name="role" class="form-select form-select-sm" <?=((int)$user['id']===(int)($_SESSION['user']['id']??0)?'disabled':'')?>>
                <option value="admin" <?=$user['role']==='admin'?'selected':''?>>Admin</option>
                <option value="beheerder" <?=$user['role']==='beheerder'?'selected':''?>>Beheerder</option>
                <option value="student" <?=$user['role']==='student'?'selected':''?>>Student</option>
            </select>
            <?php if((int)$user['id']!==(int)($_SESSION['user']['id']??0)):?>
            <button class="btn btn-outline-primary btn-sm" type="submit">Opslaan</button>
            <?php else:?>
            <span class="badge text-bg-secondary">Jij</span>
            <?php endif;?>
        </form>
    </div>
</div>
<?php endforeach;?>
</div>
</section>

<section class="leren-section">
<h2 class="leren-section-title">Studenten onder beheer</h2>
<?php if(!$managers):?>
<div class="leren-empty">Er zijn nog geen beheerders.</div>
<?php else:?>
<div class="d-grid gap-3">
<?php foreach($managers as $manager):?>
<div class="card">
<div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h3 class="mb-1"><?=e($manager['name'])?></h3>
            <div class="leren-meta"><?=e($manager['email'])?></div>
        </div>
        <span class="badge text-bg-success"><?=e((string)$manager['student_count'])?> studenten</span>
    </div>

    <?php if(!$students):?>
        <p class="text-secondary mb-0">Er zijn nog geen studenten.</p>
    <?php else:?>
    <form method="post">
        <input type="hidden" name="action" value="save_students">
        <input type="hidden" name="manager_id" value="<?=e((string)$manager['id'])?>">
        <div class="row g-2">
        <?php $assigned=array_flip($assignments[(int)$manager['id']]??[]); ?>
        <?php foreach($students as $student):?>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="d-flex align-items-center gap-2 p-2 rounded border">
                    <input class="form-check-input m-0" type="checkbox" name="student_ids[]" value="<?=e((string)$student['id'])?>" <?=isset($assigned[(int)$student['id']])?'checked':''?>>
                    <span><?=e($student['name'])?></span>
                </label>
            </div>
        <?php endforeach;?>
        </div>
        <div class="mt-3">
            <button class="btn btn-primary" type="submit">Studenten opslaan</button>
        </div>
    </form>
    <?php endif;?>
</div>
</div>
<?php endforeach;?>
</div>
<?php endif;?>
</section>
</main>
</body>
</html>