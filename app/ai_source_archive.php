<?php
/**
 * Persistent provenance for AI photo tests. Paths are stored relative to public/.
 * Never remove original images during ordinary AI session cleanup.
 */
function ai_source_archive_tables(PDO $pdo):void{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_source_collections (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      subject_id INT NOT NULL, topic_id INT NOT NULL,
      title VARCHAR(255) NOT NULL, description TEXT NULL,
      source_type VARCHAR(16) NOT NULL DEFAULT 'ai',
      analysis_json LONGTEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_source_topic(topic_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_source_pages (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      collection_id BIGINT UNSIGNED NOT NULL, page_number INT NOT NULL,
      image_path VARCHAR(512) NOT NULL, extracted_text LONGTEXT NULL,
      UNIQUE KEY uq_source_page(collection_id,page_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_source_regions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      page_id BIGINT UNSIGNED NOT NULL, title VARCHAR(255) NOT NULL,
      description TEXT NULL, region_type VARCHAR(32) NOT NULL DEFAULT 'text',
      x FLOAT NOT NULL DEFAULT 0, y FLOAT NOT NULL DEFAULT 0,
      width FLOAT NOT NULL DEFAULT 1, height FLOAT NOT NULL DEFAULT 1,
      crop_path VARCHAR(512) NULL, extracted_text LONGTEXT NULL,
      KEY idx_region_page(page_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_source_sections (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      collection_id BIGINT UNSIGNED NOT NULL,
      title VARCHAR(255) NOT NULL, description TEXT NULL, summary LONGTEXT NULL,
      source_text LONGTEXT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      KEY idx_section_collection(collection_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Upgrade existing installations without discarding source collections or summaries.
    $columns=$pdo->query("SHOW COLUMNS FROM ai_source_collections")->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('source_type',$columns,true))$pdo->exec("ALTER TABLE ai_source_collections ADD COLUMN source_type VARCHAR(16) NOT NULL DEFAULT 'ai'");
    $columns=$pdo->query("SHOW COLUMNS FROM ai_source_sections")->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('source_text',$columns,true))$pdo->exec("ALTER TABLE ai_source_sections ADD COLUMN source_text LONGTEXT NULL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_source_links (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      collection_id BIGINT UNSIGNED NOT NULL, section_id BIGINT UNSIGNED NULL,
      page_id BIGINT UNSIGNED NULL, region_id BIGINT UNSIGNED NULL,
      question_id INT NULL, summary_id INT NULL,
      link_type VARCHAR(32) NOT NULL DEFAULT 'source',
      KEY idx_link_question(question_id), KEY idx_link_summary(summary_id),
      KEY idx_link_section(section_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function ai_source_archive_create(PDO $pdo,array $saved,int $subjectId,int $topicId,string $title,string $description):array{
    $images=array_values(array_filter((array)($saved['images']??[]),'is_file'));
    if(!$images)return ['collection_id'=>null,'pages'=>[]];
    // Schema is prepared by the caller before beginTransaction(); DDL here would implicitly commit MariaDB transactions.
    $dir=__DIR__.'/../public/uploads/ai_sources/'.bin2hex(random_bytes(12));
    if(!@mkdir($dir,0755,true))throw new RuntimeException('Bronarchiefmap kon niet worden aangemaakt.');
    $paths=[];
    try{
        foreach($images as $i=>$image){
            $mime=(string)(@mime_content_type($image)?:'');
            $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
            if(!$ext)throw new RuntimeException('Ongeldig formaat bronfoto.');
            $dest=$dir.'/page_'.($i+1).'.'.$ext;
            if(!@copy($image,$dest))throw new RuntimeException('Kon originele bronfoto niet bewaren.');
            $paths[]=['absolute'=>$dest,'relative'=>'uploads/ai_sources/'.basename($dir).'/'.basename($dest)];
        }
        $stmt=$pdo->prepare("INSERT INTO ai_source_collections(subject_id,topic_id,title,description,analysis_json) VALUES(?,?,?,?,?)");
        $stmt->execute([$subjectId,$topicId,$title,$description,json_encode($saved['analysis']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $collectionId=(int)$pdo->lastInsertId();
        $pageStmt=$pdo->prepare("INSERT INTO ai_source_pages(collection_id,page_number,image_path) VALUES(?,?,?)");
        $regionStmt=$pdo->prepare("INSERT INTO ai_source_regions(page_id,title,description,region_type) VALUES(?,?,?,'page')");
        $pageIds=[];
        foreach($paths as $i=>$path){
            $pageStmt->execute([$collectionId,$i+1,$path['relative']]);
            $pageId=(int)$pdo->lastInsertId();$pageIds[$i+1]=$pageId;
            // Full-page fallback: until the AI identifies smaller regions, never discard content.
            $regionStmt->execute([$pageId,'Pagina '.($i+1),'Volledige bronpagina']);
        }
        return ['collection_id'=>$collectionId,'pages'=>$pageIds,'files'=>array_column($paths,'absolute')];
    }catch(Throwable $e){
        foreach($paths as $path)@unlink($path['absolute']);
        @rmdir($dir);
        throw $e;
    }
}
function ai_source_archive_link(PDO $pdo,int $collectionId,?int $sectionId,?int $pageId,?int $questionId,?int $summaryId,string $type='source'):void{
    $stmt=$pdo->prepare("INSERT INTO ai_source_links(collection_id,section_id,page_id,question_id,summary_id,link_type) VALUES(?,?,?,?,?,?)");
    $stmt->execute([$collectionId,$sectionId,$pageId,$questionId,$summaryId,$type]);
}

/** Create a manually authored summary in the same archive as AI source material. */
function ai_source_manual_summary_create(PDO $pdo,int $subjectId,int $topicId,string $title,string $summary):int{
    $stmt=$pdo->prepare("INSERT INTO ai_source_collections(subject_id,topic_id,title,description,source_type) VALUES(?,?,?,'Handmatige samenvatting','manual')");
    $stmt->execute([$subjectId,$topicId,$title]);
    $id=(int)$pdo->lastInsertId();
    $stmt=$pdo->prepare("INSERT INTO ai_source_sections(collection_id,title,summary,sort_order) VALUES(?,?,?,1)");
    $stmt->execute([$id,$title,$summary]);
    return $id;
}
/** Render a summary from its ordered source sections; no duplicate full-text field. */
function ai_source_summary_compose(PDO $pdo,int $collectionId):string{
    $stmt=$pdo->prepare("SELECT title,summary FROM ai_source_sections WHERE collection_id=? ORDER BY sort_order,id");
    $stmt->execute([$collectionId]);
    $parts=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $section){
        $body=trim((string)$section['summary']);
        if($body==='')continue;
        $parts[]='## '.trim((string)$section['title'])."\\n\\n".$body;
    }
    return implode("\\n\\n",$parts);
}
