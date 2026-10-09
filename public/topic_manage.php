<?php
require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/ai_source_archive.php';require_admin();
ai_source_archive_tables($pdo);
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('admin.php');

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    $itemId=filter_var($_POST['item_id']??null,FILTER_VALIDATE_INT);
    if(!$itemId){http_response_code(400);exit('Ongeldig ID.');}
    if($action==='delete_summary'){
        $x=$pdo->prepare("DELETE FROM ai_source_sections WHERE collection_id=? AND collection_id IN (SELECT id FROM ai_source_collections WHERE topic_id=?)");
        $x->execute([$itemId,$id]);
        redirect('topic_manage.php?id='.$id);
    }
    if($action==='delete_test'){
        $x=$pdo->prepare("UPDATE tests SET is_active=0 WHERE id=? AND topic_id=?");
        $x->execute([$itemId,$id]);
        redirect('topic_manage.php?id='.$id);
    }
}

$s=$pdo->prepare("SELECT tp.id,tp.name,tp.test_date,tp.is_active,s.id subject_id,s.name subject_name FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
$s->execute([$id]);$topic=$s->fetch();
if(!$topic){http_response_code(404);exit('Overhoring niet gevonden.');}

$summaries=ai_source_summary_list($pdo,$id);

$x=$pdo->prepare("SELECT t.id,t.topic_id,t.title,t.description,t.test_type,t.vocab_direction,t.is_active,COUNT(q.id) question_count FROM tests t LEFT JOIN questions q ON q.test_id=t.id WHERE t.topic_id=? AND t.is_active=1 GROUP BY t.id ORDER BY t.created_at DESC,t.id DESC");
$x->execute([$id]);$tests=$x->fetchAll();

$labels=['vocabulary'=>'Woordjes oefenen','sentences'=>'Zinnen oefenen','multiple_choice'=>'Multiple choice','open'=>'Open vragen','mixed'=>'Combinatie'];
$archived=!empty($topic['test_date']) && $topic['test_date'] < date('Y-m-d');
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($topic['name'])?> - Beheer</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4">
<div class="leren-actions mb-3"><a href="subject_manage.php?id=<?=$topic['subject_id']?>">&larr; <?=e($topic['subject_name'])?></a></div>

<div class="card shadow-sm mb-4"><div class="card-body p-4">
<div class="d-flex justify-content-between align-items-start gap-3">
<div class="min-w-0"><div class="leren-meta mb-1"><?=e($topic['subject_name'])?></div><h1 class="mb-1"><?=e($topic['name'])?></h1><div class="leren-meta"><?php if($topic['test_date']):?><?=e(date('d-m-Y',strtotime($topic['test_date'])))?> · <?= $archived?'Gearchiveerd':'Actief' ?><?php else:?>Geen overhoringsdatum<?php endif;?></div></div>
<a class="btn btn-outline-secondary flex-shrink-0" href="topic_edit.php?id=<?=$id?>">Overhoring bewerken</a>
</div></div></div>

<div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h2 class="h4 mb-0">Samenvattingen</h2><a class="btn btn-primary" href="summary_edit.php?topic_id=<?=$id?>">Nieuwe samenvatting</a></div>
<?php if(!$summaries):?><div class="alert alert-secondary mb-4">Nog geen samenvattingen voor deze overhoring.</div><?php else:?>
<div class="leren-list mb-4">
<?php foreach($summaries as $summary):?>
<?php
$menuActions=[
    ['label'=>'Samenvatting bewerken','href'=>'summary_edit.php?id='.(int)$summary['id'],'primary'=>true],
    ['type'=>'form','label'=>'Verwijderen','danger'=>true,'action'=>'topic_manage.php?id='.$id,'fields'=>['action'=>'delete_summary','item_id'=>(int)$summary['id']],'confirm'=>'Weet u zeker dat u deze samenvatting wilt verwijderen? De samenvatting verdwijnt uit de website, maar blijft in de database bewaard.'],
];
?>
<div class="leren-list-item">
<a class="leren-list-item-main" href="summary_edit.php?id=<?=$summary['id']?>"><div class="leren-list-item-content"><div class="leren-list-item-heading"><strong class="leren-list-item-title"><?=e($summary['name'])?></strong></div><div class="leren-list-item-subtitle">Bijgewerkt <?=e(date('d-m-Y',strtotime((string)($summary['updated_at']?:$summary['created_at']))))?></div></div></a>
<button class="leren-list-item-menu" type="button" data-list-menu data-list-modal="manageOptionsModal" data-menu-title="<?=e($summary['name'])?>" data-list-actions="<?=e(json_encode($menuActions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>" aria-label="Opties voor <?=e($summary['name'])?>"><span></span><span></span><span></span></button>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap"><h2 class="h4 mb-0">Sub-testen</h2><div class="d-flex gap-2 flex-wrap"><a class="btn btn-primary" href="ai_test_generator.php?topic_id=<?=$id?>">AI-test maken</a><a class="btn btn-primary" href="test_new.php?topic_id=<?=$id?>">Nieuwe sub-test</a></div></div>
<?php if(!$tests):?><div class="alert alert-info">Nog geen sub-testen binnen deze overhoring.</div><?php else:?>
<div class="leren-list">
<?php foreach($tests as $t):?>
<?php $questionCount=((in_array(($t['test_type']??'mixed'),['vocabulary','sentences'],true) && ($t['vocab_direction']??'both')==='both') ? (int)ceil(((int)$t['question_count'])/2) : (int)$t['question_count']); ?>
<?php
$menuActions=[
    ['label'=>'Sub-test bewerken','href'=>'test_edit.php?id='.(int)$t['id'],'primary'=>true],
    ['label'=>'Vragen beheren','href'=>'questions.php?test_id='.(int)$t['id']],
    ['type'=>'form','label'=>'Verwijderen','danger'=>true,'action'=>'topic_manage.php?id='.$id,'fields'=>['action'=>'delete_test','item_id'=>(int)$t['id']],'confirm'=>'Weet u zeker dat u deze sub-test wilt verwijderen? De sub-test verdwijnt uit de website, maar blijft in de database bewaard.'],
];
?>
<div class="leren-list-item">
<a class="leren-list-item-main" href="test_edit.php?id=<?=$t['id']?>"><div class="leren-list-item-content"><div class="leren-list-item-heading"><strong class="leren-list-item-title"><?=e($t['title'])?></strong></div><div class="leren-list-item-subtitle"><?=e($labels[$t['test_type']??'mixed']??'Combinatie')?> · <?=$questionCount?> <?=($t['test_type']??'mixed')==='vocabulary'?'woorden':(($t['test_type']??'mixed')==='sentences'?'zinnen':'vragen')?> · <?=((int)$t['is_active']?'Actief':'Inactief')?></div><?php if($t['description']):?><div class="leren-list-item-description"><?=e($t['description'])?></div><?php endif;?></div></a>
<button class="leren-list-item-menu" type="button" data-list-menu data-list-modal="manageOptionsModal" data-menu-title="<?=e($t['title'])?>" data-list-actions="<?=e(json_encode($menuActions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>" aria-label="Opties voor <?=e($t['title'])?>"><span></span><span></span><span></span></button>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<div class="leren-modal" id="manageOptionsModal" data-list-modal hidden aria-hidden="true">
<div class="leren-modal-backdrop" data-list-modal-close></div>
<div class="leren-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="manageOptionsTitle">
<button class="leren-modal-close" type="button" data-list-modal-close aria-label="Sluiten">&times;</button>
<h2 id="manageOptionsTitle" data-list-modal-title>Opties</h2>
<div class="leren-modal-actions" data-list-modal-actions></div>
</div>
</div>
</main></body></html>