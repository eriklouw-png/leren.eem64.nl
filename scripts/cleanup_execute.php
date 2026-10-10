<?php
declare(strict_types=1);
/**
 * Opschoning van inactieve leerinhoud.
 * Standaard DRY-RUN. Uitvoeren uitsluitend met --execute --confirm=DELETE-INACTIVE.
 * De originele AI-broncollecties en bronfoto's worden niet verwijderd.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Alleen CLI.\n"); }
$args=array_slice($argv,1);
$execute=$args===['--execute','--confirm=DELETE-INACTIVE'];
if ($args!==[] && $args!==['--dry-run'] && !$execute) {
    fwrite(STDERR,"Gebruik: php scripts/cleanup_execute.php [--dry-run] OF --execute --confirm=DELETE-INACTIVE\n");
    exit(2);
}
$root=dirname(__DIR__);
$config=require $root.'/config/config.php';
$db=$config['db'];
$pdo=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $db['host'],$db['port'],$db['name'],$db['charset']??'utf8mb4'),
    $db['user'],$db['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false
    ]);
$pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
$expected=['topics'=>3,'tests'=>86,'questions'=>1328,'attempts'=>21,'summaries'=>5,'ai_question_links'=>154,'ai_summary_links'=>2,'images'=>108];
$backupDir='/mnt/POOL/BACKUPS/leren';
$backupCheck=[
    $backupDir.'/leren_20261011_000117.sql',
    $backupDir.'/uploads_20261011_000009.tar.gz'
];
$base=$root.'/public/uploads/questions';
$quarantine=$root.'/public/uploads/.cleanup-quarantine-'.date('Ymd_His');
function cnt(PDO $pdo,string $sql):int {return (int)$pdo->query($sql)->fetchColumn();}
function logline(string $message):void {echo $message,"\n";}
$pdo->beginTransaction();
$moved=[];
try {
    // InnoDB snapshot prevents an inconsistent inventory within the transaction.
    $where='t.is_active=0 OR tp.is_active=0';
    $stats=[
        'topics'=>cnt($pdo,'SELECT COUNT(*) FROM topics WHERE is_active=0'),
        'tests'=>cnt($pdo,"SELECT COUNT(*) FROM tests t LEFT JOIN topics tp ON tp.id=t.topic_id WHERE $where"),
        'questions'=>cnt($pdo,"SELECT COUNT(*) FROM questions q JOIN tests t ON t.id=q.test_id LEFT JOIN topics tp ON tp.id=t.topic_id WHERE $where"),
        'attempts'=>cnt($pdo,"SELECT COUNT(*) FROM attempts a JOIN tests t ON t.id=a.test_id LEFT JOIN topics tp ON tp.id=t.topic_id WHERE $where"),
        'summaries'=>cnt($pdo,'SELECT COUNT(*) FROM topic_summaries WHERE is_active=0'),
        'ai_question_links'=>cnt($pdo,"SELECT COUNT(*) FROM ai_source_links l JOIN questions q ON q.id=l.question_id JOIN tests t ON t.id=q.test_id LEFT JOIN topics tp ON tp.id=t.topic_id WHERE $where"),
        'ai_summary_links'=>cnt($pdo,'SELECT COUNT(*) FROM ai_source_links l JOIN topic_summaries s ON s.id=l.summary_id WHERE s.is_active=0')
    ];
    $images=$pdo->query("SELECT DISTINCT q.image_path FROM questions q JOIN tests t ON t.id=q.test_id LEFT JOIN topics tp ON tp.id=t.topic_id WHERE ($where) AND q.image_path IS NOT NULL AND q.image_path<>'' ORDER BY q.image_path")->fetchAll(PDO::FETCH_COLUMN);
    $stats['images']=count($images);
    foreach($stats as $name=>$n) {
        logline("$name: $n (verwacht {$expected[$name]})");
        if($n!==$expected[$name]) throw new RuntimeException("Aantallen gewijzigd: $name. Stop.");
    }
    foreach([
        'SELECT COUNT(*) FROM ai_source_links l LEFT JOIN questions q ON q.id=l.question_id WHERE l.question_id IS NOT NULL AND q.id IS NULL',
        'SELECT COUNT(*) FROM ai_source_links l LEFT JOIN topic_summaries s ON s.id=l.summary_id WHERE l.summary_id IS NOT NULL AND s.id IS NULL',
        'SELECT COUNT(*) FROM tests t JOIN topics tp ON tp.id=t.topic_id WHERE tp.is_active=0',
        'SELECT COUNT(*) FROM topic_summaries s JOIN topics tp ON tp.id=s.topic_id WHERE tp.is_active=0',
        'SELECT COUNT(*) FROM topic_grades g JOIN topics tp ON tp.id=g.topic_id WHERE tp.is_active=0'
    ] as $check) if(cnt($pdo,$check)!==0) throw new RuntimeException('Onverwachte referenties, stop.');
    $shared=$pdo->prepare('SELECT COUNT(*) FROM questions WHERE image_path=? AND id NOT IN (SELECT q.id FROM questions q JOIN tests t ON t.id=q.test_id LEFT JOIN topics tp ON tp.id=t.topic_id WHERE t.is_active=0 OR tp.is_active=0)');
    foreach($images as $image) {
        if(!is_string($image)||basename($image)!==$image||$image==='.'||$image==='..')
            throw new RuntimeException('Onveilig afbeeldingspad.');
        $shared->execute([$image]);
        if((int)$shared->fetchColumn()>0) throw new RuntimeException("Afbeelding wordt elders gebruikt: $image");
    }
    if(!$execute) {
        logline('DRY-RUN: geen database- of bestandswijzigingen.');
        $pdo->rollBack();
        exit(0);
    }
    // The CLI container must have the host backup volume mounted; if not, refuse execution.
    foreach($backupCheck as $file) if(!is_file($file)||filesize($file)<1024)
        throw new RuntimeException("Back-up niet toegankelijk in container: $file");
    if(!is_dir($base)) throw new RuntimeException("Afbeeldingsmap ontbreekt: $base");
    foreach($images as $image) if(!is_file($base.'/'.$image))
        throw new RuntimeException("Afbeeldingsbestand ontbreekt: $image");
    if(!mkdir($quarantine,0700,true)) throw new RuntimeException('Quarantainemap aanmaken mislukt.');
    foreach($images as $image) {
        if(!rename($base.'/'.$image,$quarantine.'/'.$image))
            throw new RuntimeException("Verplaatsen mislukt: $image");
        $moved[]=$image;
    }
    $pdo->exec("DELETE l FROM ai_source_links l JOIN questions q ON q.id=l.question_id JOIN tests t ON t.id=q.test_id LEFT JOIN topics tp ON tp.id=t.topic_id WHERE $where");
    $pdo->exec('DELETE l FROM ai_source_links l JOIN topic_summaries s ON s.id=l.summary_id WHERE s.is_active=0');
    $pdo->exec("DELETE t FROM tests t LEFT JOIN topics tp ON tp.id=t.topic_id WHERE $where");
    $pdo->exec('DELETE FROM topic_summaries WHERE is_active=0');
    $pdo->exec('DELETE FROM topics WHERE is_active=0');
    $pdo->commit();
    logline('SUCCES: database opgeschoond. Afbeeldingen in quarantaine: '.$quarantine);
} catch(Throwable $e) {
    if($pdo->inTransaction()) $pdo->rollBack();
    foreach(array_reverse($moved) as $image) {
        if(!rename($quarantine.'/'.$image,$base.'/'.$image))
            fwrite(STDERR,"KRITIEK: handmatig herstellen: $quarantine/$image\n");
    }
    fwrite(STDERR,'GEANNULEERD: '.$e->getMessage()."\n");
    exit(1);
}
