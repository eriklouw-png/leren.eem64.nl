<?php
declare(strict_types=1);

function current_user(): ?array{
    return isset($_SESSION['user']) && is_array($_SESSION['user'])
        ? $_SESSION['user']
        : null;
}

function is_logged_in(): bool{
    return current_user() !== null;
}

function require_login(): void{
    if(!is_logged_in()){
        $next=$_SERVER['REQUEST_URI']??'index.php';
        redirect('login.php?next='.rawurlencode($next));
    }
}

function auth_login(int $userId): bool{
    global $pdo;
    $stmt=$pdo->prepare("SELECT id,name,email,role FROM users WHERE id=? AND role IN ('admin','student')");
    $stmt->execute([$userId]);
    $user=$stmt->fetch();
    if(!$user)return false;
    session_regenerate_id(true);
    $_SESSION['user']=$user;
    return true;
}

function auth_login_by_email(string $email,string $password): bool{
    global $pdo;
    $stmt=$pdo->prepare("SELECT id,name,email,role,password_hash FROM users WHERE email=? LIMIT 1");
    $stmt->execute([mb_strtolower(trim($email),'UTF-8')]);
    $user=$stmt->fetch();
    if(!$user || !password_verify($password,(string)$user['password_hash']))return false;
    session_regenerate_id(true);
    $_SESSION['user']=[
        'id'=>(int)$user['id'],
        'name'=>$user['name'],
        'email'=>$user['email'],
        'role'=>$user['role']
    ];
    return true;
}

function auth_logout(): void{
    $_SESSION=[];
    if(ini_get('session.use_cookies')){
        $params=session_get_cookie_params();
        setcookie(session_name(),' ',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);
    }
    session_destroy();
}
