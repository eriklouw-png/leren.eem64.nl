<?php
/** Structured analysis of source images, separate from lesson summaries. */
function ai_source_vision_analyze(string $path, array $titles): array {
    if (!is_file($path)) return ['error'=>'Bronfoto ontbreekt','regions'=>[]];
    $mime=(string)@mime_content_type($path);
    if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) return ['error'=>'Ongeldig beeldformaat','regions'=>[]];
    $key=openai_api_key();
    if ($key==='') return ['error'=>'API-sleutel ontbreekt','regions'=>[]];
    $bytes=@file_get_contents($path);
    if ($bytes===false) return ['error'=>'Bronfoto onleesbaar','regions'=>[]];
    $regionSchema=[
      'type'=>'object',
      'properties'=>[
        'title'=>['type'=>'string'],
        'type'=>['type'=>'string','enum'=>['image','diagram','table']],
        'x'=>['type'=>'number'],'y'=>['type'=>'number'],
        'width'=>['type'=>'number'],'height'=>['type'=>'number'],
        'section_indices'=>['type'=>'array','items'=>['type'=>'integer']]
      ],
      'required'=>['title','type','x','y','width','height','section_indices'],
      'additionalProperties'=>false
    ];
    $schema=[
      'type'=>'object',
      'properties'=>['regions'=>['type'=>'array','items'=>$regionSchema]],
      'required'=>['regions'],
      'additionalProperties'=>false
    ];
    $instructions='Analyseer de originele pixeloriëntatie van de foto. Lokaliseer maximaal acht zelfstandige afbeeldingen, diagrammen of tabellen. Negeer tekstblokken. Coördinaten x,y,width,height zijn fracties van de originele afbeelding (0 tot 1), zonder rotatie. Geef alleen duidelijk herkenbare gebieden. Koppel passende onderdelen via hun nulgebaseerde indices. Onderdelen: '.json_encode(array_values($titles),JSON_UNESCAPED_UNICODE);
    $payload=[
      'model'=>openai_model(),
      'instructions'=>$instructions,
      'input'=>[['role'=>'user','content'=>[
        ['type'=>'input_text','text'=>'Retourneer uitsluitend gestructureerde gebieden.'],
        ['type'=>'input_image','image_url'=>'data:'.$mime.';base64,'.base64_encode($bytes),'detail'=>'high']
      ]]],
      'max_output_tokens'=>2000,'store'=>false,
      'text'=>['format'=>['type'=>'json_schema','name'=>'source_regions','strict'=>true,'schema'=>$schema]]
    ];
    $ctx=stream_context_create(['http'=>[
      'method'=>'POST',
      'header'=>"Content-Type: application/json\r\nAuthorization: Bearer ".$key."\r\n",
      'content'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      'timeout'=>120,'ignore_errors'=>true
    ]]);
    $body=@file_get_contents('https://api.openai.com/v1/responses',false,$ctx);
    if ($body===false) return ['error'=>'AI-verbinding mislukt','regions'=>[]];
    $data=json_decode($body,true);
    if (!is_array($data)) return ['error'=>'AI-antwoord ongeldig','regions'=>[]];
    if (isset($data['error'])) return ['error'=>(string)($data['error']['message']??'AI-fout'),'regions'=>[]];
    $parsed=openai_output_json($data);
    if (!is_array($parsed)||!isset($parsed['regions'])||!is_array($parsed['regions'])) return ['error'=>'AI gaf geen gestructureerde gebieden','regions'=>[]];
    return ['error'=>null,'regions'=>array_slice($parsed['regions'],0,8)];
}
