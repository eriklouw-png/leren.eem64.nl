<?php
/** Normalize JPEG orientation once for both vision and cropping. */
function ai_source_oriented_image(string $path) {
    $mime=(string)@mime_content_type($path);
    $src=match($mime){
        'image/jpeg'=>@imagecreatefromjpeg($path),
        'image/png'=>@imagecreatefrompng($path),
        'image/webp'=>@imagecreatefromwebp($path),
        default=>false
    };
    if(!$src)return false;
    if($mime==='image/jpeg' && function_exists('exif_read_data')){
        $exif=@exif_read_data($path);
        $orientation=(int)($exif['Orientation']??1);
        $rotated=match($orientation){
            3=>imagerotate($src,180,0),
            6=>imagerotate($src,270,0),
            8=>imagerotate($src,90,0),
            default=>false
        };
        if($rotated!==false){imagedestroy($src);$src=$rotated;}
    }
    return $src;
}
/** Structured analysis of source images, separate from lesson summaries. */
function ai_source_vision_analyze(string $path, array $titles): array {
    if (!is_file($path)) return ['error'=>'Bronfoto ontbreekt','regions'=>[]];
    $mime=(string)@mime_content_type($path);
    if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) return ['error'=>'Ongeldig beeldformaat','regions'=>[]];
    $key=openai_api_key();
    if ($key==='') return ['error'=>'API-sleutel ontbreekt','regions'=>[]];
    if(!extension_loaded('gd'))return ['error'=>'PHP GD ontbreekt','regions'=>[]];
    $normalized=ai_source_oriented_image($path);
    if(!$normalized)return ['error'=>'Foto kan niet worden geopend','regions'=>[]];
    ob_start();
    imagejpeg($normalized,null,90);
    $bytes=ob_get_clean();
    imagedestroy($normalized);
    if(!is_string($bytes)||$bytes==='')return ['error'=>'Foto normaliseren mislukt','regions'=>[]];
    $mime='image/jpeg';
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
    $instructions='De foto is al correct georiënteerd en bevat geen EXIF-rotatie. Lokaliseer maximaal acht zelfstandige afbeeldingen, diagrammen of tabellen. Negeer tekstblokken. Coördinaten x,y,width,height zijn fracties van de getoonde rechtopstaande afbeelding (0 tot 1). Geef alleen duidelijk herkenbare gebieden. Koppel passende onderdelen via hun nulgebaseerde indices. Onderdelen: '.json_encode(array_values($titles),JSON_UNESCAPED_UNICODE);
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
