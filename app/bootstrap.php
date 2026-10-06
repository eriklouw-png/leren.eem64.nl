<?php
declare(strict_types=1);

session_start();

// Inject the global website theme into all HTML pages. JSON/API responses are left untouched.
function leren_navbar_html(string $area): string{
    if(!isset($_SESSION['user']) || !is_array($_SESSION['user']))return '';

    $name=e((string)($_SESSION['user']['name']??''));
    $area=$area==='admin'?'admin':'website';
    $right=$area==='admin'
        ? '<a class="btn btn-outline-light btn-sm" href="index.php">Website</a>'
        : '<a class="btn btn-outline-light btn-sm" href="admin.php">Beheer</a>';

    return '<nav class="navbar navbar-dark bg-dark mb-4"><div class="container">'
        .'<a class="navbar-brand" href="index.php">Leren</a>'
        .'<div><span class="text-white me-3">'.$name.'</span>'
        .'<a class="btn btn-outline-light btn-sm me-2" href="logout.php">Uitloggen</a>'.$right.'</div>'
        .'</div></nav>';
}

ob_start(static function(string $html): string{
    if(stripos($html,'</head>')!==false){
        $theme='<link rel="stylesheet" href="/theme.css">';
        $html=preg_replace('~</head>~i',$theme.'</head>',$html,1)??$html;
    }

    if(isset($_SESSION['user']) && is_array($_SESSION['user']) && stripos($html,'<body')!==false){
        $script=basename((string)($_SERVER['SCRIPT_NAME']??''));
        $adminPages=[
            'admin.php','subject_manage.php','subject_edit.php','topic_new.php','topic_edit.php',
            'test_new.php','ai_test_generator.php','test_edit.php','import.php','vocabulary_import.php',
            'questions.php','question_edit.php','summary_edit.php','user_edit.php',
            'system_update.php','debug_question.php'
        ];
        $area=in_array($script,$adminPages,true)?'admin':'website';
        $navbar=leren_navbar_html($area);

        if($navbar!==''){
            if($area==='admin'){
                // Add the admin class to the existing <body> tag while preserving any
                // existing classes/attributes. This is deliberately done with a callback
                // so pages such as <body class="bg-light"> remain valid.
                $html=preg_replace_callback(
                    '~<body\b([^>]*)>~i',
                    static function(array $m):string{
                        $attrs=$m[1];
                        if(preg_match('~\bclass="([^"]*)"~i',$attrs,$classMatch)){
                            $classes=trim($classMatch[1].' leren-admin');
                            $attrs=preg_replace('~\bclass="[^"]*"~i','class="'.htmlspecialchars($classes,ENT_QUOTES,'UTF-8').'"',$attrs,1)??$attrs;
                        }else{
                            $attrs=' class="leren-admin"'.$attrs;
                        }
                        return '<body'.$attrs.'>';
                    },
                    $html,
                    1
                )??$html;
            }
            if(preg_match('~<nav\b[^>]*>.*?</nav>~is',$html)){
                $html=preg_replace('~<nav\b[^>]*>.*?</nav>~is',$navbar,$html,1)??$html;
            }else{
                $html=preg_replace('~(<body\b[^>]*>)~i','$1'.$navbar,$html,1)??$html;
            }
        }
    }

    return $html;
});

$configPath=__DIR__.'/../config/config.php';
if(!is_file($configPath)){http_response_code(500);exit('config/config.php ontbreekt.');}
$config=require $configPath;
$db=$config['db'];
$dsn="mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";

try{$pdo=new PDO($dsn,$db['user'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
catch(PDOException $e){http_response_code(500);exit('Databaseverbinding mislukt.');}

/*
 * User profile images are stored directly in the users table.
 * Keep older installations compatible by adding the columns once when needed.
 */
try{
    $userImageColumn=$pdo->query("SHOW COLUMNS FROM users LIKE 'image_mime'")->fetch();
    if(!$userImageColumn){
        $pdo->exec("ALTER TABLE users ADD COLUMN image_mime VARCHAR(50) NULL AFTER email, ADD COLUMN image_data MEDIUMBLOB NULL AFTER image_mime");
    }
}catch(Throwable $e){
    // Do not make the complete website unavailable if an older database cannot be migrated here.
}

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

    foreach($acceptedAnswers as $acceptedRaw){
        $alternatives=preg_split('/\s*(?:\||\/)\s*/u',(string)$acceptedRaw,-1,PREG_SPLIT_NO_EMPTY);
        if(!$alternatives)$alternatives=[(string)$acceptedRaw];

        foreach($alternatives as $acceptedRawAlternative){
            $accepted=normalize_open_answer($acceptedRawAlternative);
            if($accepted==='')continue;
            if($answer===$accepted)return true;

            $answerBc=(bool)preg_match('/(?:voor christus|v ?ch?r|vc)\b/u',$answer);
            $acceptedBc=(bool)preg_match('/(?:voor christus|v ?ch?r|vc)\b/u',$accepted);
            $answerAd=(bool)preg_match('/(?:na christus|n ?ch?r|nc)\b/u',$answer);
            $acceptedAd=(bool)preg_match('/(?:na christus|n ?ch?r|nc)\b/u',$accepted);
            if(($answerBc&&$acceptedBc)||($answerAd&&$acceptedAd)){
                preg_match('/\b(\d{1,4})\b/u',$answer,$am);
                preg_match('/\b(\d{1,4})\b/u',$accepted,$cm);
                if(isset($am[1],$cm[1])&&$am[1]===$cm[1])return true;
            }

            if(preg_match('/^[-+]?\d+(?:[.,]\d+)?$/u',$accepted)){
                $numberPattern=preg_quote($accepted,'/');
                if(preg_match('/(?<![\d.,])'.$numberPattern.'(?![\d.,])/u',$answer)){
                    return true;
                }
            }

            $distance=levenshtein($answer,$accepted);
            $tolerance=min(open_answer_tolerance($answer),open_answer_tolerance($accepted));
            if($distance<=$tolerance)return true;
        }
    }
    return false;
}
