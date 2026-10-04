<?php
require __DIR__.'/../app/bootstrap.php';require_admin();
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
    if(!$id){http_response_code(400);exit('Ongeldig ID.');}
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
$tests=$pdo->query("SELECT t.id,t.title,t.is_active,COUNT(q.id) question_count,s.name subject_name FROM tests t LEFT JOIN topics tp ON tp.id=t.topic_id LEFT JOIN subjects s ON s.id=tp.subject_id LEFT JOIN questions q ON q.test_id=t.id GROUP BY t.id ORDER BY t.created_at DESC")->fetchAll();
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
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Beheer - Leren</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>details[open] .details-arrow{transform:rotate(90deg);display:inline-block} .details-arrow{display:inline-block;transition:transform .15s ease}</style></head><body class="bg-light"><nav class="navbar navbar-dark bg-dark"><div class="container"><a class="navbar-brand" href="admin.php">Leren beheer</a><div><span class="text-white me-3"><?=e($_SESSION['user']['name'])?></span><a class="btn btn-outline-light btn-sm" href="index.php">Website</a> <a class="btn btn-outline-light btn-sm" href="import.php">Importeren</a> <a class="btn btn-outline-light btn-sm" href="logout.php">Uitloggen</a></div></div></nav><main class="container py-4"><div class="d-flex justify-content-between align-items-center mb-3"><h1>Toetsen</h1><a class="btn btn-primary" href="test_edit.php">Nieuwe toets</a></div><div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Toets</th><th>Vak</th><th>Vragen</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($tests as $t):?><tr><td><?=e($t['title'])?></td><td><?=e($t['subject_name'])?></td><td><?=$t['question_count']?></td><td><?=((int)$t['is_active']?'Actief':'Inactief')?></td><td><a class="btn btn-sm btn-outline-primary" href="test_edit.php?id=<?=$t['id']?>">Bewerken</a> <a class="btn btn-sm btn-outline-secondary" href="questions.php?test_id=<?=$t['id']?>">Vragen</a> <form method="post" class="d-inline" onsubmit="return confirm('U gaat toets &quot;<?=e($t['title'])?>&quot; verwijderen. Weet u het zeker? Dit verwijdert ook de vragen, resultaten en oefentijd die bij deze toets horen.');"><input type="hidden" name="action" value="delete_test"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="btn btn-sm btn-outline-danger" type="submit">Verwijderen</button></form></td></tr><?php endforeach;?></tbody></table></div></div><h2 class="h4 mt-5">Oefentijd</h2><p class="text-secondary">Actieve tijd wordt gemeten zolang de pagina zichtbaar is en er recent toetsenbord-, muis-, scroll- of touchactiviteit is geweest. Er worden geen toetsaanslagen of muisbewegingen opgeslagen.</p><?php if(!$sessionGroups): ?><div class="alert alert-secondary">Er zijn nog geen oefensessies.</div><?php else: ?><?php foreach($sessionGroups as $testId=>$group): $answered=(int)($answeredByTest[$testId]??0);$totalQuestions=(int)($totalQuestionsByTest[$testId]??0);$percentage=$totalQuestions>0?min(100,round($answered/$totalQuestions*100)):0; ?><details class="card shadow-sm mb-3"><summary class="list-group-item list-group-item-action p-3" style="cursor:pointer;list-style:none"><div class="row align-items-center g-2"><div class="col-md-8"><strong><?=e($group['title'])?></strong><div><span class="text-secondary">Totale oefentijd:<br></span><strong><?=e(format_duration((int)$group['total_seconds']))?></strong></div><div><span class="text-secondary">Vragen beantwoord:<br></span><strong><?=$answered?> / <?=$totalQuestions?> (<?=$percentage?>%)</strong></div></div><div class="col-md-4 text-end fs-4"><span class="details-arrow" aria-hidden="true">▶</span></div></div></summary><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Start</th><th>Einde</th><th>Actieve tijd</th><th>Score</th><th></th></tr></thead><tbody><?php foreach($group['sessions'] as $s): ?><tr><td><?=e($s['started_at'])?></td><td><?=e($s['ended_at']??'Actief')?></td><td><?=e(format_duration((int)$s['active_seconds']))?></td><td><?=isset($s['score'])?e((string)$s['score']).'%':'-'?></td><td><form method="post" class="d-inline" onsubmit="return confirm('U gaat deze oefentijd verwijderen. Weet u het zeker?');"><input type="hidden" name="action" value="delete_session"><input type="hidden" name="id" value="<?=$s['id']?>"><button class="btn btn-sm btn-outline-danger" type="submit">Verwijderen</button></form></td></tr><?php endforeach; ?></tbody></table></div></details><?php endforeach; ?><?php endif; ?><h2 class="h4 mt-5">Recente resultaten</h2><div class="card shadow-sm"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Toets</th><th>Score</th><th>Datum</th><th></th></tr></thead><tbody><?php foreach($attempts as $a):?><tr><td><?=e($a['title'])?></td><td><?=e((string)$a['score'])?>%</td><td><?=e($a['finished_at'])?></td><td><form method="post" class="d-inline" onsubmit="return confirm('U gaat het resultaat van toets &quot;<?=e($a['title'])?>&quot; van <?=e((string)$a['score'])?>% verwijderen. Weet u het zeker?');"><input type="hidden" name="action" value="delete_attempt"><input type="hidden" name="id" value="<?=$a['id']?>"><button class="btn btn-sm btn-outline-danger" type="submit">Verwijderen</button></form></td></tr><?php endforeach;?></tbody></table></div></div></main></body></html>
