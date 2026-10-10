<?php
declare(strict_types=1);

/**
 * Inventarisatie van inactieve leerinhoud.
 * ALLEEN LEZEN: dit script voert geen UPDATE, DELETE of bestandsmutaties uit.
 *
 * Uitvoeren vanuit projectroot:
 *   php scripts/cleanup_inactive_content.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Alleen via CLI.\n");
}
if (array_diff(array_slice($argv, 1), ['--dry-run'])) {
    fwrite(STDERR, "Alleen --dry-run wordt ondersteund. Er bestaat nog geen verwijdermodus.\n");
    exit(2);
}
$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "config/config.php ontbreekt.\n");
    exit(1);
}
$config = require $configFile;
$db = $config['db'];
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $db['host'], $db['port'], $db['name'], $db['charset'] ?? 'utf8mb4'
);
$pdo = new PDO($dsn, $db['user'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
function report(PDO $pdo, string $heading, string $sql): void {
    echo "\n=== {$heading} ===\n";
    $rows = $pdo->query($sql)->fetchAll();
    if (!$rows) {
        echo "(geen resultaten)\n";
        return;
    }
    foreach ($rows as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    }
}
echo "DRY-RUN / ALLEEN LEZEN — ", date(DATE_ATOM), "\n";
echo "Criteria: inactieve topics, inactieve tests, inactieve samenvattingen.\n";
report($pdo, 'Aantallen', "
    SELECT 'inactieve_overhoringen' onderdeel, COUNT(*) aantal FROM topics WHERE is_active=0
    UNION ALL SELECT 'inactieve_tests', COUNT(*) FROM tests WHERE is_active=0
    UNION ALL SELECT 'tests_voor_opruiming', COUNT(*) FROM tests t
        LEFT JOIN topics tp ON tp.id=t.topic_id
        WHERE t.is_active=0 OR tp.is_active=0
    UNION ALL SELECT 'vragen_voor_opruiming', COUNT(*) FROM questions q
        JOIN tests t ON t.id=q.test_id LEFT JOIN topics tp ON tp.id=t.topic_id
        WHERE t.is_active=0 OR tp.is_active=0
    UNION ALL SELECT 'resultaten_voor_opruiming', COUNT(*) FROM attempts a
        JOIN tests t ON t.id=a.test_id LEFT JOIN topics tp ON tp.id=t.topic_id
        WHERE t.is_active=0 OR tp.is_active=0
    UNION ALL SELECT 'inactieve_samenvattingen', COUNT(*) FROM topic_summaries WHERE is_active=0
");
report($pdo, 'Inactieve overhoringen', "
    SELECT tp.id, tp.name, tp.subject_id,
        (SELECT COUNT(*) FROM tests t WHERE t.topic_id=tp.id) AS aantal_tests
    FROM topics tp WHERE tp.is_active=0 ORDER BY tp.id
");
report($pdo, 'Te verwijderen tests (ook onder inactieve overhoringen)', "
    SELECT t.id, t.title, t.topic_id, t.is_active, tp.name AS overhoring,
        tp.is_active AS overhoring_actief,
        (SELECT COUNT(*) FROM questions q WHERE q.test_id=t.id) AS vragen
    FROM tests t LEFT JOIN topics tp ON tp.id=t.topic_id
    WHERE t.is_active=0 OR tp.is_active=0 ORDER BY t.topic_id,t.id
");
report($pdo, 'Inactieve samenvattingen', "
    SELECT s.id, s.name, s.topic_id, s.is_active, s.deleted_at
    FROM topic_summaries s WHERE s.is_active=0 ORDER BY s.id
");
report($pdo, 'AI-collecties: beoordeling, GEEN automatische verwijderbeslissing', "
    SELECT c.id, c.title, c.subject_id, c.topic_id, tp.is_active AS overhoring_actief,
        (SELECT COUNT(DISTINCT l.question_id)
           FROM ai_source_links l JOIN questions q ON q.id=l.question_id
           JOIN tests t ON t.id=q.test_id
           JOIN topics tt ON tt.id=t.topic_id
           WHERE l.collection_id=c.id AND t.is_active=1 AND tt.is_active=1) AS actieve_vragen,
        (SELECT COUNT(DISTINCT l.question_id)
           FROM ai_source_links l JOIN questions q ON q.id=l.question_id
           JOIN tests t ON t.id=q.test_id LEFT JOIN topics tt ON tt.id=t.topic_id
           WHERE l.collection_id=c.id AND (t.is_active=0 OR tt.is_active=0)) AS inactieve_vragen,
        (SELECT COUNT(DISTINCT l.summary_id)
           FROM ai_source_links l JOIN topic_summaries s ON s.id=l.summary_id
           JOIN topics tt ON tt.id=s.topic_id
           WHERE l.collection_id=c.id AND s.is_active=1 AND tt.is_active=1) AS actieve_samenvattingen,
        (SELECT COUNT(*) FROM ai_source_pages p WHERE p.collection_id=c.id) AS bronpaginas
    FROM ai_source_collections c
    LEFT JOIN topics tp ON tp.id=c.topic_id ORDER BY c.id
");
report($pdo, 'Afbeeldingsverwijzingen bij te verwijderen vragen (geen bestanden verwijderd)', "
    SELECT q.id AS vraag_id, q.test_id, q.image_path, q.image
    FROM questions q JOIN tests t ON t.id=q.test_id
    LEFT JOIN topics tp ON tp.id=t.topic_id
    WHERE (t.is_active=0 OR tp.is_active=0)
      AND ((q.image_path IS NOT NULL AND q.image_path<>'')
        OR (q.image IS NOT NULL AND q.image<>''))
    ORDER BY q.test_id,q.id
");
report($pdo, 'AI-bronbestanden: alle bestaande paden (nog niet verwijderen)', "
    SELECT c.id AS collectie_id, p.id AS pagina_id, p.image_path,
      r.id AS uitsnede_id, r.crop_path
    FROM ai_source_collections c
    LEFT JOIN ai_source_pages p ON p.collection_id=c.id
    LEFT JOIN ai_source_regions r ON r.page_id=p.id
    WHERE (p.image_path IS NOT NULL AND p.image_path<>'')
       OR (r.crop_path IS NOT NULL AND r.crop_path<>'')
    ORDER BY c.id,p.id,r.id
");
report($pdo, 'AI-koppelingen naar te verwijderen vragen', "
    SELECT l.collection_id, COUNT(*) AS koppelingen,
        COUNT(DISTINCT l.question_id) AS vragen
    FROM ai_source_links l
    JOIN questions q ON q.id=l.question_id
    JOIN tests t ON t.id=q.test_id
    LEFT JOIN topics tp ON tp.id=t.topic_id
    WHERE t.is_active=0 OR tp.is_active=0
    GROUP BY l.collection_id ORDER BY l.collection_id
");
report($pdo, 'AI-koppelingen naar inactieve samenvattingen', "
    SELECT l.collection_id, COUNT(*) AS koppelingen,
        COUNT(DISTINCT l.summary_id) AS samenvattingen
    FROM ai_source_links l
    JOIN topic_summaries s ON s.id=l.summary_id
    WHERE s.is_active=0
    GROUP BY l.collection_id ORDER BY l.collection_id
");
report($pdo, 'Studievoortgang bij inactieve overhoringen', "
    SELECT tp.id AS topic_id, tp.name,
        (SELECT COUNT(*) FROM topic_grades g WHERE g.topic_id=tp.id) AS cijfers,
        (SELECT COUNT(*) FROM topic_summaries s WHERE s.topic_id=tp.id) AS samenvattingen
    FROM topics tp WHERE tp.is_active=0 ORDER BY tp.id
");
report($pdo, 'Foreign keys voor verwijderplanning', "
    SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
           r.DELETE_RULE
    FROM information_schema.KEY_COLUMN_USAGE k
    JOIN information_schema.REFERENTIAL_CONSTRAINTS r
      ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
     AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
     AND r.TABLE_NAME=k.TABLE_NAME
    WHERE k.TABLE_SCHEMA=DATABASE()
      AND k.REFERENCED_TABLE_NAME IS NOT NULL
    ORDER BY k.TABLE_NAME,k.COLUMN_NAME
");
echo "\nLET OP: ai_source_links heeft geen foreign keys; een verwijdermodus moet eerst deze koppelingen verwijderen.\n";
echo "\nKLAAR: niets gewijzigd. Geen SQL-verwijdering, geen bestandsverwijdering.\n";
