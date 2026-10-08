<?php
require __DIR__.'/../app/bootstrap.php';
require_admin();
$pdo->exec("CREATE TABLE IF NOT EXISTS ai_test_rules (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, subject_id INT UNSIGNED NOT NULL, test_type VARCHAR(40) NOT NULL,
 label VARCHAR(120) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, allow_summary TINYINT(1) NOT NULL DEFAULT 0,
 allow_images TINYINT(1) NOT NULL DEFAULT 0, allow_multiple_choice TINYINT(1) NOT NULL DEFAULT 0, allow_open TINYINT(1) NOT NULL DEFAULT 1,
 recognition_instructions TEXT NULL, generation_instructions TEXT NULL, sort_order INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
 UNIQUE KEY uq_ai_test_rules_subject_type(subject_id,test_type), KEY idx_ai_test_rules_subject(subject_id),
 CONSTRAINT fk_ai_test_rules_subject FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=$_POST['action']??'';
 if($action==='save'){
  $ids=$_POST['id']??[];
  if(is_array($ids)){
   $stmt=$pdo->prepare("UPDATE ai_test_rules SET label=?,enabled=?,allow_summary=?,allow_images=?,allow_multiple_choice=?,allow_open=?,recognition_instructions=?,generation_instructions=?,sort_order=?,updated_at=NOW() WHERE id=?");
   foreach($ids as $id){$id=(int)$id;if($id<1)continue;$stmt->execute([
    trim((string)($_POST['label'][$id]??'')),isset($_POST['enabled'][$id])?1:0,isset($_POST['allow_summary'][$id])?1:0,
    isset($_POST['allow_images'][$id])?1:0,isset($_POST['allow_multiple_choice'][$id])?1:0,isset($_POST['allow_open'][$id])?1:0,
    trim((string)($_POST['recognition_instructions'][$id]??'')),trim((string)($_POST['generation_instructions'][$id]??'')),
    (int)($_POST['sort_order'][$id]??0),$id
   ]);}
  }
  redirect('ai_rules.php?saved=1');
 }
 if($action==='add'){
  $subjectId=filter_var($_POST['subject_id']??null,FILTER_VALIDATE_INT);$type=trim((string)($_POST['test_type']??''));$label=trim((string)($_POST['label']??''));
  if(!$subjectId||$type===''||$label==='')redirect('ai_rules.php?error=missing');
  $stmt=$pdo->prepare("INSERT INTO ai_test_rules(subject_id,test_type,label,sort_order) VALUES(?,?,?,99) ON DUPLICATE KEY UPDATE label=VALUES(label)");
  $stmt->execute([$subjectId,$type,$label]);redirect('ai_rules.php?saved=1');
 }
 if($action==='delete'){ $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);if($id)$pdo->prepare("DELETE FROM ai_test_rules WHERE id=?")->execute([$id]);redirect('ai_rules.php?saved=1');}
}
$subjects=$pdo->query("SELECT id,name FROM subjects ORDER BY name")->fetchAll();
$seedRules=[
 ['duits','vocabulary','Woordjes oefenen',1,0,0,0,1,'Herken woordenlijsten en woordparen, ook wanneer ze niet in twee kolommen staan. Herken meerdere blokken en secties. Neem expliciet vermelde meervouden en vrouwelijke vormen als afzonderlijke leeritems op.','Maak beide richtingen tussen de brontaal en Nederlands. Voeg alleen als de bron dit duidelijk aangeeft (mannelijk), (vrouwelijk) of (meervoud) toe. Verzin geen grammaticale vormen.',1],
 ['duits','sentences','Zinnen oefenen',1,0,0,0,1,'Herken pagina’s waarop volledige zinnen of voorbeeldzinnen met vertaling centraal staan. De zinnen hoeven niet in twee kolommen te staan.','Neem volledige zinnen letterlijk over en maak beide vertaalrichtingen. Behoud relevante leestekens en verander de inhoud van bronzinnen niet.',2],
 ['duits','grammar','Grammatica',1,0,0,1,1,'Herken grammatica-uitleg, regels, tabellen, vervoegingen en grammaticale voorbeelden. Behandel een pagina niet als woorden- of zinnenlijst wanneer grammatica duidelijk het hoofddoel is.','Maak toepassingsgerichte vragen over de regels en voorbeelden uit de bron. Gebruik zowel open vragen als multiple choice wanneer dat is toegestaan. Gebruik uitsluitend informatie uit de bron.',3]
];
$insert=$pdo->prepare("INSERT IGNORE INTO ai_test_rules(subject_id,test_type,label,enabled,allow_summary,allow_images,allow_multiple_choice,allow_open,recognition_instructions,generation_instructions,sort_order) SELECT id,?,?,?,?,?,?,?,?,?,? FROM subjects WHERE LOWER(name)=?");
foreach($seedRules as $r){
  $insert->execute([$r[1],$r[2],$r[3],$r[4],$r[5],$r[6],$r[7],$r[8],$r[9],$r[10],$r[0]]);
}
$copyRules=$pdo->query("SELECT * FROM ai_test_rules WHERE subject_id=(SELECT id FROM subjects WHERE LOWER(name)='duits' LIMIT 1) ORDER BY sort_order")->fetchAll();
$copyStmt=$pdo->prepare("INSERT IGNORE INTO ai_test_rules(subject_id,test_type,label,enabled,allow_summary,allow_images,allow_multiple_choice,allow_open,recognition_instructions,generation_instructions,sort_order) SELECT id,?,?,?,?,?,?,?,?,?,? FROM subjects WHERE LOWER(name)=?");
foreach(['engels','spaans'] as $target){
    foreach($copyRules as $r){
        $copyStmt->execute([$r['test_type'],$r['label'],$r['enabled'],$r['allow_summary'],$r['allow_images'],$r['allow_multiple_choice'],$r['allow_open'],$r['recognition_instructions'],$r['generation_instructions'],$r['sort_order'],$target]);
    }
}
$newSubjectRules=[
 ['nederlands','mixed','Nederlands oefenen',1,1,1,1,1,'Herken leerstofpagina’s met Nederlandse taalvaardigheid, lezen, spelling, woordenschat, tekstbegrip en andere Nederlandstalige oefenstof. Bepaal op basis van de bron welk onderdeel centraal staat.','Maak een gevarieerde oefentoets die de belangrijkste leerstof uit de bron toetst. Gebruik open vragen en multiple choice wanneer passend. Gebruik afbeeldingen wanneer ze inhoudelijk iets verduidelijken, niet als decoratie. Maak ook een leersamenvatting wanneer daarvoor gekozen is. Gebruik uitsluitend informatie uit de bron.',1],
 ['nederlands','grammar','Grammatica',1,1,1,1,1,'Herken Nederlandse grammatica-uitleg, grammaticale begrippen, woordsoorten, zinsdelen, werkwoordspelling, vervoegingen, tijden en grammaticale schema’s of voorbeelden. Herken grammatica als apart type wanneer grammaticale regels en toepassing het hoofddoel van de pagina zijn.','Maak toepassingsgerichte grammatica-oefeningen. Laat de leerling regels herkennen én zelfstandig toepassen. Gebruik zowel open vragen als multiple choice wanneer passend. Neem voorbeelden uit de bron als uitgangspunt en verzin geen grammaticale regels die niet uit de bron volgen.',2],
 ['biologie','mixed','Biologie oefenen',1,1,1,1,1,'Herken biologische leerstof over bijvoorbeeld organismen, cellen, organen, lichaamsstelsels, voortplanting, erfelijkheid, ecologie, stofwisseling en biologische processen. Herken schema’s, doorsneden, tabellen, grafieken en afbeeldingen die onderdeel zijn van de leerstof.','Maak een gevarieerde oefentoets waarin begrippen, processen, functies en verbanden uit de bron worden getoetst. Gebruik open vragen en multiple choice wanneer passend. Gebruik afbeeldingen wanneer deze noodzakelijk of duidelijk ondersteunend zijn, bijvoorbeeld bij anatomie, celstructuren, biologische schema’s of processen. Controleer dat een afbeelding inhoudelijk klopt met de vraag.',1],
 ['geschiedenis','mixed','Geschiedenis oefenen',1,1,1,1,1,'Herken historische leerstof over personen, gebeurtenissen, perioden, ontwikkelingen, begrippen, oorzaken en gevolgen, chronologie, bronnen en historische kaarten of afbeeldingen. Herken tijdlijnen, kaarten, bronfragmenten, afbeeldingen en schema’s als onderdeel van de leerstof.','Maak een gevarieerde oefentoets over de belangrijkste kennis en verbanden uit de bron. Toets niet alleen losse feiten, maar ook chronologie, oorzaak en gevolg, verandering en continuïteit en het plaatsen van gebeurtenissen in hun historische context wanneer de bron dit ondersteunt. Gebruik open vragen en multiple choice wanneer passend. Gebruik historische afbeeldingen, kaarten of schema’s wanneer ze inhoudelijk relevant zijn en controleer hun juistheid.',2]
];
$ruleInsert=$pdo->prepare("INSERT IGNORE INTO ai_test_rules(subject_id,test_type,label,enabled,allow_summary,allow_images,allow_multiple_choice,allow_open,recognition_instructions,generation_instructions,sort_order) SELECT id,?,?,?,?,?,?,?,?,?,? FROM subjects WHERE LOWER(name)=?");
foreach($newSubjectRules as $r){
    $ruleInsert->execute([$r[1],$r[2],$r[3],$r[4],$r[5],$r[6],$r[7],$r[8],$r[9],$r[10],$r[0]]);
}
$rules=$pdo->query("SELECT * FROM ai_test_rules ORDER BY subject_id,sort_order,label")->fetchAll();
$bySubject=[];foreach($rules as $r)$bySubject[(int)$r['subject_id']][]=$r;
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AI-instructies per vak</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body><main class="container py-4">
<div class="d-flex justify-content-between align-items-center mb-4"><div><h1 class="mb-1">AI-instructies per vak</h1><div class="text-secondary">Beheer per vak en type sub-test de AI-herkenning, opties en instructies.</div></div><a class="btn btn-outline-light" href="admin.php">← Beheer</a></div>
<?php if(isset($_GET['saved'])):?><div class="alert alert-success">De AI-instructies zijn opgeslagen.</div><?php endif;?>
<form method="post"><input type="hidden" name="action" value="save">
<?php foreach($subjects as $subject):?><section class="mb-5"><h2 class="h3 mb-3"><?=e($subject['name'])?></h2>
<?php if(empty($bySubject[(int)$subject['id']])):?><div class="text-secondary">Nog geen AI-regels ingesteld voor dit vak.</div><?php else:foreach($bySubject[(int)$subject['id']] as $rule):$id=(int)$rule['id'];?>
<div class="rule-card"><input type="hidden" name="id[]" value="<?=$id?>">
<div class="d-flex justify-content-between align-items-center gap-3 mb-2"><div><div class="rule-type"><?=e($rule['test_type'])?></div><input class="form-control form-control-lg" name="label[<?=$id?>]" value="<?=e($rule['label'])?>"></div><label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled[<?=$id?>]" <?=$rule['enabled']?'checked':''?>> Actief</label></div>
<div class="rule-options"><label><input type="checkbox" name="allow_summary[<?=$id?>]" <?=$rule['allow_summary']?'checked':''?>> Samenvatting</label><label><input type="checkbox" name="allow_images[<?=$id?>]" <?=$rule['allow_images']?'checked':''?>> Afbeeldingen</label><label><input type="checkbox" name="allow_multiple_choice[<?=$id?>]" <?=$rule['allow_multiple_choice']?'checked':''?>> Multiple choice</label><label><input type="checkbox" name="allow_open[<?=$id?>]" <?=$rule['allow_open']?'checked':''?>> Open vragen</label></div>
<div class="rule-grid"><div><label class="form-label fw-semibold">AI-herkenning</label><textarea class="form-control" name="recognition_instructions[<?=$id?>]"><?=e($rule['recognition_instructions']??'')?></textarea><div class="form-text">Wanneer moet de AI dit type pagina herkennen?</div></div><div><label class="form-label fw-semibold">AI-generatie</label><textarea class="form-control" name="generation_instructions[<?=$id?>]"><?=e($rule['generation_instructions']??'')?></textarea><div class="form-text">Welke regels gelden bij het maken van de vragen?</div></div></div>
<div class="text-end mt-3"><button class="btn btn-outline-danger btn-sm" type="submit" name="action" value="delete" formaction="ai_rules.php" onclick="return confirm('Dit AI-type verwijderen?');">Verwijderen</button></div>
</div>
<?php endforeach;endif;?></section><?php endforeach;?>
<button class="btn btn-primary btn-lg" type="submit">Alle AI-instructies opslaan</button></form>
<hr class="my-5"><h2 class="h4">Nieuw type toevoegen</h2>
<form method="post" class="row g-3"><input type="hidden" name="action" value="add"><div class="col-md-4"><label class="form-label">Vak</label><select class="form-select" name="subject_id" required><?php foreach($subjects as $subject):?><option value="<?=$subject['id']?>"><?=e($subject['name'])?></option><?php endforeach;?></select></div><div class="col-md-3"><label class="form-label">Technische naam</label><input class="form-control" name="test_type" placeholder="bijv. grammar" required></div><div class="col-md-5"><label class="form-label">Naam in de website</label><input class="form-control" name="label" placeholder="bijv. Grammatica" required></div><div class="col-12"><button class="btn btn-outline-primary" type="submit">Type toevoegen</button></div></form>
</main></body></html>