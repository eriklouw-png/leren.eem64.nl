<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
// Only the fully privileged administrator may export diagnostic data.
// Do not accept API keys in query strings or expose this endpoint publicly.
if(!can('admin_full')){http_response_code(403);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>'Geen toegang']);exit;}
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="ai-source-diagnostics.json"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
$topicId=filter_input(INPUT_GET,'topic_id',FILTER_VALIDATE_INT)?:null;
$limit=min(30,max(1,(int)($_GET['limit']??10)));
try{
    $tables=['ai_source_collections','ai_source_pages','ai_source_regions','ai_source_sections','ai_source_links'];
    $exists=$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $present=array_values(array_intersect($tables,$exists));
    $out=['schema_version'=>1,'generated_at'=>gmdate('c'),'topic_filter'=>$topicId,'available_tables'=>$present,'collections'=>[],'checks'=>[]];
    if(!in_array('ai_source_collections',$present,true)){echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);exit;}
    $q="SELECT id,subject_id,topic_id,title,description,created_at FROM ai_source_collections";
    if($topicId)$q.=" WHERE topic_id=:topic";
    $q.=" ORDER BY id DESC LIMIT ".$limit;
    $stmt=$pdo->prepare($q);if($topicId)$stmt->bindValue(':topic',$topicId,PDO::PARAM_INT);$stmt->execute();
    $collections=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $fetch=function(string $table,string $column,array $ids)use($pdo,$present):array{
        if(!$ids||!in_array($table,$present,true))return [];
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$pdo->prepare("SELECT * FROM ".$table." WHERE ".$column." IN (".$placeholders.")");
        $stmt->execute($ids);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $collectionIds=array_map('intval',array_column($collections,'id'));
    $pages=$fetch('ai_source_pages','collection_id',$collectionIds);
    $sections=$fetch('ai_source_sections','collection_id',$collectionIds);
    $links=$fetch('ai_source_links','collection_id',$collectionIds);
    $regions=$fetch('ai_source_regions','page_id',array_map('intval',array_column($pages,'id')));
    foreach($collections as $collection){
        $id=(int)$collection['id'];
        $forId=static fn(array $rows,string $field,int $id):array=>array_values(array_filter($rows,static fn($row)=>(int)$row[$field]===$id));
        $cp=$forId($pages,'collection_id',$id);
        foreach($cp as &$page){
            $page['image_exists']=is_file(__DIR__.'/'.ltrim((string)$page['image_path'],'/'));
            $page['regions']=$forId($regions,'page_id',(int)$page['id']);
            foreach($page['regions'] as &$region){
                $region['crop_exists']=!empty($region['crop_path'])&&is_file(__DIR__.'/'.ltrim((string)$region['crop_path'],'/'));
            }unset($region);
        }unset($page);
        $cs=$forId($sections,'collection_id',$id);
        $cl=$forId($links,'collection_id',$id);
        $out['collections'][]=['collection'=>$collection,'pages'=>$cp,'sections'=>$cs,'links'=>$cl];
        $out['checks'][]=['collection_id'=>$id,'page_count'=>count($cp),'missing_original_images'=>count(array_filter($cp,static fn($p)=>!$p['image_exists'])),'region_count'=>array_sum(array_map(static fn($p)=>count($p['regions']),$cp)),'section_count'=>count($cs),'question_link_count'=>count(array_filter($cl,static fn($l)=>!empty($l['question_id']))),'summary_link_count'=>count(array_filter($cl,static fn($l)=>!empty($l['summary_id'])))];
    }
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
}catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>'Diagnose mislukt. Controleer de serverlogs.']);}
