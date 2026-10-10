<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require_admin();

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals((string)($_SESSION['trash_csrf']??''),(string)($_POST['csrf']??''))){
        http_response_code(403);exit('Ongeldige beveiligingscode.');
    }
    $kind=(string)($_POST['kind']??'');
    $action=(string)($_POST['action']??'');
    $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
    if(!$id || !in_array($kind,['topic','test','summary'],true) || $action!=='restore'){
        http_response_code(400);exit('Ongeldige actie.');
    }
    $pdo->beginTransaction();
    try{
        if($kind==='topic'){
            $s=$pdo->prepare('SELECT id FROM topics WHERE id=? AND is_active=0 FOR UPDATE');
            $s->execute([$id]);
            if(!$s->fetchColumn())throw new RuntimeException('Overhoring niet meer in prullenbak.');
            $pdo->prepare('UPDATE topics SET is_active=1 WHERE id=?')->execute([$id]);
        }elseif($kind==='test'){
            $s=$pdo->prepare('SELECT t.id,tp.is_active FROM tests t LEFT JOIN topics tp ON tp.id=t.topic_id WHERE t.id=? AND t.is_active=0 FOR UPDATE');
            $s->execute([$id]);$row=$s->fetch();
            if(!$row)throw new RuntimeException('Sub-test niet meer in prullenbak.');
            if((int)$row['is_active']!==1)throw new RuntimeException('Herstel eerst de bovenliggende overhoring.');
            $pdo->prepare('UPDATE tests SET is_active=1 WHERE id=?')->execute([$id]);
        }else{
            $s=$pdo->prepare('SELECT sm.id,tp.is_active FROM topic_summaries sm JOIN topics tp ON tp.id=sm.topic_id WHERE sm.id=? AND sm.is_active=0 FOR UPDATE');
            $s->execute([$id]);$row=$s->fetch();
            if(!$row)throw new RuntimeException('Samenvatting niet meer in prullenbak.');
            if((int)$row['is_active']!==1)throw new RuntimeException('Herstel eerst de bovenliggende overhoring.');
            $pdo->prepare('UPDATE topic_summaries SET is_active=1,deleted_at=NULL WHERE id=?')->execute([$id]);
        }
        $pdo->commit();
        redirect('trash.php?restored=1');
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        http_response_code(409);exit(e($e->getMessage()));
    }
}
$_SESSION['trash_csrf']??=bin2hex(random_bytes(32));
$topics=$pdo->query('SELECT tp.id,tp.name,s.name subject_name FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.is_active=0 ORDER BY tp.id DESC')->fetchAll();
$tests=$pdo->query('SELECT t.id,t.title,tp.name topic_name,COALESCE(tp.is_active,0) parent_active FROM tests t LEFT JOIN topics tp ON tp.id=t.topic_id WHERE t.is_active=0 ORDER BY t.id DESC')->fetchAll();
$summaries=$pdo->query('SELECT sm.id,sm.name,tp.name topic_name,tp.is_active parent_active FROM topic_summaries sm JOIN topics tp ON tp.id=sm.topic_id WHERE sm.is_active=0 ORDER BY sm.id DESC')->fetchAll();
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Prullenbak - Beheer</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-4">
<a href="admin.php">&larr; Terug naar Beheer</a>
<div class="d-flex justify-content-between align-items-center mt-3 mb-3"><h1>Prullenbak</h1><span class="badge text-bg-secondary">Alleen administrator</span></div>
<?php if(isset($_GET['restored'])):?><div class="alert alert-success">Item hersteld.</div><?php endif;?>
<p>Verwijderde overhoringen, sub-testen en oudere samenvattingen kunnen hier worden hersteld. Definitief verwijderen wordt pas beschikbaar nadat ook de bijbehorende bestanden en AI-koppelingen veilig kunnen worden gecontroleerd.</p>
<?php foreach([['Overhoringen','topic',$topics],['Sub-testen','test',$tests],['Samenvattingen (oud model)','summary',$summaries]] as [$heading,$kind,$items]):?>
<section class="card mb-4"><div class="card-body"><h2 class="h5"><?=e($heading)?> <small class="text-secondary">(<?=count($items)?>)</small></h2>
<?php if(!$items):?><p class="text-secondary mb-0">Geen verwijderde items.</p><?php else:?><div class="list-group">
<?php foreach($items as $item):?>
<div class="list-group-item d-flex align-items-center justify-content-between gap-3">
<div><strong><?=e($item['name']??$item['title'])?></strong><div class="small text-secondary"><?=e($item['subject_name']??$item['topic_name']??'')?></div></div>
<form method="post" class="m-0"><input type="hidden" name="csrf" value="<?=e($_SESSION['trash_csrf'])?>"><input type="hidden" name="kind" value="<?=e($kind)?>"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><input type="hidden" name="action" value="restore">
<button class="btn btn-outline-primary btn-sm" type="submit" <?=isset($item['parent_active']) && (int)$item['parent_active']!==1?'disabled title="Herstel eerst de overhoring"':''?>>Herstellen</button></form>
</div>
<?php endforeach;?></div><?php endif;?></div></section>
<?php endforeach;?>
<section class="card mb-4"><div class="card-body"><h2 class="h5">Eerdere opschoning en back-ups</h2>
<p>De bestanden van 11 oktober 2026 zijn veilig bewaard, maar worden bewust niet rechtstreeks vanuit de website verwijderd.</p>
<div class="list-group">
<div class="list-group-item"><strong>Quarantaine</strong><div class="small text-secondary">102 afbeeldingen — public/uploads/.cleanup-quarantine-20261010_221706</div></div>
<div class="list-group-item"><strong>Databaseback-up</strong><div class="small text-secondary">leren_20261011_000117.sql</div></div>
<div class="list-group-item"><strong>Uploadback-up</strong><div class="small text-secondary">uploads_20261011_000009.tar.gz</div></div>
</div><p class="small text-secondary mt-3 mb-0">Dit zijn geregistreerde referenties, geen live bestandscontrole. Bestanden kunnen uitsluitend via een afzonderlijke, beveiligde onderhoudstaak definitief worden verwijderd.</p>
</div></section></main></body></html>
