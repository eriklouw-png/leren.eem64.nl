<?php
require __DIR__.'/../app/bootstrap.php';require_admin();
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
    if(!$id){http_response_code(400);exit('Ongeldig ID.');}
    if($action==='upload_subject_image'){
        if(!isset($_FILES['image']) || $_FILES['image']['error']!==UPLOAD_ERR_OK){
            redirect('admin.php?image_error=upload');
        }
        if((int)$_FILES['image']['size']>5*1024*1024){
            redirect('admin.php?image_error=size');
        }
        $tmp=$_FILES['image']['tmp_name'];
        $info=@getimagesize($tmp);
        $allowed=['image/jpeg','image/png','image/webp'];
        $mime=$info['mime']??'';
        if(!$info || !in_array($mime,$allowed,true)){
            redirect('admin.php?image_error=type');
        }
        $data=file_get_contents($tmp);
        if($data===false){
            redirect('admin.php?image_error=read');
        }
        $x=$pdo->prepare("UPDATE subjects SET image_mime=?,image_data=? WHERE id=?");
        $x->execute([$mime,$data,$id]);
        redirect('admin.php?image_saved=1');
    }
    if($action==='delete_subject_image'){
        $x=$pdo->prepare("UPDATE subjects SET image_mime=NULL,image_data=NULL WHERE id=?");
        $x->execute([$id]);
        redirect('admin.php?image_deleted=1');
    }
    if($action==='delete_subject'){
        $x=$pdo->prepare("SELECT id FROM subjects WHERE id=?");
        $x->execute([$id]);
        if(!$x->fetch()){http_response_code(404);exit('Vak niet gevonden.');}
        $pdo->beginTransaction();
        try{
            $x=$pdo->prepare("SELECT id FROM topics WHERE subject_id=?");
            $x->execute([$id]);
            $topicIds=array_map('intval',$x->fetchAll(PDO::FETCH_COLUMN));
            if($topicIds){
                $ph=implode(',',array_fill(0,count($topicIds),'?'));
                $x=$pdo->prepare("DELETE FROM tests WHERE topic_id IN ($ph)");
                $x->execute($topicIds);
                $x=$pdo->prepare("DELETE FROM topics WHERE id IN ($ph)");
                $x->execute($topicIds);
            }
            $x=$pdo->prepare("DELETE FROM subjects WHERE id=?");
            $x->execute([$id]);
            $pdo->commit();
            redirect('admin.php?deleted=subject');
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }
    if($action==='delete_test'){
        $x=$pdo->prepare("SELECT title FROM tests WHERE id=?");
        $x->execute([$id]);$item=$x->fetch();
        if(!$item){http_response_code(404);exit('Toets niet gevonden.');}
        $x=$pdo->prepare("DELETE FROM tests WHERE id=?");$x->execute([$id]);
        redirect('admin.php?deleted=test');
    }
    if($action==='delete_session'){
        $x=$pdo->prepare("SELECT id FROM study_sessions WHERE id=?");
        $x->execute([$id]);if(!$x->fetch()){http_response_code(404);exit('Oefensessie niet gevonden.');}
        $x=$pdo->prepare("DELETE FROM study_sessions WHERE id=?");$x->execute([$id]);
        redirect('admin.php?deleted=session');
    }
    if($action==='delete_attempt'){
        $x=$pdo->prepare("SELECT id FROM attempts WHERE id=? AND finished_at IS NOT NULL");
        $x->execute([$id]);if(!$x->fetch()){http_response_code(404);exit('Resultaat niet gevonden.');}
        $x=$pdo->prepare("DELETE FROM attempts WHERE id=?");$x->execute([$id]);
        redirect('admin.php?deleted=attempt');
    }
    http_response_code(400);exit('Ongeldige actie.');
}
$subjects=$pdo->query("SELECT id,name,description,image_mime FROM subjects ORDER BY name")->fetchAll();
$attempts=$pdo->query("SELECT a.id,a.score,a.finished_at,t.title FROM attempts a JOIN tests t ON t.id=a.test_id WHERE a.finished_at IS NOT NULL ORDER BY a.finished_at DESC LIMIT 10")->fetchAll();
$sessions=$pdo->query("SELECT ss.id,ss.test_id,ss.attempt_id,ss.started_at,ss.ended_at,ss.active_seconds,t.title,a.score FROM study_sessions ss LEFT JOIN attempts a ON a.id=ss.attempt_id LEFT JOIN tests t ON t.id=ss.test_id ORDER BY ss.started_at DESC")->fetchAll();
$answeredRows=$pdo->query("SELECT ss.test_id,aa.question_id FROM study_sessions ss JOIN attempt_answers aa ON aa.attempt_id=ss.attempt_id GROUP BY ss.test_id,aa.question_id")->fetchAll();
$questionCounts=$pdo->query("SELECT test_id,COUNT(*) question_count FROM questions GROUP BY test_id")->fetchAll();
$answeredByTest=[];
foreach($answeredRows as $row){$answeredByTest[(int)$row['test_id']]=($answeredByTest[(int)$row['test_id']]??0)+1;}
$totalQuestionsByTest=[];
foreach($questionCounts as $row){$totalQuestionsByTest[(int)$row['test_id']]=(int)$row['question_count'];}
$sessionGroups=[];
foreach($sessions as $s){
    $testId=(int)$s['test_id'];
    if(!isset($sessionGroups[$testId])){
        $sessionGroups[$testId]=['title'=>$s['title']??'Vrij oefenen','total_seconds'=>0,'sessions'=>[]];
    }
    $sessionGroups[$testId]['total_seconds']+=(int)$s['active_seconds'];
    $sessionGroups[$testId]['sessions'][]=$s;
}
ksort($sessionGroups);
function format_duration(int $seconds):string{$m=intdiv($seconds,60);$s=$seconds%60;return $m.' min '.str_pad((string)$s,2,'0',STR_PAD_LEFT).' sec';}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Beheer - Leren</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>details .chevron{display:inline-block;transition:transform .15s ease;font-size:1.5rem;line-height:1}details[open] .chevron{transform:rotate(180deg)}</style></head><body class="bg-light"><nav class="navbar navbar-dark bg-dark"><div class="container"><a class="navbar-brand" href="admin.php">Leren beheer</a><div><span class="text-white me-3"><?=e($_SESSION['user']['name'])?></span><a class="btn btn-outline-light btn-sm" href="index.php">Website</a> <a class="btn btn-outline-light btn-sm" href="logout.php">Uitloggen</a></div></div></nav><main class="container py-4">
<div class="d-flex justify-content-between align-items-center mb-3">
<h1 class="mb-0">Talen en vakken</h1>
<div><a class="btn btn-outline-primary" href="system_update.php">Website bijwerken</a> <a class="btn btn-outline-primary" href="subject_edit.php">Nieuw vak</a></div>
</div>

<div class="row g-3 mb-5">
<?php foreach($subjects as $subject):?>
<div class="col-12 col-md-6 col-lg-4">
<a href="subject_manage.php?id=<?=(int)$subject['id']?>" class="text-decoration-none text-dark">
<div class="card shadow-sm h-100">
<div class="card-body p-3">
<div class="d-flex align-items-center gap-3">
<?php if($subject['image_mime']):?>
<img src="subject_image.php?id=<?=(int)$subject['id']?>" class="rounded flex-shrink-0" style="width:86px;height:86px;object-fit:cover" alt="<?=e($subject['name'])?>">
<?php else:?>
<div class="rounded bg-secondary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:86px;height:86px;font-size:2rem">📚</div>
<?php endif;?>
<div class="min-w-0">
<h2 class="h4 mb-1"><?=e($subject['name'])?></h2>
<?php if($subject['description']):?><p class="text-secondary mb-0 small"><?=e($subject['description'])?></p><?php endif;?>
</div>
</div>
<div class="btn btn-primary w-100 mt-3">Naar <?=e($subject['name'])?> →</div>
</div>
</div>
</a>
</div>
<?php endforeach;?>
</div>

<h2 class="h4 mt-5">Recente resultaten</h2><div class="card shadow-sm"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Toets</th><th>Score</th><th>Datum</th><th></th></tr></thead><tbody><?php foreach($attempts as $a):?><tr><td><?=e($a['title'])?></td><td><?=e((string)$a['score'])?>%</td><td><?=e($a['finished_at'])?></td><td><form method="post" class="d-inline" onsubmit="return confirm('U gaat het resultaat van toets &quot;<?=e($a['title'])?>&quot; van <?=e((string)$a['score'])?>% verwijderen. Weet u het zeker?');"><input type="hidden" name="action" value="delete_attempt"><input type="hidden" name="id" value="<?=$a['id']?>"><button class="btn btn-sm btn-outline-danger" type="submit">Verwijderen</button></form></td></tr><?php endforeach;?></tbody></table></div></div></main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>
