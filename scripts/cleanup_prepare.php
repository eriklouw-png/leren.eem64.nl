<?php
declare(strict_types=1);
/**
 * Veilig voorbereidings- en uitvoeringsplan voor inactieve inhoud.
 *
 * Standaard: alleen lezen. Deze versie voert GEEN DELETE uit.
 * Gebruik: php scripts/cleanup_prepare.php
 *
 * De daadwerkelijke uitvoeringsmodus wordt pas vrijgegeven nadat back-up,
 * bestandsinventaris en referentiecontroles op productie zijn geverifieerd.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
if (count($argv) !== 1) { fwrite(STDERR, "Geen opties ondersteund. Alleen inventarisatie.\n"); exit(2); }
$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) { fwrite(STDERR, "config/config.php ontbreekt\n"); exit(1); }
$config = require $configFile;
$db = $config['db'];
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $db['host'], $db['port'], $db['name'], $db['charset'] ?? 'utf8mb4'),
    $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
function countSql(PDO $pdo, string $sql): int { return (int)$pdo->query($sql)->fetchColumn(); }
function printJson(string $label, $value): void {
    echo json_encode(['controle'=>$label,'waarde'=>$value],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
}
$inactive = 't.is_active=0 OR tp.is_active=0';
$stats = [
    'topics' => countSql($pdo, 'SELECT COUNT(*) FROM topics WHERE is_active=0'),
    'tests' => countSql($pdo, "SELECT COUNT(*) FROM tests t LEFT JOIN topics tp ON tp.id=t.topic_id WHERE $inactive"),
    'questions' => countSql($pdo, "SELECT COUNT(*) FROM questions q JOIN tests t ON t.id=q.test_id LEFT JOIN topics tp ON tp.id=t.topic_id WHERE $inactive"),
    'attempts' => countSql($pdo, "SELECT COUNT(*) FROM attempts a JOIN tests t ON t.id=a.test_id LEFT JOIN topics tp ON tp.id=t.topic_id WHERE $inactive"),
    'summaries' => countSql($pdo, 'SELECT COUNT(*) FROM topic_summaries WHERE is_active=0'),
    'ai_question_links' => countSql($pdo, "SELECT COUNT(*) FROM ai_source_links l JOIN questions q ON q.id=l.question_id JOIN tests t ON t.id=q.test_id LEFT JOIN topics tp ON tp.id=t.topic_id WHERE $inactive"),
    'ai_summary_links' => countSql($pdo, 'SELECT COUNT(*) FROM ai_source_links l JOIN topic_summaries s ON s.id=l.summary_id WHERE s.is_active=0'),
    'orphan_ai_question_links' => countSql($pdo, 'SELECT COUNT(*) FROM ai_source_links l LEFT JOIN questions q ON q.id=l.question_id WHERE l.question_id IS NOT NULL AND q.id IS NULL'),
    'orphan_ai_summary_links' => countSql($pdo, 'SELECT COUNT(*) FROM ai_source_links l LEFT JOIN topic_summaries s ON s.id=l.summary_id WHERE l.summary_id IS NOT NULL AND s.id IS NULL'),
    'inactive_topic_grades' => countSql($pdo, 'SELECT COUNT(*) FROM topic_grades g JOIN topics tp ON tp.id=g.topic_id WHERE tp.is_active=0'),
    'inactive_topic_tests' => countSql($pdo, 'SELECT COUNT(*) FROM tests t JOIN topics tp ON tp.id=t.topic_id WHERE tp.is_active=0'),
    'inactive_topic_summaries' => countSql($pdo, 'SELECT COUNT(*) FROM topic_summaries s JOIN topics tp ON tp.id=s.topic_id WHERE tp.is_active=0'),
];
echo "VEILIGE VOORBEREIDING — ",date(DATE_ATOM),"\n";
foreach ($stats as $key=>$value) printJson($key,$value);
echo "\n=== Bestandskandidaten (alleen namen; geen mutaties) ===\n";
$images = $pdo->query("
    SELECT DISTINCT q.image_path
    FROM questions q JOIN tests t ON t.id=q.test_id
    LEFT JOIN topics tp ON tp.id=t.topic_id
    WHERE ($inactive) AND q.image_path IS NOT NULL AND q.image_path<>''
    ORDER BY q.image_path
")->fetchAll(PDO::FETCH_COLUMN);
$shared = 0;
foreach ($images as $path) {
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM questions q JOIN tests t ON t.id=q.test_id
        LEFT JOIN topics tp ON tp.id=t.topic_id
        WHERE q.image_path=? AND t.is_active=1 AND tp.is_active=1
    ");
    $check->execute([$path]);
    $used = (int)$check->fetchColumn();
    if ($used) $shared++;
    printJson('vraagafbeelding', ['pad'=>$path,'actieve_verwijzingen'=>$used]);
}
printJson('afbeeldingen_totaal',count($images));
printJson('afbeeldingen_gedeeld_met_actief',$shared);
echo "\nNIET UITVOEREN: deze versie is bewust read-only.\n";
echo "Voor verwijderen: verse geverifieerde DB-back-up, uploads-back-up, schema/AI-verwijzingen en bestandslocaties controleren.\n";
