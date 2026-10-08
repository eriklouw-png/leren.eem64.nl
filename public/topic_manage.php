<?php
require __DIR__.'/../app/bootstrap.php';require_admin();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('admin.php');

$s=$pdo->prepare("SELECT tp.id,tp.name,tp.test_date,tp.is_active,s.id subject_id,s.name subject_name FROM topics tp JOIN subjects s ON s.id=tp.subject_id WHERE tp.id=?");
$s->execute([$id]);$topic=$s->fetch();
if(!$topic){http_response_code(404);exit('Overhoring niet gevonden.');}

$x=$pdo->prepare("SELECT ts.id,ts.topic_id,ts.name,ts.is_active,ts.updated_at,ts.created_at FROM topic_summaries ts WHERE ts.topic_id=? AND ts.is_active=1 ORDER BY ts.created_at,ts.id");
$x->execute([$id]);$summaries=$x->fetchAll();

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
<div class="leren-list-item">
<a class="leren-list-item-main" href="summary_edit.php?id=<?=$summary['id']?>"><div class="leren-list-item-content"><div class="leren-list-item-heading"><strong class="leren-list-item-title"><?=e($summary['name'])?></strong></div><div class="leren-list-item-subtitle">Bijgewerkt <?=e(date('d-m-Y',strtotime((string)($summary['updated_at']?:$summary['created_at']))))?></div></div></a>
<button class="leren-list-item-menu" type="button" data-summary-menu data-id="<?=$summary['id']?>" data-title="<?=e($summary['name'])?>" aria-label="Opties voor <?=e($summary['name'])?>"><span></span><span></span><span></span></button>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h2 class="h4 mb-0">Sub-testen</h2><a class="btn btn-primary" href="test_new.php?topic_id=<?=$id?>">Nieuwe sub-test</a></div>
<?php if(!$tests):?><div class="alert alert-info">Nog geen sub-testen binnen deze overhoring.</div><?php else:?>
<div class="leren-list">
<?php foreach($tests as $t):?>
<?php $questionCount=((in_array(($t['test_type']??'mixed'),['vocabulary','sentences'],true) && ($t['vocab_direction']??'both')==='both') ? (int)ceil(((int)$t['question_count'])/2) : (int)$t['question_count']); ?>
<div class="leren-list-item">
<a class="leren-list-item-main" href="test_edit.php?id=<?=$t['id']?>"><div class="leren-list-item-content"><div class="leren-list-item-heading"><strong class="leren-list-item-title"><?=e($t['title'])?></strong></div><div class="leren-list-item-subtitle"><?=e($labels[$t['test_type']??'mixed']??'Combinatie')?> · <?=$questionCount?> <?=($t['test_type']??'mixed')==='vocabulary'?'woorden':(($t['test_type']??'mixed')==='sentences'?'zinnen':'vragen')?> · <?=((int)$t['is_active']?'Actief':'Inactief')?></div><?php if($t['description']):?><div class="leren-list-item-description"><?=e($t['description'])?></div><?php endif;?></div></a>
<button class="leren-list-item-menu" type="button" data-test-menu data-id="<?=$t['id']?>" data-title="<?=e($t['title'])?>" aria-label="Opties voor <?=e($t['title'])?>"><span></span><span></span><span></span></button>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<div class="leren-modal" id="manageOptionsModal" hidden><div class="leren-modal-backdrop" data-manage-modal-close></div><div class="leren-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="manageOptionsTitle"><button class="leren-modal-close" type="button" data-manage-modal-close aria-label="Sluiten">&times;</button><h2 id="manageOptionsTitle">Opties</h2><div class="leren-modal-actions" id="manageOptionsActions"></div></div></div>

<script>
(function(){
const modal=document.getElementById('manageOptionsModal'),title=document.getElementById('manageOptionsTitle'),actions=document.getElementById('manageOptionsActions');
function closeModal(){modal.hidden=true;document.body.classList.remove('leren-modal-open');}
function addAction(label,href,primary){const a=document.createElement('a');a.className='leren-modal-action'+(primary?' primary':'');a.href=href;a.textContent=label;actions.appendChild(a);}
function openModal(button,type){
const itemId=button.dataset.id;title.textContent=button.dataset.title||'Opties';actions.innerHTML='';
if(type==='summary'){
addAction('Samenvatting bewerken','summary_edit.php?id='+encodeURIComponent(itemId),true);
const form=document.createElement('form');form.method='post';form.action='summary_edit.php?id='+encodeURIComponent(itemId);
form.innerHTML='<input type="hidden" name="id" value="'+itemId+'"><input type="hidden" name="subject_id" value="<?=e((string)$topic['subject_id'])?>"><input type="hidden" name="topic_id" value="<?=e((string)$id)?>">';
const b=document.createElement('button');b.type='submit';b.name='action';b.value='delete';b.className='leren-modal-action';b.textContent='Verwijderen';b.addEventListener('click',e=>{if(!confirm('Weet u zeker dat u deze samenvatting wilt verwijderen? De samenvatting verdwijnt uit de website, maar blijft in de database bewaard.'))e.preventDefault();});form.appendChild(b);actions.appendChild(form);
}else{
addAction('Sub-test bewerken','test_edit.php?id='+encodeURIComponent(itemId),true);
addAction('Vragen beheren','questions.php?test_id='+encodeURIComponent(itemId),false);
}
modal.hidden=false;document.body.classList.add('leren-modal-open');
}
document.querySelectorAll('[data-summary-menu]').forEach(b=>b.addEventListener('click',()=>openModal(b,'summary')));
document.querySelectorAll('[data-test-menu]').forEach(b=>b.addEventListener('click',()=>openModal(b,'test')));
document.querySelectorAll('[data-manage-modal-close]').forEach(el=>el.addEventListener('click',closeModal));
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)closeModal();});
})();
</script>
</main></body></html>