<?php
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
echo json_encode([
    'ok'=>warm_ai(),
    'provider'=>ai_provider(),
    'model'=>openai_model()
],JSON_UNESCAPED_UNICODE);
