<?php
declare(strict_types=1);
/**
 * Run only on TrueNAS CLI:
 * php scripts/export_ai_diagnostics.php /absolute/path/to/leren-diagnostics/ai-sources.json
 * The output contains only AI provenance metadata, never users, tokens or full page text.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/bootstrap.php';
$target=$argv[1]??'';
$stdout=($target==='-');
if($target===''||(!$stdout&&!str_starts_with($target,'/'))){fwrite(STDERR,"Usage: php scripts/export_ai_diagnostics.php /absolute/output.json\n");exit(2);}
$tables=['ai_source_collections','ai_source_pages','ai_source_regions','ai_source_sections','ai_source_links'];
$present=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$available=array_values(array_intersect($tables,$present));
$out=['schema_version'=>1,'generated_at'=>gmdate('c'),'tables_present'=>$available,'collections'=>[]];
if(in_array('ai_source_collections',$available,true)){
 $collections=$pdo->query('SELECT id,subject_id,topic_id,title,created_at FROM ai_source_collections ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
 $query=function(string $table,string $sql,int $id)use($pdo,$available):array{
  if(!in_array($table,$available,true))return [];
  $stmt=$pdo->prepare($sql);$stmt->execute([$id]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
 };
 foreach($collections as $c){
  $id=(int)$c['id'];
  $pages=$query('ai_source_pages','SELECT id,page_number,image_path FROM ai_source_pages WHERE collection_id=? ORDER BY page_number',$id);
  foreach($pages as &$page){
   $page['image_exists']=is_file(__DIR__.'/../public/'.ltrim((string)$page['image_path'],'/'));
   $page['regions']=$query('ai_source_regions','SELECT id,title,region_type,x,y,width,height,crop_path FROM ai_source_regions WHERE page_id=? ORDER BY id',(int)$page['id']);
   foreach($page['regions'] as &$region)$region['crop_exists']=!empty($region['crop_path'])&&is_file(__DIR__.'/../public/'.ltrim((string)$region['crop_path'],'/'));
   unset($region);
  }unset($page);
  $out['collections'][]=[
   'collection'=>$c,'pages'=>$pages,
   'sections'=>$query('ai_source_sections','SELECT id,title,sort_order,CHAR_LENGTH(summary) AS summary_length FROM ai_source_sections WHERE collection_id=? ORDER BY sort_order,id',$id),
   'links'=>$query('ai_source_links','SELECT id,section_id,page_id,region_id,question_id,summary_id,link_type FROM ai_source_links WHERE collection_id=? ORDER BY id',$id)
  ];
 }
}
$json=json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n";
if($stdout){fwrite(STDOUT,$json);exit(0);}
$dir=dirname($target);
if(!is_dir($dir)){fwrite(STDERR,"Output directory does not exist\n");exit(2);}
$tmp=tempnam($dir,'.ai-diag-');
if($tmp===false)throw new RuntimeException('Unable to create temporary file');
chmod($tmp,0600);
try{
 if(file_put_contents($tmp,$json)===false)throw new RuntimeException('Write failed');
 if(!rename($tmp,$target))throw new RuntimeException('Rename failed');
 chmod($target,0600);
}catch(Throwable $e){@unlink($tmp);throw $e;}
fwrite(STDOUT,"Exported ".count($out['collections'])." collections.\n");
