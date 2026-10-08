<?php
require __DIR__.'/../app/bootstrap.php';require_admin();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('admin.php');

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete_topic'){
    $topicId=filter_var($_POST['topic_id']??null,FILTER_VALIDATE_INT);
    if(!$topicId){http_response_code(400);exit('Ongeldig overhoring-ID.');}
    $x=$pdo->prepare("UPDATE topics SET is_active=0 WHERE id=? AND subject_id=?");
    $x->execute([$topicId,$id]);
    redirect('subject_manage.php?id='.$id);
}

$s=$pdo->prepare("SELECT id,name,description,image_mime FROM subjects WHERE id=?");$s->execute([$id]);$subject=$s->fetch();
if(!$subject){http_response_code(404);exit('Vak niet gevonden.');}

$tx=$pdo->prepare("SELECT id,name,test_date,is_active,created_at FROM topics WHERE subject_id=? AND is_active=1 ORDER BY test_date DESC, created_at DESC, name");
$tx->execute([$id]);$topics=$tx->fetchAll();

$x=$pdo->prepare("SELECT t.id,t.topic_id,COUNT(q.id) question_count FROM tests t JOIN topics tp ON tp.id=t.topic_id LEFT JOIN questions q ON q.test_id=t.id WHERE tp.subject_id=? AND t.is_active=1 GROUP BY t.id");
$x->execute([$id]);$tests=$x->fetchAll();

$x=$pdo->prepare("SELECT ts.id,ts.topic_id FROM topic_summaries ts JOIN topics tp ON tp.id=ts.topic_id WHERE tp.subject_id=? AND ts.is_active=1");
$x->execute([$id]);$summaries=$x->fetchAll();

$countsByTopic=[];
foreach($tests as $t){$topicId=(int)$t['topic_id'];if(!isset($countsByTopic[$topicId]))$countsByTopic[$topicId]=['tests'=>0,'summaries'=>0];$countsByTopic[$topicId]['tests']++;}
foreach($summaries as $summary){$topicId=(int)$summary['topic_id'];if(!isset($countsByTopic[$topicId]))$countsByTopic[$topicId]=['tests'=>0,'summaries'=>0];$countsByTopic[$topicId]['summaries']++;}
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($subject['name'])?> - Beheer</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4">
<div class="leren-actions mb-3"><a href="admin.php">&larr; Beheer</a></div>
<div class="card shadow-sm overflow-hidden">
<?php if($subject['image_mime']):?><img src="subject_image.php?id=<?=$id?>" style="height:180px;object-fit:cover" alt="<?=e($subject['name'])?>"><?php endif;?>
<div class="card-body p-4"><div class="d-flex justify-content-between align-items-start gap-3"><div class="min-w-0"><h1 class="h3 mb-1"><?=e($subject['name'])?></h1><?php if($subject['description']):?><p class="text-secondary mb-0"><?=e($subject['description'])?></p><?php endif;?></div><a class="btn btn-outline-secondary flex-shrink-0" href="subject_edit.php?id=<?=$id?>">Vak bewerken</a></div></div>
</div>
<div class="d-flex justify-content-between align-items-center gap-2 mt-4 mb-3"><h2 class="h4 mb-0">Overhoringen</h2><a class="btn btn-primary" href="topic_new.php?subject_id=<?=$id?>">Nieuwe overhoring</a></div>
<?php if(!$topics):?><div class="alert alert-info">Nog geen overhoringen voor dit vak.</div><?php else:?>
<div class="leren-list">
<?php foreach($topics as $topic):$topicId=(int)$topic['id'];$counts=$countsByTopic[$topicId]??['tests'=>0,'summaries'=>0];$archived=!empty($topic['test_date']) && $topic['test_date'] < date('Y-m-d');$parts=[];$parts[]=$counts['tests'].' '.($counts['tests']===1?'sub-test':'sub-testen');$parts[]=$counts['summaries'].' '.($counts['summaries']===1?'samenvatting':'samenvattingen');$parts[]=$topic['test_date']?date('d-m-Y',strtotime($topic['test_date'])):'Geen overhoringsdatum';$parts[]=$archived?'Gearchiveerd':'Actief';?>
<div class="leren-list-item">
<a class="leren-list-item-main" href="topic_manage.php?id=<?=$topicId?>">
<div class="leren-list-item-content"><div class="leren-list-item-heading"><strong class="leren-list-item-title"><?=e($topic['name'])?></strong></div><div class="leren-list-item-subtitle"><?=e(implode(' · ',$parts))?></div></div>
</a>
<button class="leren-list-item-menu" type="button" data-topic-menu data-id="<?=$topicId?>" data-title="<?=e($topic['name'])?>" aria-label="Opties voor <?=e($topic['name'])?>"><span></span><span></span><span></span></button>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<div class="leren-modal" id="topicOptionsModal" hidden><div class="leren-modal-backdrop" data-topic-modal-close></div><div class="leren-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="topicOptionsTitle"><button class="leren-modal-close" type="button" data-topic-modal-close aria-label="Sluiten">&times;</button><h2 id="topicOptionsTitle">Opties</h2><div class="leren-modal-actions" id="topicOptionsActions"></div></div></div>
<script>
(function(){
const modal=document.getElementById('topicOptionsModal'),title=document.getElementById('topicOptionsTitle'),actions=document.getElementById('topicOptionsActions');
function closeModal(){modal.hidden=true;document.body.classList.remove('leren-modal-open');}
function openModal(button){const itemId=button.dataset.id;title.textContent=button.dataset.title||'Overhoring';actions.innerHTML='';
const open=document.createElement('a');open.className='leren-modal-action primary';open.href='topic_manage.php?id='+encodeURIComponent(itemId);open.textContent='Overhoring openen';actions.appendChild(open);
const edit=document.createElement('a');edit.className='leren-modal-action';edit.href='topic_edit.php?id='+encodeURIComponent(itemId);edit.textContent='Overhoring bewerken';actions.appendChild(edit);
const ai=document.createElement('a');ai.className='leren-modal-action';ai.href='ai_test_generator.php?topic_id='+encodeURIComponent(itemId);ai.textContent='AI toets maken';actions.appendChild(ai);\nconst form=document.createElement('form');form.method='post';form.action='subject_manage.php?id='+'<?=e((string)$id)?>';form.dataset.confirm='Weet u zeker dat u deze overhoring wilt verwijderen? De overhoring verdwijnt uit de website, maar blijft in de database bewaard.';form.innerHTML='<input type="hidden" name="action" value="delete_topic"><input type="hidden" name="topic_id" value="'+itemId+'">';const del=document.createElement('button');del.type='submit';del.className='leren-modal-action danger';del.textContent='Verwijderen';form.appendChild(del);actions.appendChild(form);
modal.hidden=false;document.body.classList.add('leren-modal-open');}
document.querySelectorAll('[data-topic-menu]').forEach(b=>b.addEventListener('click',()=>openModal(b)));
document.querySelectorAll('[data-topic-modal-close]').forEach(el=>el.addEventListener('click',closeModal));
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)closeModal();});
})();
</script>
</main></body></html>