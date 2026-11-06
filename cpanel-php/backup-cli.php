<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try { $r=ilmek_backup();fwrite(STDOUT,'Yedek alındı: '.$r['name'].PHP_EOL);if(!empty($r['drive']['enabled'])){fwrite(STDOUT,$r['drive']['message'].PHP_EOL);if(!empty($r['drive']['detail']))fwrite(STDERR,'Drive ayrıntısı: '.$r['drive']['detail'].PHP_EOL);} }
catch(Throwable $e){ilmek_request_log('Scheduled backup failed',$e);fwrite(STDERR,'Yedek başarısız. Ayrıntı özel php-errors.log dosyasına yazıldı.'.PHP_EOL);exit(1);}
