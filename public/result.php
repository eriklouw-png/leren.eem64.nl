<?php
require __DIR__.'/../app/bootstrap.php';
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);if(!$id)redirect('index.php');
$s=$pdo->prepare("SELECT a.id,a.score,t.title FROM attempts a JOIN tests t ON t.id=a.test_id WHERE a.id=?");$s->execute([$id]);$r=$s->fetch();if(!$r){http_response_code(404);exit('Resultaat niet gevonden.');}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resultaat</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-5" style="max-width:700px"><div class="card shadow-sm text-center"><div class="card-body p-5"><h1>Resultaat</h1><p><?=e($r['title'])?></p><div class="display-3 fw-bold"><?=e((string)$r['score'])?>%</div><p class="mt-3">De uitslag is opgeslagen.</p><a class="btn btn-primary" href="index.php">Terug naar toetsen</a></div></div></main></body></html>
