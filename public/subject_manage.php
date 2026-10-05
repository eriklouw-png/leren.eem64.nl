<?php
require __DIR__.'/../app/bootstrap.php';require_admin();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('admin.php');

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete_subject'){
    $postId=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
    if($postId!==$id){http_response_code(400);exit('Ongeldig ID.');}
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
        $x=$pdo->prepare("DELETE FROM subjects WHERE id=?");$x->execute([$id]);
        $pdo->commit();
        redirect('admin.php?deleted=subject');
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
$s=$pdo->prepare("SELECT id,name,description,image_mime FROM subjects WHERE id=?");$s->execute([$id]);$subject=$s->fetch();
if(!$subject){http_response_code(404);exit('Taal niet gevonden.');}
$x=$pdo->prepare("SELECT t.id,t.title,t.description,t.test_type,t.is_active,COUNT(q.id) question_count FROM tests t JOIN topics tp ON tp.id=t.topic_id LEFT JOIN questions q ON q.test_id=t.id WHERE tp.subject_id=? GROUP BY t.id ORDER BY t.created_at DESC");
$x->execute([$id]);$tests=$x->fetchAll();
$testIds=array_map('intval',array_column($tests,'id'));
$sessionGroups=[];
if($testIds){
    $ph=implode(',',array_fill(0,count($testIds),'?'));
    $sx=$pdo->prepare("SELECT ss.id,ss.test_id,ss.attempt_id,ss.started_at,ss.ended_at,ss.active_seconds,t.title,a.score FROM study_sessions ss LEFT JOIN attempts a ON a.id=ss.attempt_id LEFT JOIN tests t ON t.id=ss.test_id WHERE ss.test_id IN ($ph) ORDER BY ss.started_at DESC");
    $sx->execute($testIds);$sessions=$sx->fetchAll();
    foreach($sessions as $ss){
        $tid=(int)$ss['test_id'];
        if(!isset($sessionGroups[$tid]))$sessionGroups[$tid]=['title'=>$ss['title']??'Toets','total_seconds'=>0,'sessions'=>[]];
        $sessionGroups[$tid]['total_seconds']+=(int)$ss['active_seconds'];
        $sessionGroups[$tid]['sessions'][]=$ss;
    }
}
function format_duration_subject(int $seconds):string{$m=intdiv($seconds,60);$s=$seconds%60;return $m.' min '.str_pad((string)$s,2,'0',STR_PAD_LEFT).' sec';}
$labels=['vocabulary'=>'Woordjes oefenen','multiple_choice'=>'Multiple choice','mixed'=>'Combinatie'];
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($subject['name'])?> - Beheer</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4" style="max-width:1000px">
<a href="admin.php">&larr; Beheer</a>
<div class="card shadow-sm mt-3 overflow-hidden">
<?php if($subject['image_mime']):?><img src="subject_image.php?id=<?=$id?>" style="height:180px;object-fit:cover" alt="<?=e($subject['name'])?>"><?php endif;?>
<div class="card-body p-4"><div class="d-flex justify-content-between align-items-start gap-3"><div><h1 class="h3 mb-1"><?=e($subject['name'])?></h1><?php if($subject['description']):?><p class="text-secondary mb-0"><?=e($subject['description'])?></p><?php endif;?></div><div class="d-flex flex-column flex-sm-row gap-2">
<a class="btn btn-primary" href="test_new.php?subject_id=<?=$id?>">Nieuwe toets</a>
<a class="btn btn-outline-secondary" href="subject_edit.php?id=<?=$id?>">Bewerken</a>
</div></div></div>
</div>
<h2 class="h4 mt-4">Toetsen</h2>
<?php if(!$tests):?><div class="alert alert-info">Nog geen toetsen voor deze taal.</div><?php else:?><div class="list-group shadow-sm">
<?php foreach($tests as $t):?><div class="list-group-item"><div class="d-flex justify-content-between align-items-center gap-3"><div><strong><?=e($t['title'])?></strong><div class="small text-secondary"><?=e($labels[$t['test_type']??'mixed']??'Combinatie')?> · <?=$t['question_count']?> vragen · <?=((int)$t['is_active']?'Actief':'Inactief')?></div></div><div class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="test_edit.php?id=<?=$t['id']?>">Bewerken</a><a class="btn btn-sm btn-outline-secondary ms-1" href="questions.php?test_id=<?=$t['id']?>">Vragen</a></div></div></div><?php endforeach;?>
</div><?php endif;?>
<h2 class="h4 mt-5">Oefentijd</h2>
<p class="text-secondary">Actieve tijd wordt gemeten zolang de pagina zichtbaar is en er recent toetsenbord-, muis-, scroll- of touchactiviteit is geweest.</p>
<?php if(!$sessionGroups):?>
<div class="alert alert-secondary">Er zijn nog geen oefensessies voor deze taal.</div>
<?php else:?>
<?php foreach($sessionGroups as $testId=>$group):?>
<details class="card shadow-sm mb-3">
<summary class="p-3" style="cursor:pointer;list-style:none">
<div class="d-flex justify-content-between align-items-center gap-2">
<strong><?=e($group['title'])?></strong><span class="text-secondary"><?=e(format_duration_subject((int)$group['total_seconds']))?></span><span class="fs-5">⌄</span>
</div>
</summary>
<div class="table-responsive"><table class="table table-hover mb-0">
<thead><tr><th>Start</th><th>Einde</th><th>Actieve tijd</th><th>Score</th></tr></thead>
<tbody>
<?php foreach($group['sessions'] as $ss):?>
<tr><td><?=e($ss['started_at'])?></td><td><?=e($ss['ended_at']??'Actief')?></td><td><?=e(format_duration_subject((int)$ss['active_seconds']))?></td><td><?=isset($ss['score'])?e((string)$ss['score']).'%':'-'?></td></tr>
<?php endforeach;?>
</tbody></table></div>
</details>
<?php endforeach;?>
<?php endif;?>
<div class="card border-danger mt-4"><div class="card-body">
<h2 class="h5 text-danger">Taal verwijderen</h2>
<p class="text-secondary">Hiermee worden ook alle onderwerpen, toetsen, vragen, resultaten en oefentijd van deze taal verwijderd.</p>
<form method="post" onsubmit="return confirm('Weet je zeker dat je deze taal wilt verwijderen? Dit verwijdert ook alle toetsen, vragen, resultaten en oefentijd.');">
<input type="hidden" name="action" value="delete_subject"><input type="hidden" name="id" value="<?=$id?>">
<button class="btn btn-outline-danger" type="submit">Taal verwijderen</button>
</form>
</div></div>
</main></body></html>