<?php
require __DIR__.'/../app/bootstrap.php';

$subjects=$pdo->query("SELECT s.id,s.name,s.description,s.image_mime,COUNT(DISTINCT t.id) test_count FROM subjects s JOIN topics tp ON tp.subject_id=s.id JOIN tests t ON t.topic_id=tp.id AND t.is_active=1 GROUP BY s.id ORDER BY s.name");

function subject_visual(string $name):array{
    $n=mb_strtolower(trim($name),'UTF-8');
    if(str_contains($n,'biolog')) return ['bi-leaf-fill','green'];
    if(str_contains($n,'duits') || str_contains($n,'duitse')) return ['bi-book','orange'];
    if(str_contains($n,'geschiedenis')) return ['bi-bank','purple'];
    if(str_contains($n,'spaans') || str_contains($n,'spaanse')) return ['bi-globe-europe-africa','red'];
    if(str_contains($n,'test')) return ['bi-file-earmark-text','blue'];
    if(str_contains($n,'nederlands')) return ['bi-chat-square-text','blue'];
    if(str_contains($n,'engels')) return ['bi-translate','blue'];
    if(str_contains($n,'wiskund')) return ['bi-calculator','purple'];
    return ['bi-journal-text','blue'];
}

$subjects=$subjects->fetchAll();
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
<div class="container">
<a class="navbar-brand" href="index.php">Leren</a>
<a class="btn btn-outline-light btn-sm" href="admin.php">Beheer</a>
</div>
</nav>

<main class="container pb-5">
<h1>Kies een vak</h1>

<div class="row g-3 subjects-grid">
<?php foreach($subjects as $s):
    [$icon,$iconColor]=subject_visual((string)$s['name']);
?>
<div class="col-6 subject-col">
<a class="leren-tile subject-home-tile text-decoration-none" href="subject.php?id=<?=(int)$s['id']?>">
<div class="subject-card-image" style="background-image:<?=($s['image_mime']?'url(\'subject_image.php?id='.(int)$s['id'].'\')':'none')?>;">
<div class="subject-card-overlay"></div>
<div class="subject-home-content">
<div class="subject-home-icon subject-icon-<?=$iconColor?>" aria-hidden="true"><i class="bi <?=$icon?>"></i></div>
<div class="subject-home-text">
<h2 class="subject-card-title"><?=e($s['name'])?></h2>
<div class="subject-card-count"><?=$s['test_count']?> <?=((int)$s['test_count']===1?'sub-test':'sub-testen')?></div>
</div>
<div class="subject-home-chevron" aria-hidden="true"><i class="bi bi-chevron-right"></i></div>
</div>
</div>
</a>
</div>
<?php endforeach;?>
</div>

<?php if(!$subjects):?>
<div class="alert alert-info mt-3">Er zijn nog geen vakken met actieve sub-testen.</div>
<?php endif;?>
</main>
</body>
</html>
