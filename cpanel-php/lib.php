<?php
declare(strict_types=1);

function ilmek_config(): array {
    // CLI/cron may inherit DOCUMENT_ROOT from the hosting environment; do not use that web-path boundary when locating the private config.
    $docroot = PHP_SAPI === 'cli' ? '' : (realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    $dir = realpath(__DIR__) ?: __DIR__;
    while ($dir !== dirname($dir)) {
        $candidate = $dir . DIRECTORY_SEPARATOR . 'ilmek-config.php';
        if (is_file($candidate)) {
            $real = realpath($candidate) ?: $candidate;
            if ($docroot === '' || strpos($real, rtrim($docroot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
                $config = require $real;
                if (is_array($config)) return $config;
            }
        }
        $dir = dirname($dir);
    }
    throw new RuntimeException('Web kökü dışında ilmek-config.php bulunamadı. Kurulum rehberini izleyin.');
}

function ilmek_data_dir(): string {
    static $path = null;
    if ($path !== null) return $path;
    $cfg = ilmek_config();
    $path = rtrim((string)($cfg['data_dir'] ?? ''), DIRECTORY_SEPARATOR);
    if ($path === '' || !str_starts_with($path, DIRECTORY_SEPARATOR)) throw new RuntimeException('data_dir mutlak bir klasör olmalı.');
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Veri klasörü oluşturulamadı.');
    return $path;
}

function ilmek_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = ilmek_config();
    $dsn = 'mysql:host=' . ($c['db_host'] ?? 'localhost') . ';dbname=' . ($c['db_name'] ?? '') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)($c['db_user'] ?? ''), (string)($c['db_password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $schema = [
        "CREATE TABLE IF NOT EXISTS products (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(180) NOT NULL,supplier_id INT UNSIGNED NULL,category VARCHAR(120) NOT NULL DEFAULT '',variant VARCHAR(120) NOT NULL DEFAULT '',barcode VARCHAR(80) NULL,stock INT NOT NULL DEFAULT 0,cost DECIMAL(15,4) NOT NULL DEFAULT 0,cost_usd DECIMAL(15,8) NOT NULL DEFAULT 0,price_usd DECIMAL(15,8) NOT NULL DEFAULT 0,low_stock INT NOT NULL DEFAULT 5,image VARCHAR(180) NOT NULL DEFAULT '',created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_products_barcode(barcode)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS sales (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,total DECIMAL(15,2) NOT NULL,payment VARCHAR(12) NOT NULL,user_id INT UNSIGNED NULL,status VARCHAR(16) NOT NULL DEFAULT 'completed',cancel_reason VARCHAR(400) NOT NULL DEFAULT '',exchange_rate DECIMAL(15,6) NOT NULL DEFAULT 0,rate_date VARCHAR(10) NOT NULL DEFAULT '',INDEX idx_sales_created(created),INDEX idx_sales_user(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS sale_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sale_id BIGINT UNSIGNED NOT NULL,product_id INT UNSIGNED NULL,name VARCHAR(180) NOT NULL,qty INT NOT NULL,unit_price DECIMAL(15,2) NOT NULL,unit_cost DECIMAL(15,4) NOT NULL,INDEX idx_items_sale(sale_id),CONSTRAINT fk_items_sale FOREIGN KEY(sale_id) REFERENCES sales(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS expenses (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,title VARCHAR(180) NOT NULL,amount DECIMAL(15,2) NOT NULL,payment VARCHAR(12) NOT NULL,user_id INT UNSIGNED NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS stock_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,product_id INT UNSIGNED NULL,name VARCHAR(180) NOT NULL,qty INT NOT NULL,unit_cost DECIMAL(15,4) NOT NULL DEFAULT 0,note VARCHAR(400) NOT NULL DEFAULT '',user_id INT UNSIGNED NULL,INDEX idx_stocklog_created(created)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,username VARCHAR(32) NOT NULL UNIQUE,name VARCHAR(120) NOT NULL,role VARCHAR(12) NOT NULL,password_hash VARCHAR(255) NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS sessions (token_hash CHAR(64) PRIMARY KEY,user_id INT UNSIGNED NOT NULL,created BIGINT NOT NULL,last_seen BIGINT NOT NULL,INDEX idx_sessions_user(user_id),CONSTRAINT fk_sessions_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS activity_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,user_id INT UNSIGNED NULL,action VARCHAR(120) NOT NULL,details VARCHAR(400) NOT NULL DEFAULT '',INDEX idx_activity_created(created)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(80) PRIMARY KEY,value_text TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS login_failures (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ip_hash CHAR(64) NOT NULL,created BIGINT NOT NULL,INDEX idx_login_ip(ip_hash,created)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS suppliers (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(180) NOT NULL,phone VARCHAR(60) NOT NULL DEFAULT '',note VARCHAR(400) NOT NULL DEFAULT '',created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS purchases (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,supplier_id INT UNSIGNED NULL,supplier_name VARCHAR(180) NOT NULL DEFAULT '',created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,total DECIMAL(15,2) NOT NULL,paid DECIMAL(15,2) NOT NULL DEFAULT 0,payment VARCHAR(12) NOT NULL DEFAULT 'Borç',exchange_rate DECIMAL(15,6) NOT NULL DEFAULT 0,rate_date VARCHAR(10) NOT NULL DEFAULT '',note VARCHAR(400) NOT NULL DEFAULT '',user_id INT UNSIGNED NULL,INDEX idx_purchases_created(created)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS purchase_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,purchase_id BIGINT UNSIGNED NOT NULL,product_id INT UNSIGNED NULL,name VARCHAR(180) NOT NULL,variant VARCHAR(120) NOT NULL DEFAULT '',qty INT NOT NULL,unit_cost DECIMAL(15,4) NOT NULL,unit_cost_usd DECIMAL(15,8) NOT NULL,INDEX idx_purchase_items(purchase_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS supplier_payments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,amount DECIMAL(15,2) NOT NULL,payment VARCHAR(12) NOT NULL,created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,note VARCHAR(400) NOT NULL DEFAULT '',user_id INT UNSIGNED NULL,INDEX idx_supplier_payments(supplier_id,created)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS cash_closings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,closing_date VARCHAR(10) NOT NULL UNIQUE,expected_cash DECIMAL(15,2) NOT NULL DEFAULT 0,counted_cash DECIMAL(15,2) NOT NULL DEFAULT 0,expected_card DECIMAL(15,2) NOT NULL DEFAULT 0,counted_card DECIMAL(15,2) NOT NULL DEFAULT 0,expected_iban DECIMAL(15,2) NOT NULL DEFAULT 0,counted_iban DECIMAL(15,2) NOT NULL DEFAULT 0,note VARCHAR(400) NOT NULL DEFAULT '',user_id INT UNSIGNED NULL,created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS stock_counts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,note VARCHAR(400) NOT NULL DEFAULT '',user_id INT UNSIGNED NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS stock_count_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,count_id BIGINT UNSIGNED NOT NULL,product_id INT UNSIGNED NULL,name VARCHAR(180) NOT NULL,expected INT NOT NULL,counted INT NOT NULL,difference INT NOT NULL,INDEX idx_stock_count(count_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS sale_returns (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sale_id BIGINT UNSIGNED NOT NULL,created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,total DECIMAL(15,2) NOT NULL,reason VARCHAR(400) NOT NULL,user_id INT UNSIGNED NULL,INDEX idx_returns_sale(sale_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS sale_return_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,return_id BIGINT UNSIGNED NOT NULL,sale_item_id BIGINT UNSIGNED NOT NULL,product_id INT UNSIGNED NULL,name VARCHAR(180) NOT NULL,qty INT NOT NULL,amount DECIMAL(15,2) NOT NULL,INDEX idx_return_items(return_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($schema as $sql) $pdo->exec($sql);
    $columns=$pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('barcode',$columns,true))$pdo->exec('ALTER TABLE products ADD COLUMN barcode VARCHAR(80) NULL');
    if(!in_array('variant',$columns,true))$pdo->exec("ALTER TABLE products ADD COLUMN variant VARCHAR(120) NOT NULL DEFAULT ''");
    if(!in_array('supplier_id',$columns,true))$pdo->exec('ALTER TABLE products ADD COLUMN supplier_id INT UNSIGNED NULL');
    $supplierColumns=$pdo->query('SHOW COLUMNS FROM suppliers')->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('active',$supplierColumns,true))$pdo->exec('ALTER TABLE suppliers ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1');
    $indexes=$pdo->query('SHOW INDEX FROM products')->fetchAll(PDO::FETCH_COLUMN,2);
    if(!in_array('uq_products_barcode',$indexes,true))$pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_products_barcode(barcode)');
    return $pdo;
}

function ilmek_json(mixed $body, int $status=200, array $headers=[]): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $key=>$value) header($key . ': ' . $value);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ilmek_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 60*1024*1024) throw new InvalidArgumentException('İstek boyutu çok büyük.');
    if ($raw === '') return [];
    $d = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($d)) throw new InvalidArgumentException('Geçersiz istek.');
    return $d;
}

function ilmek_user(): ?array {
    $token = (string)($_COOKIE['ilmek_session'] ?? '');
    if ($token === '') return null;
    $db = ilmek_db();
    $q = $db->prepare('SELECT s.user_id,s.created,s.last_seen,u.username,u.name,u.role,u.active FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=?');
    $q->execute([hash('sha256',$token)]); $row = $q->fetch();
    if (!$row) return null;
    $now=time();
    if ($now-(int)$row['last_seen']>43200 || $now-(int)$row['created']>604800) {
        $db->prepare('DELETE FROM sessions WHERE token_hash=?')->execute([hash('sha256',$token)]); return null;
    }
    if (!(int)$row['active']) { $db->prepare('DELETE FROM sessions WHERE token_hash=?')->execute([hash('sha256',$token)]); return null; }
    $db->prepare('UPDATE sessions SET last_seen=? WHERE token_hash=?')->execute([$now,hash('sha256',$token)]);
    return ['id'=>(int)$row['user_id'],'username'=>$row['username'],'name'=>$row['name'],'role'=>$row['role'],'active'=>(int)$row['active']];
}

function ilmek_require_user(bool $admin=false): array {
    $u=ilmek_user();
    if (!$u) ilmek_json(['error'=>'Oturum açın'],401);
    if ($admin && $u['role']!=='admin') ilmek_json(['error'=>'Bu bölüm yalnızca yöneticiye açık'],403);
    return $u;
}

function ilmek_new_session(int $userId): string {
    $token=bin2hex(random_bytes(32));$now=time();
    ilmek_db()->prepare('INSERT INTO sessions(token_hash,user_id,created,last_seen) VALUES(?,?,?,?)')->execute([hash('sha256',$token),$userId,$now,$now]);
    setcookie('ilmek_session',$token,['expires'=>$now+604800,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    return $token;
}

function ilmek_log(?array $user,string $action,string $details=''): void {
    ilmek_db()->prepare('INSERT INTO activity_log(user_id,action,details) VALUES(?,?,?)')->execute([$user['id']??null,$action,mb_substr($details,0,400,'UTF-8')]);
}

function ilmek_setting(string $key,?string $value=null): ?string {
    $db=ilmek_db();
    if ($value===null) { $q=$db->prepare('SELECT value_text FROM settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?null:(string)$v; }
    $db->prepare('INSERT INTO settings(setting_key,value_text) VALUES(?,?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)')->execute([$key,$value]);return $value;
}

function ilmek_rate(): array {
    $today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
    if (ilmek_setting('rate_day')===$today && ($v=ilmek_setting('rate'))!==null && (float)$v>0) return ['value'=>(float)$v,'date'=>ilmek_setting('rate_date')??'','snapshot_day'=>$today,'source'=>ilmek_setting('rate_source')??'TCMB · USD Döviz satış','stale'=>ilmek_setting('rate_stale')==='1'];
    $old=ilmek_setting('rate');$oldDate=ilmek_setting('rate_date')??'';
    try {
        $ch=curl_init('https://www.tcmb.gov.tr/kurlar/today.xml');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_USERAGENT=>'IlmekPOS/1.0']);
        $xml=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if (!is_string($xml)||$status<200||$status>=300) throw new RuntimeException('TCMB kuruna erişilemedi.');
        if (!preg_match('/<Currency\b(?=[^>]*CurrencyCode="USD")[^>]*>(.*?)<\/Currency>/s',$xml,$node) || !preg_match('/<ForexSelling>([^<]+)<\/ForexSelling>/',$node[1],$m)) throw new RuntimeException('TCMB USD kuru bulunamadı.');
        $rate=(float)str_replace(',','.',$m[1]);
        if ($rate<=0) throw new RuntimeException('TCMB USD kuru geçersiz.');
        if (preg_match('/<Tarih_Date[^>]*Date="([^"]+)"/',$xml,$d)) $published=$d[1];
        elseif (preg_match('/<Tarih_Date[^>]*Tarih="([^"]+)"/',$xml,$d)) $published=$d[1];
        else $published=$today;
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/',$published)) {$x=DateTimeImmutable::createFromFormat('m/d/Y',$published);$published=$x?$x->format('Y-m-d'):$today;}
        elseif (preg_match('/^\d{2}\.\d{2}\.\d{4}$/',$published)) {$x=DateTimeImmutable::createFromFormat('d.m.Y',$published);$published=$x?$x->format('Y-m-d'):$today;}
        $source='TCMB · USD Döviz satış';$stale='0';
    } catch (Throwable $e) {
        if (!$old || (float)$old<=0) throw new RuntimeException('Günlük kur alınamadı; internet bağlantısını kontrol edin.');
        $rate=(float)$old;$published=$oldDate ?: 'önbellek';$source='Son kayıtlı kur · çevrimdışı yedek';$stale='1';
    }
    ilmek_setting('rate',(string)$rate);ilmek_setting('rate_date',$published);ilmek_setting('rate_day',$today);ilmek_setting('rate_source',$source);ilmek_setting('rate_stale',$stale);
    return ['value'=>$rate,'date'=>$published,'snapshot_day'=>$today,'source'=>$source,'stale'=>$stale==='1'];
}

function ilmek_save_image(string $data): string {
    if(!preg_match('#^data:image/(webp|jpeg|png);base64,([A-Za-z0-9+/=]+)$#',$data,$m))throw new InvalidArgumentException('Upload a JPEG, PNG, or WebP product photo.');
    $bytes=base64_decode($m[2],true);$type=$m[1];
    if($bytes===false||strlen($bytes)>3*1024*1024)throw new InvalidArgumentException('Photo must be smaller than 3 MB.');
    $valid=$type==='webp'?substr($bytes,0,4)==='RIFF'&&substr($bytes,8,4)==='WEBP':($type==='jpeg'?substr($bytes,0,3)==="\xFF\xD8\xFF":substr($bytes,0,8)==="\x89PNG\r\n\x1a\n");
    if(!$valid)throw new InvalidArgumentException('Photo data does not match its file type.');
    $ext=$type==='jpeg'?'jpg':$type;$dir=ilmek_data_dir().'/images';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Unable to create photo folder.');
    $name=bin2hex(random_bytes(16)).'.'.$ext;if(file_put_contents($dir.'/'.$name,$bytes,LOCK_EX)===false)throw new RuntimeException('Unable to save photo.');@chmod($dir.'/'.$name,0600);
    return '/images/'.$name;
}

function ilmek_backup(bool $uploadDrive=true): array {
    $db=ilmek_db();$tables=['products','sales','sale_items','sale_returns','sale_return_items','expenses','stock_log','suppliers','purchases','purchase_items','supplier_payments','cash_closings','stock_counts','stock_count_items','activity_log','settings'];$data=[];
    foreach($tables as $t)$data[$t]=$db->query('SELECT * FROM `'.$t.'`')->fetchAll();
    $images=[];$dir=ilmek_data_dir().'/images';
    if(is_dir($dir))foreach(array_merge(glob($dir.'/*.webp')?:[],glob($dir.'/*.jpg')?:[],glob($dir.'/*.png')?:[]) as $p)$images[basename($p)]=base64_encode((string)file_get_contents($p));
    $data['images']=$images;$data['backup_created']=(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->format(DATE_ATOM);
    $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $payload=function_exists('gzencode')?gzencode($json,9):$json;
    $ext=function_exists('gzencode')?'json.gz':'json';$dir=ilmek_data_dir().'/backups';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Yedek klasörü oluşturulamadı.');
    $name='ilmek-'.date('Y-m-d_H-i-s').'.'.$ext;$path=$dir.'/'.$name;file_put_contents($path,$payload,LOCK_EX);@chmod($path,0600);
    $files=glob($dir.'/ilmek-*')?:[];usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));foreach(array_slice($files,30) as $old)@unlink($old);
    $cloud=['enabled'=>false,'message'=>'Drive ayarlanmamış · yerel yedek aktif'];
    if($uploadDrive){$c=ilmek_config();if(!empty($c['google_drive_client_id'])&&!empty($c['google_drive_client_secret'])&&!empty($c['google_drive_refresh_token']))$cloud=ilmek_drive_upload($path,$name,$c);}
    return ['path'=>$path,'name'=>$name,'count'=>count(glob($dir.'/ilmek-*')?:[]),'drive'=>$cloud];
}

function ilmek_drive_upload(string $path,string $name,array $c): array {
    try {
        $ch=curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>$c['google_drive_client_id'],'client_secret'=>$c['google_drive_client_secret'],'refresh_token'=>$c['google_drive_refresh_token'],'grant_type'=>'refresh_token']),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
        $resp=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$token=json_decode((string)$resp,true)['access_token']??'';
        if($status<200||$status>=300||!$token)throw new RuntimeException('Drive OAuth yenilemesi başarısız oldu.');
        $meta=['name'=>$name,'mimeType'=>'application/gzip'];if(!empty($c['google_drive_folder_id']))$meta['parents']=[$c['google_drive_folder_id']];
        $boundary='ilmek-'.bin2hex(random_bytes(12));$body="--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode($meta,JSON_UNESCAPED_SLASHES)."\r\n--$boundary\r\nContent-Type: application/gzip\r\n\r\n".file_get_contents($path)."\r\n--$boundary--";
        $ch=curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: multipart/related; boundary='.$boundary]]);$out=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$curlError=curl_error($ch);curl_close($ch);
        if($status<200||$status>=300){$response=json_decode((string)$out,true);$err=is_array($response)?($response['error']??[]):[];$reason=is_array($err)?(string)($err['status']??($err['errors'][0]['reason']??'')):(string)$err;$message=is_array($err)?(string)($err['message']??''):(string)($curlError?:'');$detail='Drive API HTTP '.$status.($reason!==''?' · '.$reason:'').($message!==''?' · '.$message:'').($curlError!==''&&$message===''?' · '.$curlError:'');$detail=preg_replace('/ya29\.[A-Za-z0-9._-]+/','[token gizlendi]',$detail);throw new RuntimeException($detail); }
        return ['enabled'=>true,'message'=>'Drive yedeği tamamlandı','name'=>$name];
    } catch(Throwable $e) {return ['enabled'=>true,'message'=>'Drive yüklenemedi; yerel yedek güvende','detail'=>mb_substr($e->getMessage(),0,180,'UTF-8')];}
}

function ilmek_normalize(array $rows,array $ints,array $floats=[]): array {
    foreach($rows as &$r){foreach($ints as $k)if(isset($r[$k]))$r[$k]=(int)$r[$k];foreach($floats as $k)if(isset($r[$k]))$r[$k]=(float)$r[$k];}unset($r);return $rows;
}

function ilmek_request_log(string $message,?Throwable $e=null): void {
    try {$d=ilmek_data_dir().'/logs';if(!is_dir($d))mkdir($d,0700,true);$line=date('c').' '.$message;if($e)$line.="\n".get_class($e).' ['.$e->getCode().'] '.str_replace(["\r","\n"],' ',mb_substr($e->getMessage(),0,1200,'UTF-8'))."\n".$e->getTraceAsString();file_put_contents($d.'/php-errors.log',$line."\n",FILE_APPEND|LOCK_EX);@chmod($d.'/php-errors.log',0600);}catch(Throwable $ignored){}
}
