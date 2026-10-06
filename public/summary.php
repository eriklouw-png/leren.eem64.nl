<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/auth.php';
require_login();

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('index.php');

$x=$pdo->prepare("
    SELECT ts.id,ts.name,ts.summary,ts.updated_at,ts.created_at,
           tp.id topic_id,tp.name topic_name,
           s.id subject_id,s.name subject_name
    FROM topic_summaries ts
    JOIN topics tp ON tp.id=ts.topic_id
    JOIN subjects s ON s.id=tp.subject_id
    WHERE ts.id=? AND ts.is_active=1 AND tp.is_active=1
");
$x->execute([$id]);
$summary=$x->fetch();

if(!$summary){http_response_code(404);exit('Samenvatting niet gevonden.');}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($summary['name'])?> - Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-4" style="max-width:900px">
<a href="topic.php?id=<?=(int)$summary['topic_id']?>">&larr; Terug naar <?=e($summary['topic_name'])?></a>

<div class="card shadow-sm mt-3">
<div class="card-body p-4 p-md-5">
<div class="small text-secondary mb-2"><?=e($summary['subject_name'])?> · <?=e($summary['topic_name'])?></div>
<h1 class="h2 mb-1"><?=e($summary['name'])?></h1>
<?php if(!empty($summary['updated_at']) || !empty($summary['created_at'])):?>
<div class="small text-secondary mb-4">Bijgewerkt <?=e(date('d-m-Y',strtotime((string)($summary['updated_at']?:$summary['created_at']))))?></div>
<?php endif;?>
<div class="lh-lg"><?=nl2br(e((string)$summary['summary']))?></div>
</div>
</div>
</main>
</body>
</html>
