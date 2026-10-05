<?php
require __DIR__.'/../app/bootstrap.php';

$statusFile=__DIR__.'/../.update_status.json';
$outputFile=__DIR__.'/../.update_output.log';

function read_update_status(string $file):array{
    if(!is_file($file))return ['state'=>'idle'];
    $raw=@file_get_contents($file);$data=json_decode($raw?:'',true);
    return is_array($data)?$data:['state'=>'idle'];
}

/*
 * The status endpoint must remain available while the Docker container is
 * being recreated. During that short restart the PHP session can disappear,
 * so requiring admin authentication here would cause the browser's polling
 * request to receive a 302 to login.php and the update screen would remain
 * stuck forever.
 *
 * Only non-sensitive status fields are exposed without authentication.
 */
if(($_GET['action']??'')==='status'){
    header('Content-Type: application/json; charset=utf-8');
    $s=read_update_status($statusFile);
    echo json_encode([
        'state'=>$s['state']??'idle',
        'stage'=>$s['stage']??null,
        'message'=>$s['message']??null,
        'requested_at'=>$s['requested_at']??null,
        'finished_at'=>$s['finished_at']??null,
    ],JSON_UNESCAPED_UNICODE);
    exit;
}

require_admin();

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='start'){
    $status=read_update_status($statusFile);
    if(in_array($status['state']??'',['requested','running'],true)){
        header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>'Er draait al een update.']);exit;
    }
    $token=bin2hex(random_bytes(16));
    $written=@file_put_contents($statusFile,json_encode([
        'state'=>'requested','token'=>$token,'requested_at'=>date('c'),
        'message'=>'Update aangevraagd. Wachten op de update-service...'
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),LOCK_EX);
    if($written===false){
        header('Content-Type: application/json');http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Kan update-status niet opslaan.']);exit;
    }
    @chmod($statusFile,0666);
    header('Content-Type: application/json');echo json_encode(['ok'=>true]);exit;
}

$status=read_update_status($statusFile);
?>
<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Website bijwerken</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.step{padding:.8rem 1rem;border-left:4px solid #dee2e6;margin-bottom:.5rem;background:#f8f9fa}.step.active{border-left-color:#0d6efd}.step.done{border-left-color:#198754}.step.error{border-left-color:#dc3545}.log{white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.82rem;max-height:350px;overflow:auto;background:#111;color:#eee;padding:1rem;border-radius:.5rem}</style></head>
<body class="bg-light"><nav class="navbar navbar-dark bg-dark"><div class="container"><a class="navbar-brand" href="admin.php">Leren beheer</a><a class="btn btn-outline-light btn-sm" href="admin.php">Beheer</a></div></nav>
<main class="container py-4" style="max-width:850px">
<a href="admin.php">&larr; Beheer</a>
<div class="card shadow-sm mt-3"><div class="card-body p-4">
<h1 class="h3">Website bijwerken</h1>
<p class="text-secondary">De nieuwste versie wordt uit GitHub gehaald en de Docker-container wordt opnieuw opgebouwd.</p>
<div id="statusBox" class="alert alert-secondary">Status wordt opgehaald...</div>
<div class="mb-3">
<div class="step" id="stepRequest">1. Update aanvragen</div>
<div class="step" id="stepPull">2. GitHub bijwerken</div>
<div class="step" id="stepBuild">3. Docker-container bouwen</div>
<div class="step" id="stepDone">4. Website weer beschikbaar</div>
</div>
<button id="startBtn" class="btn btn-primary btn-lg">Website bijwerken</button>
<a href="admin.php" class="btn btn-outline-secondary btn-lg ms-2">Annuleren</a>
<div id="details" class="mt-4 d-none"><h2 class="h5">Uitvoer</h2><div class="log" id="log"></div></div>
</div></div>
</main>
<script>
const statusBox=document.getElementById('statusBox'),btn=document.getElementById('startBtn'),details=document.getElementById('details'),log=document.getElementById('log');

function render(s){
 const state=s.state||'idle';
 if(state==='idle'){statusBox.className='alert alert-secondary';statusBox.textContent='De website is bijgewerkt. Je kunt een nieuwe update starten.';btn.disabled=false;}
 else if(state==='requested'){statusBox.className='alert alert-info';statusBox.textContent=s.message||'Update aangevraagd. Wachten...';btn.disabled=true;}
 else if(state==='running'){statusBox.className='alert alert-primary';statusBox.textContent=s.message||'Website wordt bijgewerkt...';btn.disabled=true;}
 else if(state==='success'){statusBox.className='alert alert-success';statusBox.textContent=s.message||'Website is succesvol bijgewerkt.';btn.disabled=false;}
 else {statusBox.className='alert alert-danger';statusBox.textContent=s.message||'De update is mislukt.';btn.disabled=false;}

 document.querySelectorAll('.step').forEach(x=>x.className='step');
 if(state==='requested')document.getElementById('stepRequest').classList.add('active');
 if(state==='running'){
   document.getElementById('stepRequest').classList.add('done');
   if(s.stage==='build'){
     document.getElementById('stepPull').classList.add('done');
     document.getElementById('stepBuild').classList.add('active');
   }else{
     document.getElementById('stepPull').classList.add('active');
   }
 }
 if(state==='success')document.querySelectorAll('.step').forEach(x=>x.classList.add('done'));
 if(state==='error'){
   document.getElementById('stepRequest').classList.add('done');
   document.getElementById('stepDone').classList.add('error');
 }
}

async function poll(){
 try{
   const r=await fetch('system_update.php?action=status',{cache:'no-store'});
   if(!r.ok)return;
   render(await r.json());
 }catch(e){}
}

btn.addEventListener('click',async()=>{
 if(!confirm('Website bijwerken? De website kan tijdens het bouwen kort niet beschikbaar zijn.'))return;
 btn.disabled=true;
 try{
   const r=await fetch('system_update.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=start'});
   const data=await r.json();
   if(!data.ok){alert(data.error||'De update kon niet worden aangevraagd.');btn.disabled=false;return;}
   await poll();
 }catch(e){
   alert('De update kon niet worden aangevraagd.');
   btn.disabled=false;
 }
});

render(<?php echo json_encode($status,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>);
setInterval(poll,2000);
</script></body></html>