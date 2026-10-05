<?php
require __DIR__.'/../app/bootstrap.php';require_admin();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('admin.php');
$s=$pdo->prepare("SELECT id,name,description,image_mime FROM subjects WHERE id=?");$s->execute([$id]);$subject=$s->fetch();
if(!$subject){http_response_code(404);exit('Taal niet gevonden.');}
$x=$pdo->prepare("SELECT t.id,t.title,t.description,t.test_type,t.is_active,COUNT(q.id) question_count FROM tests t JOIN topics tp ON tp.id=t.topic_id LEFT JOIN questions q ON q.test_id=t.id WHERE tp.subject_id=? GROUP BY t.id ORDER BY t.created_at DESC");
$x->execute([$id]);$tests=$x->fetchAll();
$labels=['vocabulary'=>'Woordjes oefenen','multiple_choice'=>'Multiple choice','mixed'=>'Combinatie'];
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($subject['name'])?> - Beheer</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4" style="max-width:1000px">
<a href="admin.php">&larr; Beheer</a>
<div class="card shadow-sm mt-3 overflow-hidden">
<?php if($subject['image_mime']):?><img src="subject_image.php?id=<?=$id?>" style="height:180px;object-fit:cover" alt="<?=e($subject['name'])?>"><?php endif;?>
<div class="card-body p-4"><div class="d-flex justify-content-between align-items-start gap-3"><div><h1 class="h3 mb-1"><?=e($subject['name'])?></h1><?php if($subject['description']):?><p class="text-secondary mb-0"><?=e($subject['description'])?></p><?php endif;?></div><div class="text-nowrap"><a class="btn btn-outline-secondary" href="subject_edit.php?id=<?=$id?>">Bewerken</a><a class="btn btn-primary ms-2" href="test_new.php?subject_id=<?=$id?>">Nieuwe toets</a></div></div></div>
</div>
<h2 class="h4 mt-4">Toetsen</h2>
<?php if(!$tests):?><div class="alert alert-info">Nog geen toetsen voor deze taal.</div><?php else:?><div class="list-group shadow-sm">
<?php foreach($tests as $t):?><div class="list-group-item"><div class="d-flex justify-content-between align-items-center gap-3"><div><strong><?=e($t['title'])?></strong><div class="small text-secondary"><?=e($labels[$t['test_type']??'mixed']??'Combinatie')?> · <?=$t['question_count']?> vragen · <?=((int)$t['is_active']?'Actief':'Inactief')?></div></div><div class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="test_edit.php?id=<?=$t['id']?>">Bewerken</a><a class="btn btn-sm btn-outline-secondary ms-1" href="questions.php?test_id=<?=$t['id']?>">Vragen</a></div></div></div><?php endforeach;?>
</div><?php endif;?>
</main></body></html>