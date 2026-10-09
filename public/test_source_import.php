<?php
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/ai_source_archive.php';
require_manager();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT);
$q=$pdo->prepare("SELECT t.id,t.title,t.topic_id,tp.subject_id FROM tests t JOIN topics tp ON tp.id=t.topic_id WHERE t.id=? AND t.is_active=1");
$q->execute([$id]);$test=$q->fetch(PDO::FETCH_ASSOC);
if(!$test){http_response_code(404);exit('Sub-test niet gevonden');}
$q=$pdo->prepare("SELECT id,question_text FROM questions WHERE test_id=? ORDER BY sort_order,id");
$q->execute([$id]);$questions=$q->fetchAll(PDO::FETCH_ASSOC);
$sessionKey='source_import_'.$id;$stage=$_SESSION[$sessionKey]??null;$error='';$notice='';
$_SESSION['source_import_csrf']??=bin2hex(random_bytes(24));
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($_SESSION['source_import_csrf'],(string)($_POST['token']??''))){http_response_code(403);exit;}
 $action=$_POST['action']??'';
 if($action==='upload'){
  $files=$_FILES['photos']??null;$paths=[];
  try{
   if(!$files||!is_array($files['name'])||count($files['name'])>10)throw new RuntimeException('Selecteer maximaal 10 foto’s');
   $dir=sys_get_temp_dir().'/leren_source_import';if(!is_dir($dir))mkdir($dir,0700,true);
   foreach($files['name'] as $i=>$name){
    $tmp=$files['tmp_name'][$i]??'';
    if(($files['error'][$i]??1)!==UPLOAD_ERR_OK||($files['size'][$i]??0)>12582912||!in_array(@mime_content_type($tmp),['image/jpeg','image/png','image/webp'],true)||!@getimagesize($tmp))throw new RuntimeException('Ongeldige foto (JPG, PNG, WEBP; maximaal 12 MB)');
    $dest=$dir.'/'.bin2hex(random_bytes(16));if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('Opslaan mislukt');$paths[]=$dest;
   }
   if(!$paths)throw new RuntimeException('Geen foto’s geselecteerd');
   if(is_array($stage))foreach($stage['paths'] as $old)@unlink($old);
   $stage=['paths'=>$paths,'created'=>time()];$_SESSION[$sessionKey]=$stage;
  }catch(Throwable $e){foreach($paths as $path)@unlink($path);$error=$e->getMessage();}
 }
 if($action==='cancel'){if(is_array($stage))foreach($stage['paths'] as $path)@unlink($path);unset($_SESSION[$sessionKey]);redirect('test_source_import.php?id='.$id);}
 if($action==='confirm'&&is_array($stage)){
  try{
   if(time()-$stage['created']>3600)throw new RuntimeException('Upload verlopen');
   foreach($stage['paths'] as $path)if(!is_file($path))throw new RuntimeException('Foto ontbreekt');
   $allowed=array_fill_keys(array_column($questions,'id'),true);$mapping=[];
   foreach((array)($_POST['page']??[]) as $qid=>$page){
    $qid=(int)$qid;$page=(int)$page;
    if(!isset($allowed[$qid])||$page<0||$page>count($stage['paths']))throw new RuntimeException('Ongeldige koppeling');
    if($page)$mapping[$qid]=$page;
   }
   ai_source_archive_tables($pdo);$pdo->beginTransaction();
   try{
    $archive=ai_source_archive_create($pdo,['images'=>$stage['paths']],(int)$test['subject_id'],(int)$test['topic_id'],'Bronfoto’s: '.$test['title'],'Achteraf toegevoegd');
    foreach($mapping as $qid=>$page)ai_source_archive_link($pdo,(int)$archive['collection_id'],null,(int)$archive['pages'][$page],$qid,null,'question');
    $pdo->commit();
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
   foreach($stage['paths'] as $path)@unlink($path);unset($_SESSION[$sessionKey]);$stage=null;
   $notice='Bronfoto’s en '.count($mapping).' koppelingen opgeslagen. Vragen en antwoorden zijn ongewijzigd.';
  }catch(Throwable $e){$error=$e->getMessage();}
 }
}
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bronfoto’s toevoegen</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4" style="max-width:900px">
<a href="test_edit.php?id=<?=$id?>">&larr; Sub-test bewerken</a><h1 class="h3 mt-3">Bronfoto’s toevoegen <span class="badge text-bg-secondary">Tijdelijk</span></h1>
<p><?=e($test['title'])?></p><p class="alert alert-info">Alle bestaande vragen, antwoorden, afbeeldingen en scores blijven ongewijzigd.</p>
<?php if($error):?><p class="alert alert-danger"><?=e($error)?></p><?php endif;?>
<?php if($notice):?><p class="alert alert-success"><?=e($notice)?></p><?php endif;?>
<?php if(!$stage):?>
<form method="post" enctype="multipart/form-data" class="card card-body"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="token" value="<?=e($_SESSION['source_import_csrf'])?>">
<label class="form-label">Boekfoto’s (maximaal 10)</label><input class="form-control mb-3" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
<button class="btn btn-primary" name="action" value="upload">Koppelingen voorbereiden</button></form>
<?php else:?>
<form method="post" class="card card-body"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="token" value="<?=e($_SESSION['source_import_csrf'])?>">
<h2 class="h5">Controleer de koppelingen</h2><p>Er zijn <?=count($stage['paths'])?> foto’s geselecteerd. Kies de bronpagina per vraag.</p>
<?php foreach($questions as $question):?><div class="border-bottom py-2"><label class="form-label"><?=e($question['question_text'])?></label><select class="form-select" name="page[<?=(int)$question['id']?>]"><option value="0">Geen koppeling</option>
<?php foreach($stage['paths'] as $i=>$path):?><option value="<?=$i+1?>">Foto <?=$i+1?></option><?php endforeach;?></select></div><?php endforeach;?>
<button class="btn btn-primary mt-3" name="action" value="confirm">Definitief opslaan</button></form>
<form method="post" class="mt-2"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="token" value="<?=e($_SESSION['source_import_csrf'])?>"><button class="btn btn-outline-secondary" name="action" value="cancel">Annuleren</button></form>
<?php endif;?></main></body></html>