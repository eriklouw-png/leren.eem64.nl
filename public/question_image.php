<?php
require __DIR__.'/../app/bootstrap.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Ongeldig vraag-ID.');
}

$stmt = $pdo->prepare("SELECT image_path FROM questions WHERE id = ?");
$stmt->execute([$id]);
$imagePath = $stmt->fetchColumn();

if (!$imagePath) {
    http_response_code(404);
    exit('Geen afbeelding gekoppeld aan vraag '.$id.'.');
}

$imagePath = trim((string)$imagePath);

// Alleen bestandsnamen en veilige relatieve paden toestaan.
if (
    $imagePath === '' ||
    str_contains($imagePath, '..') ||
    str_starts_with($imagePath, '/') ||
    str_starts_with($imagePath, '\\') ||
    !preg_match('/^[A-Za-z0-9._\\/-]+$/', $imagePath)
) {
    http_response_code(404);
    exit('Ongeldig afbeeldingspad.');
}

$file = __DIR__.'/uploads/questions/'.$imagePath;

if (!is_file($file) || !is_readable($file)) {
    http_response_code(404);
    exit('Afbeeldingsbestand niet gevonden: '.$imagePath);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file);

$allowed = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif'
];

if (!in_array($mime, $allowed, true)) {
    http_response_code(415);
    exit('Bestand is geen ondersteund afbeeldingstype.');
}

header('Content-Type: '.$mime);
header('Content-Length: '.filesize($file));
header('Cache-Control: public, max-age=86400');

readfile($file);
exit;
