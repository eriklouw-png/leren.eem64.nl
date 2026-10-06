<?php
require __DIR__.'/../app/bootstrap.php';

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('index.php');

$s=$pdo->prepare("SELECT a.id,a.score,a.mode,a.source_attempt_id,a.test_id,t.title,t.test_type,t.topic_id,tp.subject_id FROM attempts a JOIN tests t ON t.id=a.test_id JOIN topics tp ON tp.id=t.topic_id WHERE a.id=?");
$s->execute([$id]);$r=$s->fetch();
if(!$r){http_response_code(404);exit('Resultaat niet gevonden.');}

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
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resultaat</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light">
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