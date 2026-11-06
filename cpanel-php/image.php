<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
try {
    ilmek_require_user();
    $name=(string)($_GET['id']??'');
    if(!preg_match('/^[a-f0-9]{32}\.(webp|jpg|png)$/',$name))ilmek_json(['error'=>'Görsel bulunamadı'],404);
    $path=ilmek_data_dir().'/images/'.$name;
    if(!is_file($path))ilmek_json(['error'=>'Görsel bulunamadı'],404);
    $mime=str_ends_with($name,'.webp')?'image/webp':(str_ends_with($name,'.jpg')?'image/jpeg':'image/png');header('Content-Type: '.$mime);header('Cache-Control: private, max-age=3600');header('X-Content-Type-Options: nosniff');header('Content-Length: '.filesize($path));readfile($path);
} catch(Throwable $e) {ilmek_request_log('Image request failed',$e);ilmek_json(['error'=>'Görsel açılamadı.'],500);}
