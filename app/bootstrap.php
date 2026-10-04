<?php
declare(strict_types=1);

session_start();

$configPath=__DIR__.'/../config/config.php';
if(!is_file($configPath)){http_response_code(500);exit('config/config.php ontbreekt.');}
$config=require $configPath;
$db=$config['db'];
$dsn="mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";

try{$pdo=new PDO($dsn,$db['user'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
catch(PDOException $e){http_response_code(500);exit('Databaseverbinding mislukt.');}

function e(?string $v):string{return htmlspecialchars($v??'',ENT_QUOTES,'UTF-8');}
function redirect(string $url):never{header('Location: '.$url);exit;}
function is_admin():bool{return isset($_SESSION['user'])&&$_SESSION['user']['role']==='admin';}
function require_admin():void{if(!is_admin())redirect('login.php');}

function normalize_open_answer(string $value):string{
    $value=trim($value);
    if(function_exists('transliterator_transliterate')){
        $normalized=transliterator_transliterate('NFKD; [:Nonspacing Mark:] Remove; [:Punctuation:] Remove; Lower();',$value);
        if($normalized!==false)$value=$normalized;
    }else{
        $value=mb_strtolower($value,'UTF-8');
        $value=preg_replace('/[[:punct:]]/u',' ',$value);
    }
    $value=preg_replace('/\s+/u',' ',$value)??$value;
    return trim($value);
}

function open_answer_tolerance(string $normalizedAnswer):int{
    $length=mb_strlen($normalizedAnswer,'UTF-8');
    if($length<=4)return 0;
    if($length<=8)return 1;
    if($length<=14)return 2;
    return 3;
}

function open_answer_matches(string $answer,array $acceptedAnswers):bool{
    $answer=normalize_open_answer($answer);
    if($answer==='')return false;
    foreach($acceptedAnswers as $accepted){
        $accepted=normalize_open_answer((string)$accepted);
        if($accepted==='')continue;
        if($answer===$accepted)return true;
        $distance=levenshtein($answer,$accepted);
        $tolerance=min(open_answer_tolerance($answer),open_answer_tolerance($accepted));
        if($distance<=$tolerance)return true;
    }
    return false;
}
