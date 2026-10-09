<?php
require_once __DIR__.'/ai_source_archive.php';
require_once __DIR__.'/ai_source_vision.php';

/** Add detected illustrations to an existing archive, preserving manually edited regions. */
function ai_source_auto_detect(PDO $pdo,int $collectionId):array{
    $q=$pdo->prepare("SELECT id,page_number,image_path FROM ai_source_pages WHERE collection_id=? ORDER BY page_number");
    $q->execute([$collectionId]);$pages=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare("SELECT id,title FROM ai_source_sections WHERE collection_id=? ORDER BY sort_order,id");
    $q->execute([$collectionId]);$sections=$q->fetchAll(PDO::FETCH_ASSOC);
    $result=['added'=>0,'errors'=>[]];
    if(!$pages||!$sections)return $result;
    $base=realpath(__DIR__.'/../public/uploads/ai_sources');
    foreach($pages as $page){
        $relative=(string)$page['image_path'];
        $path=realpath(__DIR__.'/../public/'.$relative);
        if(!$base||!$path||!str_starts_with($path,$base.DIRECTORY_SEPARATOR)){
            $result['errors'][]='Bronpagina '.(int)$page['page_number'].' ontbreekt.';
            continue;
        }
        $analysis=ai_source_vision_analyze($path,array_column($sections,'title'));
        if(!empty($analysis['error'])){
            $result['errors'][]='Pagina '.(int)$page['page_number'].': '.$analysis['error'];
            continue;
        }
        foreach((array)($analysis['regions']??[]) as $region){
            if(!is_array($region)||!in_array($region['type']??'', ['image','diagram','table'],true))continue;
            $coords=[];
            foreach(['x','y','width','height'] as $k)$coords[$k]=filter_var($region[$k]??null,FILTER_VALIDATE_FLOAT);
            if(in_array(false,$coords,true))continue;
            [$x,$y,$w,$h]=array_values($coords);
            if($x<0||$y<0||$w<0.12||$h<0.12||$x+$w>1||$y+$h>1)continue;
            $links=[];
            foreach((array)($region['section_indices']??[]) as $index){
                if(is_int($index)&&isset($sections[$index]))$links[]=(int)$sections[$index]['id'];
            }
            $links=array_unique($links);
            if(!$links)continue;
            $existing=$pdo->prepare("SELECT id FROM ai_source_regions WHERE page_id=? AND region_type=? AND ABS(x-?)<0.06 AND ABS(y-?)<0.06 AND ABS(width-?)<0.06 AND ABS(height-?)<0.06 LIMIT 1");
            $existing->execute([(int)$page['id'],$region['type'],$x,$y,$w,$h]);
            if($existing->fetchColumn())continue;
            $pdo->beginTransaction();
            try{
                $regionId=ai_source_region_add($pdo,(int)$page['id'],mb_substr(trim((string)($region['title']??'Afbeelding')),0,255),$region['type'],$x,$y,$w,$h);
                foreach($links as $sectionId)ai_source_region_link_section($pdo,$collectionId,$sectionId,$regionId);
                $pdo->commit();$result['added']++;
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                $result['errors'][]='Opslaan brongebied mislukt.';
            }
        }
    }
    return $result;
}
