<?php
require __DIR__.'/../app/bootstrap.php';

$subjects=$pdo->query("SELECT s.id,s.name,s.description,s.image_mime,COUNT(DISTINCT t.id) test_count FROM subjects s JOIN topics tp ON tp.subject_id=s.id JOIN tests t ON t.topic_id=tp.id AND t.is_active=1 GROUP BY s.id ORDER BY s.name");

function subject_visual(string $name):array{
    $n=mb_strtolower(trim($name),'UTF-8');
    if(str_contains($n,'biolog')) return ['leaf','green'];
    if(str_contains($n,'duits') || str_contains($n,'duitse')) return ['beer','orange'];
    if(str_contains($n,'geschiedenis')) return ['bi-bank','purple'];
    if(str_contains($n,'spaans') || str_contains($n,'spaanse')) return ['bull','red'];
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
<div class="row g-3 subjects-grid">
<?php foreach($subjects as $s):
    [$icon,$iconColor]=subject_visual((string)$s['name']);
?>
<div class="col-6 col-lg-3 subject-col">
<a class="leren-tile subject-home-tile text-decoration-none" href="subject.php?id=<?=(int)$s['id']?>">
<div class="subject-card-image" style="background-image:<?=($s['image_mime']?'url(\'subject_image.php?id='.(int)$s['id'].'\')':'none')?>;">
<div class="subject-card-overlay"></div>
<div class="subject-home-content">
<div class="subject-home-icon subject-icon-<?=$iconColor?>" aria-hidden="true">
<?php if($icon==='leaf'): ?>
<svg class="subject-custom-icon" viewBox="0 0 64 64" aria-hidden="true"><path d="M56 8C35 10 18 18 11 31c-5 10-1 20 8 24 9 4 19 0 25-8 8-10 11-24 12-39Z" fill="none" stroke="currentColor" stroke-width="5" stroke-linejoin="round"/><path d="M10 56c11-16 23-25 38-34" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/></svg>
<?php elseif($icon==='bull'): ?>
<svg class="subject-custom-icon" viewBox="0 0 64 64" aria-hidden="true"><path d="M17 24C9 22 5 17 5 10c7 1 13 4 17 9 3-3 6-4 10-4s7 1 10 4c4-5 10-8 17-9 0 7-4 12-12 14 2 5 1 11-2 16-3 5-8 9-13 12-5-3-10-7-13-12-3-5-4-11-2-16Z" fill="none" stroke="currentColor" stroke-width="4" stroke-linejoin="round" stroke-linecap="round"/><path d="M24 34h4m8 0h4M28 44c3 2 5 2 8 0" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>
<?php elseif($icon==='beer'): ?>
<svg class="subject-custom-icon" viewBox="0 0 64 64" aria-hidden="true"><path d="M18 18h28v31c0 4-3 7-7 7H25c-4 0-7-3-7-7V18Z" fill="none" stroke="currentColor" stroke-width="4" stroke-linejoin="round"/><path d="M46 25h7c4 0 6 3 6 7v7c0 5-3 8-8 8h-5M20 12c0 4 4 4 4 8m7-8c0 4 4 4 4 8m7-8c0 4 4 4 4 8" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"/><path d="M23 29c4 3 12 3 18 0" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
<?php else: ?>
<i class="bi <?=$icon?>"></i>
<?php endif; ?>
</div>
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
