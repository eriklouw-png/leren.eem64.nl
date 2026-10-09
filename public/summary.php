<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';
require_login();

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id)redirect('index.php');

$isArchive=($_GET['source']??'')==='archive';
if($isArchive){
    require_once __DIR__.'/../app/ai_source_archive.php';
    ai_source_archive_tables($pdo);
    $summary=ai_source_summary_get($pdo,$id);
}else{
$x=$pdo->prepare("
    SELECT ts.id,ts.name,ts.summary,ts.updated_at,ts.created_at,
           tp.id topic_id,tp.name topic_name,
           s.id subject_id,s.name subject_name
    FROM topic_summaries ts
    JOIN topics tp ON tp.id=ts.topic_id
    JOIN subjects s ON s.id=tp.subject_id
    WHERE ts.id=? AND ts.is_active=1 AND tp.is_active=1
");
$x->execute([$id]);
$summary=$x->fetch();

if(!$summary){http_response_code(404);exit('Samenvatting niet gevonden.');}

}
if(!$summary){http_response_code(404);exit('Samenvatting niet gevonden.');}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($summary['name'])?> - Leren</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-4" style="max-width:900px">
<a href="topic.php?id=<?=(int)$summary['topic_id']?>">&larr; Terug naar <?=e($summary['topic_name'])?></a>

<div class="card shadow-sm mt-3">
<div class="card-body p-4 p-md-5">
<div class="small text-secondary mb-2"><?=e($summary['subject_name'])?> · <?=e($summary['topic_name'])?></div>
<h1 class="h2 mb-1"><?=e($summary['name'])?></h1>
<?php if(!empty($summary['updated_at']) || !empty($summary['created_at'])):?>
<div class="small text-secondary mb-4"><?=!empty($summary['updated_at'])?'Bijgewerkt':'Aangemaakt'?> <?=e(date('d-m-Y',strtotime((string)(($summary['updated_at']??null)?:$summary['created_at']))))?></div>
<?php endif;?>
<?php
if($isArchive){
    $sections=ai_source_summary_sections($pdo,$id);
}else{
$rawSummary=(string)$summary['summary'];
$lines=preg_split("/\\r\\n|\\r|\\n/",$rawSummary);
$sections=[];
$currentTitle=null;
$currentLines=[];
foreach($lines as $line){
    if(preg_match('/^##\\s+(.+)$/',trim($line),$m)){
        if($currentTitle!==null || $currentLines){
            $sections[]=['title'=>$currentTitle,'content'=>trim(implode("\n",$currentLines))];
        }
        $currentTitle=trim($m[1]);
        $currentLines=[];
    }else{
        $currentLines[]=$line;
    }
}
if($currentTitle!==null || $currentLines){
    $sections[]=['title'=>$currentTitle,'content'=>trim(implode("\n",$currentLines))];
}
if(!$sections)$sections=[['title'=>null,'content'=>trim($rawSummary)]];
}
?>
<div id="summarySections">
<?php foreach($sections as $i=>$section):?>
<section class="summary-section <?=$i===0?'':'d-none'?>" data-index="<?=$i?>">
<?php if($section['title']!==null):?>
<h2 class="h3 mb-4"><?=e($section['title'])?></h2>
<?php endif;?>
<div class="lh-lg"><?php
$parts=preg_split('/(\\[\\[BRONPAGINA:\\d+\\]\\])/u',(string)$section['content'],-1,PREG_SPLIT_DELIM_CAPTURE);
foreach($parts as $part){
    if(preg_match('/^\\[\\[BRONPAGINA:(\\d+)\\]\\]$/',$part,$match)){
        $page=(int)$match[1];
        if($page>=1 && $page<=10){
            echo '<figure class="my-3"><img class="img-fluid rounded border" loading="lazy" src="summary_source_image.php?source='.($isArchive?'archive':'legacy').'&amp;id='.(int)$summary['id'].'&page='.$page.'" alt="Originele boekpagina '.$page.'"><figcaption class="small text-secondary">Bronpagina '.$page.'</figcaption></figure>';
        }
    }else{
        echo nl2br(e($part));
    }
}
?></div>
<?php if($isArchive && !empty($section['id'])):
    $regions=ai_source_section_regions($pdo,$id,(int)$section['id']);
    if($regions):?>
<div class="mt-4"><h3 class="h5">Afbeeldingen en tekst uit de bron</h3>
<div class="row g-3"><?php foreach($regions as $region):?>
<div class="col-md-6"><figure class="border rounded p-2 h-100">
<img loading="lazy" class="img-fluid rounded" src="source_region_image.php?collection=<?=$id?>&amp;id=<?=(int)$region['id']?>" alt="<?=e($region['title'])?>">
<figcaption class="small text-secondary mt-2"><?=e($region['title'])?> · pagina <?=(int)$region['page_number']?></figcaption>
</figure></div><?php endforeach;?></div></div>
<?php endif;endif;?>
<?php if($isArchive && !empty($section['id'])):
    $sourcePages=ai_source_section_pages($pdo,$id,(int)$section['id']);
    if($sourcePages):?>
<details class="mt-4 border-top pt-3">
<summary class="text-secondary" style="cursor:pointer">Originele bronpagina<?=count($sourcePages)>1?'’s':''?> (<?=count($sourcePages)?>)</summary>
<div class="mt-3">
<?php foreach($sourcePages as $sourcePage):?>
<figure class="mb-3">
<img loading="lazy" class="img-fluid rounded border" src="summary_source_image.php?source=archive&amp;id=<?=$id?>&amp;page=<?=(int)$sourcePage['page_number']?>" alt="Boekpagina <?=(int)$sourcePage['page_number']?>">
<figcaption class="small text-secondary">Pagina <?=(int)$sourcePage['page_number']?></figcaption>
</figure>
<?php endforeach;?>
</div>
</details>
<?php endif;endif;?>
</section>
<?php endforeach;?>
</div>

<?php if(count($sections)>1):?>
<div class="d-flex justify-content-between align-items-center mt-5 pt-3 border-top gap-3">
<a class="btn btn-outline-secondary" href="topic.php?id=<?=(int)$summary['topic_id']?>">Terug</a>
<div class="small text-secondary text-center" id="sectionCounter">Kopje 1 van <?=count($sections)?></div>
<button type="button" class="btn btn-primary" id="nextSection">Volgende</button>
</div>
<?php else:?>
<div class="mt-5 pt-3 border-top">
<a class="btn btn-outline-secondary" href="topic.php?id=<?=(int)$summary['topic_id']?>">Terug</a>
</div>
<?php endif;?>
</div>
</div>
</main>
<script>
(function(){
 const summaryId='<?= (int)$summary['id'] ?>';
 function activity(data){
   fetch('activity.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data),keepalive:true}).catch(()=>{});
 }
 if(!<?= $isArchive?'true':'false' ?>)activity({action:'start',summary_id:summaryId});

 const sections=[...document.querySelectorAll('.summary-section')];
 const nextButton=document.getElementById('nextSection');
 const counter=document.getElementById('sectionCounter');
 let current=0;
 function showSection(index){
   current=index;
   sections.forEach((section,i)=>section.classList.toggle('d-none',i!==current));
   if(counter)counter.textContent='Kopje '+(current+1)+' van '+sections.length;
   if(nextButton){
     nextButton.textContent=current===sections.length-1?'Klaar':'Volgende';
     if(current===sections.length-1){
       nextButton.classList.remove('btn-primary');
       nextButton.classList.add('btn-success');
     }else{
       nextButton.classList.remove('btn-success');
       nextButton.classList.add('btn-primary');
     }
   }
   window.scrollTo({top:0,behavior:'smooth'});
 }
 if(nextButton){
   nextButton.addEventListener('click',()=>{
     if(current<sections.length-1)showSection(current+1);
     else window.location.href='topic.php?id=<?= (int)$summary['topic_id'] ?>';
   });
   showSection(0);
 }
 let activeUntil=Date.now()+60000;
 const touch=()=>{activeUntil=Date.now()+60000;};
 ['mousemove','mousedown','keydown','touchstart','scroll'].forEach(e=>window.addEventListener(e,touch,{passive:true}));
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')touch();});
 if(!<?= $isArchive?'true':'false' ?>)setInterval(()=>activity({action:'heartbeat',active:(document.visibilityState==='visible'&&Date.now()<activeUntil)?'1':'0'}),15000);
 if(!<?= $isArchive?'true':'false' ?>)window.addEventListener('beforeunload',()=>activity({action:'end'}));
})();
</script>
</body>
</html>
