<?php
require __DIR__.'/../app/bootstrap.php';

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('index.php');

$s=$pdo->prepare("SELECT a.id,a.score,a.mode,a.source_attempt_id,a.test_id,t.title,t.test_type,t.topic_id,tp.subject_id FROM attempts a JOIN tests t ON t.id=a.test_id JOIN topics tp ON tp.id=t.topic_id WHERE a.id=?");
$s->execute([$id]);$r=$s->fetch();
if(!$r){http_response_code(404);exit('Resultaat niet gevonden.');}

$answeredCheck=$pdo->prepare("
    SELECT COUNT(*)
    FROM attempt_answers
    WHERE attempt_id=?
      AND (
          (answer_text IS NOT NULL AND TRIM(answer_text)<>'')
          OR selected_option_id IS NOT NULL
      )
");
$answeredCheck->execute([$id]);
if((int)$answeredCheck->fetchColumn()===0){
    $pdo->prepare("DELETE FROM attempts WHERE id=?")->execute([$id]);
    redirect('topic.php?id='.(int)$r['topic_id']);
}

$s=$pdo->prepare("
    SELECT
        q.id,q.question_text,q.question_type,q.explanation,
        aa.selected_option_id,aa.answer_text,aa.is_correct,
        aq.sort_order
    FROM attempt_questions aq
    JOIN questions q ON q.id=aq.question_id
    LEFT JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id
    WHERE aq.attempt_id=?
    ORDER BY aq.sort_order,q.id
");
$s->execute([$id]);$questions=$s->fetchAll();

$total=count($questions);
$correct=0;
foreach($questions as $q)if((int)$q['is_correct']===1)$correct++;
$score=$total?round($correct/$total*100,2):0;

$update=$pdo->prepare("UPDATE attempts SET score=? WHERE id=? AND status='finished'");
$update->execute([$score,$id]);

$optionStmt=$pdo->prepare("SELECT option_text FROM question_options WHERE id=? AND question_id=?");
$correctOptionStmt=$pdo->prepare("SELECT option_text FROM question_options WHERE question_id=? AND is_correct=1 ORDER BY sort_order,id");
$openAnswerStmt=$pdo->prepare("SELECT answer_text FROM open_question_answers WHERE question_id=? ORDER BY sort_order,id");

foreach($questions as &$q){
    $q['selected_text']=null;
    $q['correct_answers']=[];
    if($q['question_type']==='multiple_choice'){
        if($q['selected_option_id']!==null){
            $optionStmt->execute([(int)$q['selected_option_id'],(int)$q['id']]);
            $q['selected_text']=$optionStmt->fetchColumn()?:null;
        }
        $correctOptionStmt->execute([(int)$q['id']]);
        $q['correct_answers']=$correctOptionStmt->fetchAll(PDO::FETCH_COLUMN);
    }else{
        $openAnswerStmt->execute([(int)$q['id']]);
        $q['correct_answers']=$openAnswerStmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
unset($q);

$doneMistakes=$correct<$total;
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resultaat</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>
.confetti-overlay{
  position:fixed;
  inset:0;
  z-index:2000;
  pointer-events:none;
  overflow:hidden;
  display:flex;
  justify-content:center;
  align-items:center;
}
.confetti-message{
  position:relative;
  z-index:2;
  padding:1rem 1.6rem;
  border-radius:1rem;
  background:rgba(255,255,255,.94);
  box-shadow:0 12px 40px rgba(0,0,0,.18);
  font-size:clamp(1.8rem,6vw,3.2rem);
  font-weight:800;
  color:#198754;
  animation:confetti-pop .7s cubic-bezier(.2,.8,.2,1) both;
}
.confetti-piece{
  position:absolute;
  top:-24px;
  width:10px;
  height:16px;
  border-radius:2px;
  animation:confetti-fall var(--duration) linear var(--delay) forwards;
  transform:translate3d(0,0,0) rotate(0deg);
}
@keyframes confetti-fall{
  0%{transform:translate3d(0,-20px,0) rotate(0deg);opacity:1}
  100%{transform:translate3d(var(--drift),110vh,0) rotate(var(--rotation));opacity:0}
}
@keyframes confetti-pop{
  0%{transform:scale(.5);opacity:0}
  70%{transform:scale(1.08)}
  100%{transform:scale(1);opacity:1}
}
@media (prefers-reduced-motion:reduce){
  .confetti-piece{display:none}
  .confetti-message{animation:none}
}
</style>
</head>
<body class="bg-light">
<?php if($score>=100):?>
<div class="confetti-overlay" id="confettiOverlay" aria-hidden="true">
  <div class="confetti-message">🎉 100%! Goed gedaan!</div>
</div>
<script>
(function(){
  const overlay=document.getElementById('confettiOverlay');
  if(!overlay || window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
  const pieces=90;
  for(let i=0;i<pieces;i++){
    const el=document.createElement('span');
    el.className='confetti-piece';
    el.style.left=(Math.random()*100)+'%';
    el.style.width=(6+Math.random()*7)+'px';
    el.style.height=(9+Math.random()*11)+'px';
    el.style.background=['#198754','#0d6efd','#ffc107','#dc3545','#6f42c1','#fd7e14'][Math.floor(Math.random()*6)];
    el.style.setProperty('--duration',(2.2+Math.random()*1.8)+'s');
    el.style.setProperty('--delay',(Math.random()*.45)+'s');
    el.style.setProperty('--drift',((-120+Math.random()*240))+'px');
    el.style.setProperty('--rotation',((Math.random()>.5?1:-1)*(360+Math.random()*720))+'deg');
    overlay.appendChild(el);
  }
  window.setTimeout(()=>overlay.remove(),4500);
})();
</script>
<?php endif;?>
<main class="container py-4" style="max-width:850px">
<a href="topic.php?id=<?=(int)$r['topic_id']?>">&larr; Terug naar <?=e($r['title'])?></a>
<div class="card shadow-sm mt-3 mb-4 text-center">
  <div class="card-body p-4">
    <h1>Resultaat</h1>
    <p class="text-secondary mb-1"><?=e($r['title'])?></p>
    <div class="display-3 fw-bold"><?=e((string)$score)?>%</div>
    <p class="mt-2 mb-0"><?=e((string)$correct)?> van <?=e((string)$total)?> vragen goed.</p>
  </div>
</div>

<div class="d-grid gap-2 mb-4">
  <?php if($r['title']==='Fouten oefenen'):?>
  <a class="btn btn-primary" href="topic.php?id=<?=(int)$r['topic_id']?>">Terug naar de overhoring</a>
  <?php else:?>
  <a class="btn btn-primary" href="quiz.php?id=<?=(int)$r['test_id']?>&new=1">Sub-Test opnieuw maken</a>
  <?php if($doneMistakes):?><a class="btn btn-warning" href="quiz.php?id=<?=(int)$r['test_id']?>&mode=mistakes&source=<?=(int)$r['id']?>">Alleen mijn fouten oefenen</a><?php endif;?>
  <?php endif;?>
</div>

<?php foreach($questions as $i=>$q):?>
<div class="card shadow-sm mb-3">
  <div class="card-body">
    <div class="text-secondary small mb-2">Vraag <?=$i+1?> van <?=$total?></div>
    <h2 class="h5"><?=e($q['question_text'])?></h2>

    <?php if($q['answer_text']!==null && trim((string)$q['answer_text'])!==''):?>
      <div class="mt-3"><strong>Jouw antwoord:</strong><br><?=nl2br(e((string)$q['answer_text']))?></div>
    <?php elseif($q['selected_text']!==null):?>
      <div class="mt-3"><strong>Jouw antwoord:</strong> <?=e((string)$q['selected_text'])?></div>
    <?php else:?>
      <div class="mt-3 text-secondary"><strong>Jouw antwoord:</strong> niet ingevuld</div>
    <?php endif;?>

    <?php if((int)$q['is_correct']===1):?>
      <div class="alert alert-success mt-3 mb-0"><strong>Goed!</strong>
        <?php
          $isAi=false;
          if($q['question_type']==='open' && $q['answer_text']!==null){
              $isAi=!open_answer_matches((string)$q['answer_text'],$q['correct_answers']);
          }
        ?>
        <?php if($isAi):?><div class="small mt-1">✓ Inhoudelijk goedgekeurd door AI.</div><?php endif;?>
      </div>
    <?php else:?>
      <div class="alert alert-danger mt-3 mb-0">
        <strong>Helaas, fout.</strong>
        <?php if($q['correct_answers']):?><div class="mt-2"><strong>Juiste antwoord:</strong> <?=e(implode(' / ',$q['correct_answers']))?></div><?php endif;?>
      </div>
    <?php endif;?>

    <?php if(!empty($q['explanation'])):?><div class="text-secondary mt-3"><?=nl2br(e((string)$q['explanation']))?></div><?php endif;?>
  </div>
</div>
<?php endforeach;?>
</main></body></html>