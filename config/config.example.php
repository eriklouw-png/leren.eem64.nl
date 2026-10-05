<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'leren',
        'user' => 'leren',
        'password' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'ai' => [
        'provider' => 'openai',
        'openai_model' => 'gpt-6-luna',
        // Zet de API-key bij voorkeur in .env als OPENAI_API_KEY.
        'openai_api_key' => '',
    ],
];
