<?php
require __DIR__.'/../app/bootstrap.php';
$subjects=$pdo->query("SELECT s.id,s.name,s.description,s.image_mime,COUNT(DISTINCT t.id) test_count FROM subjects s JOIN topics tp ON tp.subject_id=s.id JOIN tests t ON t.topic_id=tp.id AND t.is_active=1 GROUP BY s.id ORDER BY s.name")->fetchAll();
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Leren</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>@media(max-width:767.98px){.subjects-grid{--bs-gutter-x:.6rem;--bs-gutter-y:.6rem}.subject-col{width:50%;padding-left:calc(var(--bs-gutter-x)*.5);padding-right:calc(var(--bs-gutter-x)*.5)}.subject-card-image{aspect-ratio:1/1;min-height:0}.subject-card-image .card-body{min-height:0!important;padding:.8rem}.subject-card-title{font-size:1.15rem}.subject-card-description{font-size:.82rem;line-height:1.2;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.subject-card-image .badge{font-size:.7rem}}</style><style>.subject-card-image{position:relative;background-size:cover;background-position:center;min-height:180px;background-color:#6c757d}.subject-card-overlay{position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.18),rgba(0,0,0,.76))}.subject-card-title{font-weight:700;font-size:1.6rem;text-shadow:0 2px 5px rgba(0,0,0,.75)}.subject-card-description{font-weight:600;color:#fff!important;text-shadow:0 1px 4px rgba(0,0,0,.9)}</style></head><body class="bg-light"><nav class="navbar navbar-dark bg-dark mb-4"><div class="container"><a class="navbar-brand" href="index.php">Leren</a><a class="btn btn-outline-light btn-sm" href="admin.php">Beheer</a></div></nav><main class="container pb-5"><h1>Kies een vak</h1><p class="text-secondary">Kies eerst een vak om de beschikbare sub-testen te bekijken.</p><div class="row g-3 subjects-grid">
<?php foreach($subjects as $s):?>
<div class="col-md-6 col-lg-4 subject-col">
<a class="text-decoration-none text-dark" href="subject.php?id=<?=$s['id']?>">
<div class="card h-100 shadow-sm overflow-hidden">
<div class="subject-card-image" style="background-image:<?=($s['image_mime']?'url(\'subject_image.php?id='.(int)$s['id'].'\')':'none')?>;">
<div class="subject-card-overlay"></div>
<div class="card-body position-relative d-flex flex-column justify-content-end" style="min-height:180px;">
<h2 class="h4 text-white mb-1 subject-card-title"><?=e($s['name'])?></h2>
<?php if($s['description']):?><p class="mb-2 subject-card-description"><?=e($s['description'])?></p><?php endif;?>
<span class="badge text-bg-primary align-self-start"><?=$s['test_count']?> <?=((int)$s['test_count']===1?'sub-test':'sub-testen')?></span>
</div>
</div>
</div>
</a>
</div>
<?php endforeach;?>
</div><?php if(!$subjects):?><div class="alert alert-info mt-3">Er zijn nog geen vakken met actieve sub-testen.</div><?php endif;?></main></body></html>
