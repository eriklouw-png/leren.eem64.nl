<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require_admin();
require_once __DIR__.'/../app/trash_schema.php';trash_schema($pdo);

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals((string)($_SESSION['trash_csrf']??''),(string)($_POST['csrf']??''))){
        http_response_code(403);exit('Ongeldige beveiligingscode.');
    }
    $kind=(string)($_POST['kind']??'');
    $action=(string)($_POST['action']??'');
    $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
    if(!$id || !in_array($kind,['subject','ai_summary','topic','test','summary'],true) || !in_array($action,['restore','delete'],true)){
        http_response_code(400);exit('Ongeldige actie.');
    }
    if($action==='delete' && (string)($_POST['confirm']??'')!=='DEFINITIEF VERWIJDEREN'){
        http_response_code(400);exit('Bevestiging ontbreekt.');
    }
    $moved=[];$trashDir=null;
    $pdo->beginTransaction();
    try{
        if($action==='delete' && $kind==='subject'){
            $st=$pdo->prepare('SELECT id FROM subjects WHERE id=? AND deleted_at IS NOT NULL FOR UPDATE');
            $st->execute([$id]);if(!$st->fetchColumn())throw new RuntimeException('Vak niet in prullenbak.');
            foreach(['topics'=>'subject_id','ai_source_collections'=>'subject_id'] as $table=>$column){
                $st=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column=?");
                $st->execute([$id]);if((int)$st->fetchColumn()>0)throw new RuntimeException('Dit vak bevat nog gekoppelde gegevens. Verwijder deze eerst afzonderlijk; het vak blijft herstelbaar.');
            }
            $pdo->prepare('DELETE FROM subjects WHERE id=? AND deleted_at IS NOT NULL')->execute([$id]);
        }elseif($action==='delete' && $kind==='ai_summary'){
            $st=$pdo->prepare('SELECT id FROM ai_source_collections WHERE id=? AND deleted_at IS NOT NULL FOR UPDATE');
            $st->execute([$id]);if(!$st->fetchColumn())throw new RuntimeException('Samenvatting niet in prullenbak.');
            foreach(['ai_source_pages','ai_source_links'] as $table){
                $st=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE collection_id=?");
                $st->execute([$id]);if((int)$st->fetchColumn()>0)throw new RuntimeException('Deze samenvatting heeft nog AI-bronnen of vraagkoppelingen en kan niet veilig definitief worden verwijderd.');
            }
            $pdo->prepare('DELETE FROM ai_source_sections WHERE collection_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM ai_source_collections WHERE id=? AND deleted_at IS NOT NULL')->execute([$id]);
        }
        if($action==='delete' && !in_array($kind,['subject','ai_summary'],true)){
            if($kind==='topic'){
                $st=$pdo->prepare('SELECT id FROM topics WHERE id=? AND is_active=0 FOR UPDATE');
                $st->execute([$id]);if(!$st->fetchColumn())throw new RuntimeException('Overhoring niet meer in prullenbak.');
                $st=$pdo->prepare('SELECT id FROM tests WHERE topic_id=?');$st->execute([$id]);$testIds=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
            }elseif($kind==='test'){
                $st=$pdo->prepare('SELECT id FROM tests WHERE id=? AND is_active=0 FOR UPDATE');
                $st->execute([$id]);if(!$st->fetchColumn())throw new RuntimeException('Sub-test niet meer in prullenbak.');
                $testIds=[$id];
            }else{
                $st=$pdo->prepare('SELECT id FROM topic_summaries WHERE id=? AND is_active=0 FOR UPDATE');
                $st->execute([$id]);if(!$st->fetchColumn())throw new RuntimeException('Samenvatting niet meer in prullenbak.');
                $pdo->prepare('DELETE FROM ai_source_links WHERE summary_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM topic_summaries WHERE id=?')->execute([$id]);
                $testIds=[];
            }
            if($testIds){
                $ph=implode(',',array_fill(0,count($testIds),'?'));
                $st=$pdo->prepare("SELECT DISTINCT image_path FROM questions WHERE test_id IN ($ph) AND image_path IS NOT NULL AND image_path<>''");
                $st->execute($testIds);$images=$st->fetchAll(PDO::FETCH_COLUMN);
                $shared=$pdo->prepare('SELECT COUNT(*) FROM questions WHERE image_path=? AND test_id NOT IN ('. $ph .')');
                $base=__DIR__.'/uploads/questions';
                $trashDir=__DIR__.'/../storage/trash-pending-'.bin2hex(random_bytes(10));
                foreach($images as $img){
                    if(!is_string($img)||basename($img)!==$img||$img==='.'||$img==='..')throw new RuntimeException('Onveilig afbeeldingspad.');
                    $shared->execute(array_merge([$img],$testIds));
                    if((int)$shared->fetchColumn()!==0)continue;
                    $src=$base.'/'.$img;
                    if(!is_file($src))continue;
                    if(!is_dir($trashDir) && !mkdir($trashDir,0700,true))throw new RuntimeException('Kan tijdelijke opslag niet aanmaken.');
                    if(!rename($src,$trashDir.'/'.$img))throw new RuntimeException('Afbeelding verplaatsen mislukt.');
                    $moved[]=$img;
                }
                $pdo->prepare("DELETE FROM ai_source_links WHERE question_id IN (SELECT id FROM questions WHERE test_id IN ($ph))")->execute($testIds);
                $pdo->prepare("DELETE FROM tests WHERE id IN ($ph)")->execute($testIds);
            }
            if($kind==='topic'){
                $pdo->prepare('DELETE FROM ai_source_links WHERE summary_id IN (SELECT id FROM topic_summaries WHERE topic_id=?)')->execute([$id]);
                $pdo->prepare('DELETE FROM topics WHERE id=?')->execute([$id]);
            }
        }elseif($kind==='subject'){
            $s=$pdo->prepare('SELECT id FROM subjects WHERE id=? AND deleted_at IS NOT NULL FOR UPDATE');$s->execute([$id]);if(!$s->fetchColumn())throw new RuntimeException('Vak niet in prullenbak.');
            $pdo->prepare('UPDATE subjects SET deleted_at=NULL WHERE id=?')->execute([$id]);
        }elseif($kind==='ai_summary'){
            $s=$pdo->prepare('SELECT id FROM ai_source_collections WHERE id=? AND deleted_at IS NOT NULL FOR UPDATE');$s->execute([$id]);if(!$s->fetchColumn())throw new RuntimeException('Samenvatting niet in prullenbak.');
            $pdo->prepare('UPDATE ai_source_collections SET deleted_at=NULL WHERE id=?')->execute([$id]);
        }elseif($kind==='topic'){
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
        if($action==='delete'){
            foreach($moved as $img){if(!@unlink($trashDir.'/'.$img))error_log('Trash cleanup: bestand nog in '.$trashDir.'/'.$img);}
            if($trashDir && is_dir($trashDir))@rmdir($trashDir);
        }
        redirect('trash.php?'.($action==='delete'?'deleted':'restored').'=1');
    }catch(Throwable $e){
        if($pdo->inTransaction()){
            $pdo->rollBack();
            foreach(array_reverse($moved) as $img){
                if(!@rename($trashDir.'/'.$img,__DIR__.'/uploads/questions/'.$img))error_log('KRITIEK: herstel afbeelding handmatig: '.$trashDir.'/'.$img);
            }
        }
        http_response_code(409);exit(e($e->getMessage()));
    }
}
$_SESSION['trash_csrf']??=bin2hex(random_bytes(32));
$subjects=$pdo->query("SELECT id,name FROM subjects WHERE deleted_at IS NOT NULL ORDER BY id DESC")->fetchAll();
$aiSummaries=$pdo->query("SELECT c.id,c.title name,COALESCE(tp.name,'Onbekend') topic_name FROM ai_source_collections c LEFT JOIN topics tp ON tp.id=c.topic_id WHERE c.deleted_at IS NOT NULL ORDER BY c.id DESC")->fetchAll();
$topics=$pdo->query('SELECT tp.id,tp.name,s.name subject_name FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.is_active=0 ORDER BY tp.id DESC')->fetchAll();
$tests=$pdo->query('SELECT t.id,t.title,tp.name topic_name,COALESCE(tp.is_active,0) parent_active FROM tests t LEFT JOIN topics tp ON tp.id=t.topic_id WHERE t.is_active=0 ORDER BY t.id DESC')->fetchAll();
$summaries=$pdo->query('SELECT sm.id,sm.name,tp.name topic_name,tp.is_active parent_active FROM topic_summaries sm JOIN topics tp ON tp.id=sm.topic_id WHERE sm.is_active=0 ORDER BY sm.id DESC')->fetchAll();
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Prullenbak - Beheer</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-4">
<a href="admin.php">&larr; Terug naar Beheer</a>
<div class="d-flex justify-content-between align-items-center mt-3 mb-3"><h1>Prullenbak</h1><span class="badge text-bg-secondary">Alleen administrator</span></div>
<?php if(isset($_GET['restored'])):?><div class="alert alert-success">Item hersteld.</div><?php endif;?>
<?php if(isset($_GET['deleted'])):?><div class="alert alert-success">Item definitief verwijderd.</div><?php endif;?>
<p>Verwijderde vakken, overhoringen, sub-testen en samenvattingen kunnen hier worden hersteld. Definitief verwijderen is onomkeerbaar. Vakken met gekoppelde gegevens en AI-samenvattingen met bronmateriaal of vraagkoppelingen worden uit veiligheid niet definitief verwijderd.</p>
<?php foreach([['Vakken','subject',$subjects],['Samenvattingen','ai_summary',$aiSummaries],['Overhoringen','topic',$topics],['Sub-testen','test',$tests],['Samenvattingen (oud model)','summary',$summaries]] as [$heading,$kind,$items]):?>
<section class="card mb-4"><div class="card-body"><h2 class="h5"><?=e($heading)?> <small class="text-secondary">(<?=count($items)?>)</small></h2>
<?php if(!$items):?><p class="text-secondary mb-0">Geen verwijderde items.</p><?php else:?><div class="list-group">
<?php foreach($items as $item):?>
<div class="list-group-item d-flex align-items-center justify-content-between gap-3">
<div><strong><?=e($item['name']??$item['title'])?></strong><div class="small text-secondary"><?=e($item['subject_name']??$item['topic_name']??'')?></div></div>
<form method="post" class="m-0"><input type="hidden" name="csrf" value="<?=e($_SESSION['trash_csrf'])?>"><input type="hidden" name="kind" value="<?=e($kind)?>"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><input type="hidden" name="action" value="restore">
<button class="btn btn-outline-primary btn-sm" type="submit" <?=isset($item['parent_active']) && (int)$item['parent_active']!==1?'disabled title="Herstel eerst de overhoring"':''?>>Herstellen</button></form>
<form method="post" class="m-0" data-confirm="Dit item en gekoppelde gegevens definitief verwijderen? Dit kan niet ongedaan worden gemaakt."><input type="hidden" name="csrf" value="<?=e($_SESSION['trash_csrf'])?>"><input type="hidden" name="kind" value="<?=e($kind)?>"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="confirm" value="DEFINITIEF VERWIJDEREN"><button class="btn btn-outline-danger btn-sm" type="submit">Definitief verwijderen</button></form>
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
