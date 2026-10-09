<?php
require_once __DIR__.'/ai_source_vision.php';

/** Replace full textbook photos on spatially explicit questions with detected crops. */
function ai_source_assign_question_crops(PDO $pdo,int $collectionId):array{
    $sql="SELECT q.id,q.question_text,q.image_path,p.image_path AS source_path,p.page_number,r.id AS region_id,r.x,r.y,r.width,r.height,r.rotation_degrees
    FROM ai_source_links l JOIN questions q ON q.id=l.question_id
    JOIN ai_source_pages p ON p.id=l.page_id
    JOIN ai_source_regions r ON r.page_id=p.id AND r.region_type IN ('image','diagram','table')
    WHERE l.collection_id=? AND l.link_type='question' AND l.question_id IS NOT NULL
    ORDER BY q.id,r.x";
    $stmt=$pdo->prepare($sql);$stmt->execute([$collectionId]);
    $groups=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)$groups[(int)$row['id']][]=$row;
    $count=0;
    $dir=__DIR__.'/../public/uploads/questions';
    $base=realpath(__DIR__.'/../public/uploads/ai_sources');
    if(!extension_loaded('gd')||!$base)return ['updated'=>0];
    foreach($groups as $questionId=>$rows){
        $question=(string)$rows[0]['question_text'];
        $left=preg_match('/\\b(links|linker|left)\\b/iu',$question);
        $right=preg_match('/\\b(rechts|rechter|right)\\b/iu',$question);
        if((bool)$left===(bool)$right)continue;
        if(count($rows)<2)continue; // Do not infer left/right from a single region.
        usort($rows,static fn($a,$b)=>(float)$a['x']<=>(float)$b['x']);
        $row=$left?$rows[0]:$rows[count($rows)-1];
        $relative=(string)$row['source_path'];
        $path=realpath(__DIR__.'/../public/'.$relative);
        if(!str_starts_with($relative,'uploads/ai_sources/')||!$path||!str_starts_with($path,$base.DIRECTORY_SEPARATOR))continue;
        $src=ai_source_oriented_image($path);
        if(!$src)continue;
        $iw=imagesx($src);$ih=imagesy($src);
        $x=max(0,(int)floor((float)$row['x']*$iw));$y=max(0,(int)floor((float)$row['y']*$ih));
        $w=min($iw-$x,max(1,(int)ceil((float)$row['width']*$iw)));
        $h=min($ih-$y,max(1,(int)ceil((float)$row['height']*$ih)));
        $crop=imagecrop($src,['x'=>$x,'y'=>$y,'width'=>$w,'height'=>$h]);imagedestroy($src);
        if(!$crop)continue;
        $rotation=(int)$row['rotation_degrees'];
        if($rotation===0 && imagesy($crop)>imagesx($crop)*1.3)$rotation=270;
        if($rotation!==0){
            $rotated=imagerotate($crop,$rotation,0);
            if($rotated!==false){imagedestroy($crop);$crop=$rotated;}
        }
        if(!is_dir($dir)&&!@mkdir($dir,0755,true)){imagedestroy($crop);continue;}
        $filename='source_crop_'.bin2hex(random_bytes(12)).'.jpg';
        $success=@imagejpeg($crop,$dir.'/'.$filename,88);imagedestroy($crop);
        if(!$success)continue;
        $update=$pdo->prepare("UPDATE questions SET image_path=? WHERE id=? AND image_path=?");
        $update->execute([$filename,$questionId,$rows[0]['image_path']]);
        if($update->rowCount()===1){
            $count++;
            // Do not delete source archives; remove only the replaced question-specific copy.
            $old=(string)$rows[0]['image_path'];
            if(str_starts_with($old,'source_') && preg_match('/^source_[a-f0-9]{24}\\.(jpg|png|webp)$/',$old))@unlink($dir.'/'.$old);
        }else @unlink($dir.'/'.$filename);
    }
    return ['updated'=>$count];
}
