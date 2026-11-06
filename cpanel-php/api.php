<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

function api_cookie_expire(): void { setcookie('ilmek_session','',['expires'=>1,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']); }
function api_user_row(array $r): array { return ['id'=>(int)$r['id'],'username'=>$r['username'],'name'=>$r['name'],'role'=>$r['role'],'active'=>(int)$r['active']]; }
function api_write(callable $fn): mixed {
    $db=ilmek_db();$db->beginTransaction();
    try {$out=$fn($db);$db->commit();return $out;} catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function api_cash_expected(PDO $db,string $day): array {
    $sales=['Nakit'=>0.0,'Kart'=>0.0,'IBAN'=>0.0];
    $q=$db->prepare("SELECT payment,SUM(total) amount FROM sales WHERE DATE(created)=? AND status='completed' GROUP BY payment");$q->execute([$day]);foreach($q->fetchAll() as $r)$sales[$r['payment']]=(float)$r['amount'];
    $q=$db->prepare("SELECT s.payment,SUM(r.total) amount FROM sale_returns r JOIN sales s ON s.id=r.sale_id WHERE DATE(r.created)=? GROUP BY s.payment");$q->execute([$day]);foreach($q->fetchAll() as $r)$sales[$r['payment']]-=(float)$r['amount'];
    $out=['Nakit'=>0.0,'Kart'=>0.0,'IBAN'=>0.0];
    foreach([['expenses','amount','created','payment'],['purchases','paid','created','payment'],['supplier_payments','amount','created','payment']] as [$table,$amount,$created,$payment]){$q=$db->prepare("SELECT $payment,SUM($amount) amount FROM $table WHERE DATE($created)=? GROUP BY $payment");$q->execute([$day]);foreach($q->fetchAll() as $r)if(isset($out[$r[$payment]]))$out[$r[$payment]]+=(float)$r['amount'];}
    return ['date'=>$day,'expected'=>['Nakit'=>$sales['Nakit']-$out['Nakit'],'Kart'=>$sales['Kart']-$out['Kart'],'IBAN'=>$sales['IBAN']-$out['IBAN']]];
}
$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
$route=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
try {
    $db=ilmek_db();
    if($method==='GET') {
        if($route==='/api/bootstrap') {
            $need=(int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn()===0;
            $cfg=ilmek_config();ilmek_json(['needs_setup'=>$need,'requires_setup_token'=>!empty($cfg['setup_token'])]);
        }
        if($route==='/api/rate') {
            try { ilmek_json(ilmek_rate()); }
            catch(Throwable $e){ilmek_request_log('TCMB rate request failed',$e);ilmek_json(['error'=>'Günlük kur alınamadı. Bağlantıyı kontrol edip tekrar deneyin.'],503);}
        }
        $u=ilmek_require_user();
        if($route==='/api/me')ilmek_json(['user'=>$u]);
        if($route==='/api/cash-close'){$day=(string)($_GET['date']??(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->format('Y-m-d'));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$day))throw new InvalidArgumentException('Tarih biçimi geçersiz.');$result=api_cash_expected($db,$day);$q=$db->prepare('SELECT * FROM cash_closings WHERE closing_date=?');$q->execute([$day]);$result['closing']=$q->fetch()?:null;ilmek_json($result);}

        if($route==='/api/data') {
            $products=ilmek_normalize($db->query('SELECT p.*,sp.name AS supplier_name FROM products p LEFT JOIN suppliers sp ON sp.id=p.supplier_id ORDER BY p.name')->fetchAll(),['id','stock','low_stock'],['cost','cost_usd','price_usd']);
            $sql='SELECT s.id,s.created,s.total,s.payment,s.user_id,s.status,s.cancel_reason,s.exchange_rate,s.rate_date,COALESCE((SELECT SUM(r.total) FROM sale_returns r WHERE r.sale_id=s.id),0) AS return_total FROM sales s';$params=[];
            if($u['role']!=='admin'){$sql.=' WHERE s.user_id=?';$params[]=$u['id'];}
            $q=$db->prepare($sql.' ORDER BY s.id DESC LIMIT 500');$q->execute($params);$sales=ilmek_normalize($q->fetchAll(),['id','user_id'],['total','exchange_rate']);
            $ids=array_column($sales,'id');$items=[];
            if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$q=$db->prepare("SELECT si.*,COALESCE((SELECT SUM(ri.qty) FROM sale_return_items ri WHERE ri.sale_item_id=si.id),0) AS returned_qty FROM sale_items si WHERE si.sale_id IN ($marks) ORDER BY si.id DESC LIMIT 1000");$q->execute($ids);$items=ilmek_normalize($q->fetchAll(),['id','sale_id','product_id','qty','returned_qty'],['unit_price','unit_cost']);}
            $expenses=[];if($u['role']==='admin')$expenses=ilmek_normalize($db->query('SELECT * FROM expenses ORDER BY id DESC LIMIT 100')->fetchAll(),['id','user_id'],['amount']);
            $q=$db->query('SELECT s.*,u.name AS by_name FROM stock_log s LEFT JOIN users u ON u.id=s.user_id ORDER BY s.id DESC LIMIT 500');$stock=ilmek_normalize($q->fetchAll(),['id','product_id','qty','user_id'],['unit_cost']);
            $activity=[];$staff=[];
            if($u['role']==='admin'){
                $activity=$db->query('SELECT a.id,a.created,u.name,a.action,a.details FROM activity_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')->fetchAll();
                $activity=ilmek_normalize($activity,['id']);
                $staff=ilmek_normalize($db->query('SELECT id,name,role FROM users')->fetchAll(),['id']);
            }
            if($u['role']!=='admin'){
                foreach($products as &$p){unset($p['cost'],$p['cost_usd']);}unset($p);
                foreach($items as &$i)unset($i['unit_cost']);unset($i);
                foreach($stock as &$s)unset($s['unit_cost']);unset($s);
            }
            $extra=['settings'=>['shop_name'=>ilmek_setting('shop_name')??'Ilmek','low_stock_default'=>(int)(ilmek_setting('low_stock_default')??'5'),'default_theme'=>ilmek_setting('default_theme')??'sage','report_period'=>(int)(ilmek_setting('report_period')??'7')]];
            if($u['role']==='admin'){
                foreach(['suppliers','purchases','supplier_payments','cash_closings','stock_counts'] as $table)$extra[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id DESC LIMIT 500')->fetchAll();
                $extra['purchase_items']=$db->query('SELECT * FROM purchase_items ORDER BY id DESC LIMIT 1000')->fetchAll();
                $extra['sale_returns']=$db->query('SELECT * FROM sale_returns ORDER BY id DESC LIMIT 500')->fetchAll();
                $extra['sale_return_items']=$db->query('SELECT * FROM sale_return_items ORDER BY id DESC LIMIT 1000')->fetchAll();$extra['stock_count_items']=$db->query('SELECT * FROM stock_count_items ORDER BY id DESC LIMIT 2000')->fetchAll();
            }else $extra['suppliers']=$db->query('SELECT id,name,active FROM suppliers WHERE active=1 ORDER BY name')->fetchAll();
            ilmek_json(['products'=>$products,'sales'=>$sales,'sale_items'=>$items,'expenses'=>$expenses,'stock_log'=>$stock,'activity'=>$activity,'users'=>$staff,'user'=>$u]+$extra);
        }
        if($route==='/api/users') {
            if($u['role']!=='admin')ilmek_json(['error'=>'Bu bölüm yalnızca yöneticiye açık'],403);
            $users=ilmek_normalize($db->query('SELECT id,username,name,role,active,created FROM users ORDER BY role,name')->fetchAll(),['id','active']);ilmek_json(['users'=>$users]);
        }
        if($route==='/api/backup'||$route==='/api/export') {
            ilmek_require_user(true);
            if($route==='/api/backup'){$b=ilmek_backup();ilmek_json(['ok'=>true,'count'=>$b['count'],'drive'=>$b['drive']]);}
            $out=[];foreach(['products','sales','sale_items','sale_returns','sale_return_items','expenses','stock_log','suppliers','purchases','purchase_items','supplier_payments','cash_closings','stock_counts','stock_count_items','activity_log','settings'] as $t)$out[$t]=$db->query('SELECT * FROM `'.$t.'`')->fetchAll();$out['images']=[];$imageDir=ilmek_data_dir().'/images';if(is_dir($imageDir))foreach(array_merge(glob($imageDir.'/*.webp')?:[],glob($imageDir.'/*.jpg')?:[],glob($imageDir.'/*.png')?:[]) as $file)$out['images'][basename($file)]=base64_encode((string)file_get_contents($file));$out['backup_created']=(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->format(DATE_ATOM);ilmek_json($out);
        }
        ilmek_json(['error'=>'Bulunamadı'],404);
    }
    if(!in_array($method,['POST','DELETE'],true))ilmek_json(['error'=>'Yöntem desteklenmiyor'],405);
    try {$data=ilmek_body();}catch(Throwable $e){ilmek_json(['error'=>'Geçersiz veya büyük istek.'],400);}
    if($route==='/api/setup-admin') {
        $cfg=ilmek_config();$expected=(string)($cfg['setup_token']??'');
        if($expected!==''&&!hash_equals($expected,(string)($data['setup_token']??'')))ilmek_json(['error'=>'Kurulum anahtarı yanlış'],403);
        $username=trim((string)($data['username']??''));$name=trim((string)($data['name']??''));$password=(string)($data['password']??'');
        if(strlen($password)<10)throw new InvalidArgumentException('Yönetici parolası en az 10 karakter olmalı.');
        if(!preg_match('/^[A-Za-z0-9_.-]{3,32}$/',$username))throw new InvalidArgumentException('Kullanıcı adı 3–32 karakter olmalı; harf, sayı, nokta ve tire kullanın.');
        $user=api_write(function($db)use($username,$name,$password){if((int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn()>0)throw new DomainException('Yönetici hesabı zaten kurulu.');$q=$db->prepare("INSERT INTO users(username,name,role,password_hash) VALUES(?,?,'admin',?)");$q->execute([$username,$name?:$username,password_hash($password,PASSWORD_DEFAULT)]);$u=['id'=>(int)$db->lastInsertId(),'username'=>$username,'name'=>$name?:$username,'role'=>'admin','active'=>1];ilmek_log($u,'Yönetici hesabı oluşturuldu');return $u;});
        ilmek_new_session($user['id']);ilmek_json(['user'=>$user]);
    }
    if($route==='/api/login') {
        $ip=$_SERVER['REMOTE_ADDR']??'unknown';$ipHash=hash('sha256',$ip);$cut=time()-900;
        $db->prepare('DELETE FROM login_failures WHERE created<?')->execute([$cut]);$q=$db->prepare('SELECT COUNT(*) FROM login_failures WHERE ip_hash=? AND created>?');$q->execute([$ipHash,$cut]);
        if((int)$q->fetchColumn()>=8)ilmek_json(['error'=>'Çok sayıda deneme oldu. 15 dakika sonra tekrar deneyin.'],429);
        $q=$db->prepare('SELECT * FROM users WHERE username=? AND active=1 LIMIT 1');$q->execute([trim((string)($data['username']??''))]);$row=$q->fetch();
        if(!$row||!password_verify((string)($data['password']??''),$row['password_hash'])){$db->prepare('INSERT INTO login_failures(ip_hash,created) VALUES(?,?)')->execute([$ipHash,time()]);ilmek_json(['error'=>'Kullanıcı adı veya parola hatalı'],401);}
        $db->prepare('DELETE FROM login_failures WHERE ip_hash=?')->execute([$ipHash]);$user=api_user_row($row);ilmek_new_session($user['id']);ilmek_json(['user'=>$user]);
    }
    $u=ilmek_require_user();
    if($route==='/api/cash-close'&&$method==='DELETE'){
        if($u['role']!=='admin')ilmek_json(['error'=>'Gün sonu kaydını yalnızca yönetici silebilir'],403);$day=(string)($_GET['date']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$day))throw new InvalidArgumentException('Tarih biçimi geçersiz.');$q=$db->prepare('DELETE FROM cash_closings WHERE closing_date=?');$q->execute([$day]);if(!$q->rowCount())throw new InvalidArgumentException('Bu tarihte kasa kapanış kaydı bulunamadı.');ilmek_log($u,'Gün sonu kasa kaydı silindi',$day);ilmek_json(['ok'=>true]);
    }
    if($route==='/api/suppliers'){
        if($u['role']!=='admin')ilmek_json(['error'=>'Tedarikçi kaydını yalnızca yönetici yapabilir'],403);
        $name=trim((string)($data['name']??''));if($name==='')throw new InvalidArgumentException('Tedarikçi adı girin.');
        $db->prepare('INSERT INTO suppliers(name,phone,note,active) VALUES(?,?,?,1)')->execute([$name,trim((string)($data['phone']??'')),trim((string)($data['note']??''))]);ilmek_log($u,'Tedarikçi eklendi',$name);ilmek_json(['ok'=>true]);
    }
    if(preg_match('#^/api/suppliers/(\d+)$#',$route,$m)){
        if($u['role']!=='admin')ilmek_json(['error'=>'Tedarikçileri yalnızca yönetici düzenleyebilir'],403);$sid=(int)$m[1];
        if($method==='DELETE'){$q=$db->prepare('UPDATE suppliers SET active=0 WHERE id=? AND active=1');$q->execute([$sid]);if(!$q->rowCount())throw new InvalidArgumentException('Tedarikçi bulunamadı veya zaten arşivlenmiş.');$q=$db->prepare('SELECT name FROM suppliers WHERE id=?');$q->execute([$sid]);ilmek_log($u,'Tedarikçi arşivlendi',(string)$q->fetchColumn());ilmek_json(['ok'=>true]);}
        $name=trim((string)($data['name']??''));if($name==='')throw new InvalidArgumentException('Tedarikçi adı girin.');$q=$db->prepare('UPDATE suppliers SET name=?,phone=?,note=? WHERE id=?');$q->execute([$name,trim((string)($data['phone']??'')),trim((string)($data['note']??'')),$sid]);if(!$q->rowCount()){$check=$db->prepare('SELECT COUNT(*) FROM suppliers WHERE id=?');$check->execute([$sid]);if(!(int)$check->fetchColumn())throw new InvalidArgumentException('Tedarikçi bulunamadı.');}ilmek_log($u,'Tedarikçi düzenlendi',$name);ilmek_json(['ok'=>true]);
    }
    if(preg_match('#^/api/suppliers/(\d+)/restore$#',$route,$m)){
        if($u['role']!=='admin')ilmek_json(['error'=>'Tedarikçileri yalnızca yönetici düzenleyebilir'],403);$sid=(int)$m[1];$q=$db->prepare('UPDATE suppliers SET active=1 WHERE id=?');$q->execute([$sid]);if(!$q->rowCount()){$check=$db->prepare('SELECT COUNT(*) FROM suppliers WHERE id=?');$check->execute([$sid]);if(!(int)$check->fetchColumn())throw new InvalidArgumentException('Tedarikçi bulunamadı.');}ilmek_json(['ok'=>true]);
    }
    if($route==='/api/supplier-payments'){
        if($u['role']!=='admin')ilmek_json(['error'=>'Tedarikçi ödemesini yalnızca yönetici kaydedebilir'],403);
        $sid=(int)($data['supplier_id']??0);$amount=(float)($data['amount']??0);$method=(string)($data['payment']??'');
        if(!$sid||$amount<=0||!in_array($method,['Nakit','Kart','IBAN'],true))throw new InvalidArgumentException('Tedarikçi, tutar ve ödeme biçimini kontrol edin.');
        api_write(function($db)use($sid,$amount,$method,$data,$u){$q=$db->prepare('SELECT name FROM suppliers WHERE id=? FOR UPDATE');$q->execute([$sid]);$name=$q->fetchColumn();if(!$name)throw new InvalidArgumentException('Tedarikçi bulunamadı.');$q=$db->prepare('SELECT COALESCE(SUM(total-paid),0) FROM purchases WHERE supplier_id=?');$q->execute([$sid]);$due=(float)$q->fetchColumn();$q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id=?');$q->execute([$sid]);$due-=(float)$q->fetchColumn();if($amount>$due)throw new InvalidArgumentException('Ödeme kalan tedarikçi borcundan fazla olamaz.');$db->prepare('INSERT INTO supplier_payments(supplier_id,amount,payment,note,user_id) VALUES(?,?,?,?,?)')->execute([$sid,$amount,$method,trim((string)($data['note']??'')),$u['id']]);ilmek_log($u,'Tedarikçi ödemesi',"$name · $amount TL");return true;});ilmek_json(['ok'=>true]);
    }
    if($route==='/api/purchases'){
        $r=ilmek_rate();if(($data['rate_day']??'')!==$r['snapshot_day'])throw new InvalidArgumentException('Günlük kur değişti; alış kaydını kontrol edip tekrar kaydedin.');
        $items=$data['items']??[];$payment=(string)($data['payment']??'Borç');$paid=(float)($data['paid']??0);if(!is_array($items)||!$items||!in_array($payment,['Nakit','Kart','IBAN','Borç'],true)||$paid<0||($paid>0&&$payment==='Borç'))throw new InvalidArgumentException('Alış ve ödeme bilgilerini kontrol edin.');
        $result=api_write(function($db)use($items,$payment,$paid,$r,$data,$u){$sid=(int)($data['supplier_id']??0);$supplier=trim((string)($data['supplier_name']??''));if($sid){$q=$db->prepare('SELECT name FROM suppliers WHERE id=?');$q->execute([$sid]);$known=$q->fetchColumn();if(!$known)throw new InvalidArgumentException('Tedarikçi bulunamadı.');$supplier=(string)$known;}elseif($supplier!==''){$q=$db->prepare('SELECT id FROM suppliers WHERE name=? LIMIT 1');$q->execute([$supplier]);$found=$q->fetchColumn();if($found)$sid=(int)$found;else{$db->prepare('INSERT INTO suppliers(name) VALUES(?)')->execute([$supplier]);$sid=(int)$db->lastInsertId();}}
            $checked=[];$total=0;foreach($items as $item){$pid=(int)($item['product_id']??0);$qty=filter_var($item['qty']??0,FILTER_VALIDATE_INT);$cost=(float)($item['unit_cost']??-1);if(!$pid||$qty===false||$qty<1||$cost<0)throw new InvalidArgumentException('Ürün, adet ve alış maliyetini kontrol edin.');$q=$db->prepare('SELECT * FROM products WHERE id=? FOR UPDATE');$q->execute([$pid]);$p=$q->fetch();if(!$p)throw new InvalidArgumentException('Ürün bulunamadı.');$checked[]=[$p,(int)$qty,$cost];$total+=(int)$qty*$cost;}
            if($paid>$total)throw new InvalidArgumentException('Ödenen tutar alış toplamından fazla olamaz.');
            $db->prepare('INSERT INTO purchases(supplier_id,supplier_name,total,paid,payment,exchange_rate,rate_date,note,user_id) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$sid?:null,$supplier,$total,$paid,$paid>0?$payment:'Borç',$r['value'],$r['date'],trim((string)($data['note']??'')),$u['id']]);$purchase=(int)$db->lastInsertId();
            foreach($checked as [$p,$qty,$cost]){$old=(int)$p['stock'];$new=$old+$qty;$usd=$cost/(float)$r['value'];$avg=$new?(((float)$p['cost']*$old+$qty*$cost)/$new):$cost;$avgUsd=$new?(((float)$p['cost_usd']*$old+$qty*$usd)/$new):$usd;$db->prepare('UPDATE products SET stock=?,cost=?,cost_usd=? WHERE id=?')->execute([$new,$avg,$avgUsd,$p['id']]);$db->prepare('INSERT INTO purchase_items(purchase_id,product_id,name,variant,qty,unit_cost,unit_cost_usd) VALUES(?,?,?,?,?,?,?)')->execute([$purchase,$p['id'],$p['name'],$p['variant']??'', $qty,$cost,$usd]);$db->prepare('INSERT INTO stock_log(product_id,name,qty,unit_cost,note,user_id) VALUES(?,?,?,?,?,?)')->execute([$p['id'],$p['name'].' '.($p['variant']??''),$qty,$cost,'Yeni alım',$u['id']]);}
            ilmek_log($u,'Alış kaydı',"#$purchase · $supplier · $total TL");return ['purchase_id'=>$purchase,'total'=>$total];});ilmek_json(['ok'=>true]+$result);
    }
    if($route==='/api/stock-counts'){
        if($u['role']!=='admin')ilmek_json(['error'=>'Stok sayımını yalnızca yönetici kaydedebilir'],403);$items=$data['items']??[];if(!is_array($items)||!$items)throw new InvalidArgumentException('Sayım için ürün girin.');
        $result=api_write(function($db)use($items,$data,$u){$db->prepare('INSERT INTO stock_counts(note,user_id) VALUES(?,?)')->execute([trim((string)($data['note']??'')),$u['id']]);$cid=(int)$db->lastInsertId();$changed=0;foreach($items as $item){$pid=(int)($item['product_id']??0);$counted=filter_var($item['counted']??null,FILTER_VALIDATE_INT);if(!$pid||$counted===false||$counted<0)throw new InvalidArgumentException('Sayım adetlerini kontrol edin.');$q=$db->prepare('SELECT * FROM products WHERE id=? FOR UPDATE');$q->execute([$pid]);$p=$q->fetch();if(!$p)continue;$expected=(int)$p['stock'];$diff=(int)$counted-$expected;$db->prepare('INSERT INTO stock_count_items(count_id,product_id,name,expected,counted,difference) VALUES(?,?,?,?,?,?)')->execute([$cid,$pid,$p['name'].' '.($p['variant']??''),$expected,$counted,$diff]);if($diff){$db->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$counted,$pid]);$db->prepare('INSERT INTO stock_log(product_id,name,qty,unit_cost,note,user_id) VALUES(?,?,?,?,?,?)')->execute([$pid,$p['name'],$diff,$p['cost'],'Sayım #'.$cid,$u['id']]);$changed++;}}ilmek_log($u,'Stok sayımı',"$cid · $changed ürün düzeltildi");return ['count_id'=>$cid,'changed'=>$changed];});ilmek_json(['ok'=>true]+$result);
    }
    if(preg_match('#^/api/sales/(\d+)/return$#',$route,$m)){
        if($u['role']!=='admin')ilmek_json(['error'=>'Satış iadesini yalnızca yönetici kaydedebilir'],403);$sid=(int)$m[1];$items=$data['items']??[];$reason=trim((string)($data['reason']??''));if(!is_array($items)||!$items||$reason==='')throw new InvalidArgumentException('İade kalemlerini ve nedenini girin.');
        $result=api_write(function($db)use($sid,$items,$reason,$u){$q=$db->prepare("SELECT * FROM sales WHERE id=? AND status='completed' FOR UPDATE");$q->execute([$sid]);if(!$q->fetch())throw new InvalidArgumentException('Aktif satış bulunamadı.');$checked=[];$total=0;foreach($items as $row){$iid=(int)($row['sale_item_id']??0);$qty=filter_var($row['qty']??0,FILTER_VALIDATE_INT);$q=$db->prepare('SELECT * FROM sale_items WHERE id=? AND sale_id=? FOR UPDATE');$q->execute([$iid,$sid]);$item=$q->fetch();if(!$item||$qty===false||$qty<1)throw new InvalidArgumentException('İade adedini kontrol edin.');$q=$db->prepare('SELECT COALESCE(SUM(qty),0) FROM sale_return_items WHERE sale_item_id=?');$q->execute([$iid]);$returned=(int)$q->fetchColumn();if($returned+(int)$qty>(int)$item['qty'])throw new InvalidArgumentException('İade adedi satılan adetten fazla olamaz.');$checked[]=[$item,(int)$qty];$total+=(int)$qty*(float)$item['unit_price'];}
            $db->prepare('INSERT INTO sale_returns(sale_id,total,reason,user_id) VALUES(?,?,?,?)')->execute([$sid,$total,$reason,$u['id']]);$rid=(int)$db->lastInsertId();foreach($checked as [$item,$qty]){$amount=$qty*(float)$item['unit_price'];$db->prepare('INSERT INTO sale_return_items(return_id,sale_item_id,product_id,name,qty,amount) VALUES(?,?,?,?,?,?)')->execute([$rid,$item['id'],$item['product_id'],$item['name'],$qty,$amount]);if($item['product_id']!==null){$db->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$qty,$item['product_id']]);$db->prepare('INSERT INTO stock_log(product_id,name,qty,unit_cost,note,user_id) VALUES(?,?,?,?,?,?)')->execute([$item['product_id'],$item['name'],$qty,$item['unit_cost'],'Satış iadesi #'.$sid,$u['id']]);}}ilmek_log($u,'Kısmi iade',"$sid · $total TL · $reason");return ['return_id'=>$rid,'total'=>$total];});ilmek_json(['ok'=>true]+$result);
    }
    if($route==='/api/cash-close'){
        $day=(string)($data['date']??(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->format('Y-m-d'));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$day))throw new InvalidArgumentException('Invalid date format.');
        if($u['role']!=='admin'&&$day!==(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->format('Y-m-d'))ilmek_json(['error'=>'Cashiers can only close today.'],403);
        $v=$data['counted']??[];
        foreach(['Nakit','Kart','IBAN'] as $method)if(!is_array($v)||!array_key_exists($method,$v)||!is_numeric($v[$method])||(float)$v[$method]<0)throw new InvalidArgumentException('Enter zero or a positive amount for cash, card, and IBAN.');
        $cash=(float)$v['Nakit'];$card=(float)$v['Kart'];$iban=(float)$v['IBAN'];$note=mb_substr(trim((string)($data['note']??'')),0,400,'UTF-8');
        $result=api_write(function($db)use($day,$cash,$card,$iban,$note,$u){
            $expected=api_cash_expected($db,$day)['expected'];
            $sql='INSERT INTO cash_closings(closing_date,expected_cash,counted_cash,expected_card,counted_card,expected_iban,counted_iban,note,user_id) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE expected_cash=VALUES(expected_cash),counted_cash=VALUES(counted_cash),expected_card=VALUES(expected_card),counted_card=VALUES(counted_card),expected_iban=VALUES(expected_iban),counted_iban=VALUES(counted_iban),note=VALUES(note),user_id=VALUES(user_id),created=CURRENT_TIMESTAMP';
            $db->prepare($sql)->execute([$day,$expected['Nakit'],$cash,$expected['Kart'],$card,$expected['IBAN'],$iban,$note,$u['id']]);
            ilmek_log($u,'Cash close saved',$day);
            return ['expected'=>$expected,'difference'=>['Nakit'=>$cash-$expected['Nakit'],'Kart'=>$card-$expected['Kart'],'IBAN'=>$iban-$expected['IBAN']]];
        });
        ilmek_json(['ok'=>true,'date'=>$day]+$result);
    }
    if($route==='/api/settings'){
        if($u['role']!=='admin')ilmek_json(['error'=>'Only an administrator can change settings.'],403);
        $name=trim((string)($data['shop_name']??''));$threshold=filter_var($data['low_stock_default']??null,FILTER_VALIDATE_INT);$theme=(string)($data['default_theme']??'');$period=filter_var($data['report_period']??null,FILTER_VALIDATE_INT);
        if($name===''||mb_strlen($name,'UTF-8')>80)throw new InvalidArgumentException('Shop name must be 1-80 characters.');
        if($threshold===false||$threshold<0||$threshold>100000)throw new InvalidArgumentException('Low stock threshold must be between 0 and 100000.');
        if(!in_array($theme,['sage','ocean','rose','plum','sand','night'],true))throw new InvalidArgumentException('Choose a valid theme.');if(!in_array($period,[1,7,30],true))throw new InvalidArgumentException('Choose a valid report period.');
        api_write(function($db)use($name,$threshold,$theme,$period,$u){foreach(['shop_name'=>$name,'low_stock_default'=>(string)$threshold,'default_theme'=>$theme,'report_period'=>(string)$period] as $key=>$value){$q=$db->prepare('INSERT INTO settings(setting_key,value_text) VALUES(?,?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)');$q->execute([$key,$value]);}ilmek_log($u,'Shop settings updated',$name);return true;});
        ilmek_json(['ok'=>true,'settings'=>['shop_name'=>$name,'low_stock_default'=>$threshold,'default_theme'=>$theme,'report_period'=>$period]]);
    }
    if($route==='/api/restore'){
        if($u['role']!=='admin')ilmek_json(['error'=>'Yedeği yalnızca yönetici geri yükleyebilir'],403);
        $payload=$data['backup']??null;if(!is_array($payload)||!isset($payload['products'],$payload['sales']))throw new InvalidArgumentException('Yedek dosyası biçimi geçersiz.');
        ilmek_backup(false);
        $tables=['products','sales','sale_items','sale_returns','sale_return_items','expenses','stock_log','suppliers','purchases','purchase_items','supplier_payments','cash_closings','stock_counts','stock_count_items','activity_log','settings'];
        $db->beginTransaction();
        try{
            foreach(['sale_return_items','sale_returns','sale_items','sales','expenses','stock_log','purchase_items','purchases','supplier_payments','cash_closings','stock_count_items','stock_counts','suppliers','products','settings','activity_log'] as $t)$db->exec('DELETE FROM '.$t);
            foreach($tables as $t){if(!isset($payload[$t])||!is_array($payload[$t]))continue;$cols=$db->query('SHOW COLUMNS FROM '.$t)->fetchAll(PDO::FETCH_COLUMN);foreach($payload[$t] as $row){if(!is_array($row))continue;$keys=array_values(array_intersect(array_keys($row),$cols));if(!$keys)continue;$quoted=implode(',',$keys);$marks=implode(',',array_fill(0,count($keys),'?'));$q=$db->prepare('INSERT INTO '.$t.' ('.$quoted.') VALUES('.$marks.')');$q->execute(array_map(fn($k)=>$row[$k],$keys));}}
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        if(!empty($payload['images'])&&is_array($payload['images'])){$dir=ilmek_data_dir().'/images';if(!is_dir($dir))mkdir($dir,0700,true);foreach($payload['images'] as $file=>$encoded)if(preg_match('/^[a-f0-9]{32}\.(webp|jpg|png)$/',$file)&&is_string($encoded)){$bytes=base64_decode($encoded,true);$ok=$bytes!==false&&(str_ends_with($file,'.webp')?substr($bytes,0,4)==='RIFF'&&substr($bytes,8,4)==='WEBP':(str_ends_with($file,'.jpg')?substr($bytes,0,3)==="\xFF\xD8\xFF":substr($bytes,0,8)===("\x89PNG\r\n\x1a\n")));if($ok)file_put_contents($dir.'/'.$file,$bytes,LOCK_EX);}}
        ilmek_log($u,'Yedek geri yüklendi',(string)($payload['backup_created']??'Dosyadan'));ilmek_json(['ok'=>true]);
    }
    if($route==='/api/logout') {
        $token=(string)($_COOKIE['ilmek_session']??'');if($token!=='')$db->prepare('DELETE FROM sessions WHERE token_hash=?')->execute([hash('sha256',$token)]);api_cookie_expire();ilmek_json(['ok'=>true]);
    }
    if($route==='/api/password') {
        $q=$db->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$u['id']]);$row=$q->fetch();
        if(!$row||!password_verify((string)($data['current']??''),$row['password_hash']))throw new InvalidArgumentException('Mevcut parola doğru değil.');
        $new=(string)($data['password']??'');if(strlen($new)<10||strlen($new)>256)throw new InvalidArgumentException('Yeni parola 10–256 karakter olmalı.');
        api_write(function($db)use($u,$new){$db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$u['id']]);$db->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$u['id']]);ilmek_log($u,'Kendi parolasını değiştirdi');return true;});api_cookie_expire();ilmek_json(['ok'=>true]);
    }
    if(preg_match('#^/api/sales/(\d+)/cancel$#',$route,$m)) {
        if($u['role']!=='admin')ilmek_json(['error'=>'Satış iptalini yalnızca yönetici yapabilir'],403);$sid=(int)$m[1];
        api_write(function($db)use($sid,$data,$u){$q=$db->prepare('SELECT status FROM sales WHERE id=? FOR UPDATE');$q->execute([$sid]);$sale=$q->fetch();if(!$sale||$sale['status']!=='completed')throw new InvalidArgumentException('Satış bulunamadı veya zaten iptal edilmiş.');$q=$db->prepare('SELECT id,product_id,qty FROM sale_items WHERE sale_id=?');$q->execute([$sid]);foreach($q->fetchAll() as $i)if($i['product_id']!==null){$r=$db->prepare('SELECT COALESCE(SUM(qty),0) FROM sale_return_items WHERE sale_item_id=?');$r->execute([(int)$i['id']]);$remaining=max(0,(int)$i['qty']-(int)$r->fetchColumn());if($remaining){$db->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$remaining,(int)$i['product_id']]);$it=$db->prepare('SELECT name,unit_cost FROM sale_items WHERE id=?');$it->execute([(int)$i['id']]);$item=$it->fetch();$db->prepare('INSERT INTO stock_log(product_id,name,qty,unit_cost,note,user_id) VALUES(?,?,?,?,?,?)')->execute([(int)$i['product_id'],$item['name'],$remaining,$item['unit_cost'],'Satış iptali #'.$sid,$u['id']]);}}$reason=trim((string)($data['reason']??''))?:'Hatalı satış';$db->prepare("UPDATE sales SET status='cancelled',cancel_reason=? WHERE id=?")->execute([$reason,$sid]);ilmek_log($u,'Satış iptal edildi',"#$sid · $reason");return true;});ilmek_json(['ok'=>true]);
    }
    if(preg_match('#^/api/products/(\d+)$#',$route,$m) && $method==='DELETE') {
        if($u['role']!=='admin')ilmek_json(['error'=>'Only an administrator can delete products'],403);$pid=(int)$m[1];
        api_write(function($db)use($pid,$u){$q=$db->prepare('SELECT name,stock FROM products WHERE id=? FOR UPDATE');$q->execute([$pid]);$p=$q->fetch();if(!$p)throw new InvalidArgumentException('Product not found');$q=$db->prepare('SELECT (SELECT COUNT(*) FROM sale_items WHERE product_id=?)+(SELECT COUNT(*) FROM purchase_items WHERE product_id=?)');$q->execute([$pid,$pid]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('This product has sales or purchase history and cannot be deleted.');$db->prepare('DELETE FROM stock_log WHERE product_id=?')->execute([$pid]);$db->prepare('DELETE FROM products WHERE id=?')->execute([$pid]);ilmek_log($u,'Product deleted',$p['name']);return true;});ilmek_json(['ok'=>true]);
    }
    if(preg_match('#^/api/products/(\d+)$#',$route,$m)) {
        $pid=(int)$m[1];$r=ilmek_rate();
        if(($data['rate_day']??'')!==$r['snapshot_day'])throw new InvalidArgumentException('Günlük kur değişti; fiyatı kontrol edip tekrar kaydedin.');
        $name=trim((string)($data['name']??''));$variant=trim((string)($data['variant']??''));$barcode=trim((string)($data['barcode']??''));$barcode=$barcode===''?null:$barcode;$priceTl=(float)($data['price_tl']??-1);$low=filter_var($data['low_stock']??5,FILTER_VALIDATE_INT);
        if($name===''||$priceTl<0||$low===false||$low<0||$r['value']<=0)throw new InvalidArgumentException('Ürün bilgilerini kontrol edin.');
        $image='';if(!empty($data['image']))$image=ilmek_save_image((string)$data['image']);
        if($barcode!==null){$q=$db->prepare('SELECT id FROM products WHERE barcode=? AND id<>?');$q->execute([$barcode,$pid]);if($q->fetch())throw new InvalidArgumentException('Bu barkod başka bir üründe kayıtlı.');}
        api_write(function($db)use($pid,$name,$variant,$barcode,$priceTl,$low,$r,$data,$image,$u){
            $q=$db->prepare('SELECT * FROM products WHERE id=? FOR UPDATE');$q->execute([$pid]);$p=$q->fetch();if(!$p)throw new InvalidArgumentException('Ürün bulunamadı.');$supplier=trim((string)($data['supplier_name']??''));$supplierId=null;if($supplier!==''){$q=$db->prepare('SELECT id FROM suppliers WHERE name=? LIMIT 1');$q->execute([$supplier]);$supplierId=$q->fetchColumn();if(!$supplierId){$db->prepare('INSERT INTO suppliers(name) VALUES(?)')->execute([$supplier]);$supplierId=(int)$db->lastInsertId();}}
            $cost=(float)$p['cost'];$costUsd=(float)$p['cost_usd'];
            if($u['role']==='admin'&&array_key_exists('cost_tl',$data)){ $cost=(float)$data['cost_tl'];if($cost<0)throw new InvalidArgumentException('Alış maliyetini kontrol edin.');$costUsd=$cost/(float)$r['value']; }
            $category=trim((string)($data['category']??''));$priceUsd=$priceTl/(float)$r['value'];
            if($image!=='')$db->prepare('UPDATE products SET name=?,variant=?,barcode=?,supplier_id=?,category=?,cost=?,cost_usd=?,price_usd=?,low_stock=?,image=? WHERE id=?')->execute([$name,$variant,$barcode,$supplierId,$category,$cost,$costUsd,$priceUsd,(int)$low,$image,$pid]);
            else $db->prepare('UPDATE products SET name=?,variant=?,barcode=?,supplier_id=?,category=?,cost=?,cost_usd=?,price_usd=?,low_stock=? WHERE id=?')->execute([$name,$variant,$barcode,$supplierId,$category,$cost,$costUsd,$priceUsd,(int)$low,$pid]);
            ilmek_log($u,'Ürün bilgisi düzenlendi',$name);return true;
        });
        ilmek_json(['ok'=>true]);
    }
    if($route==='/api/products') {
        $r=ilmek_rate();if(($data['rate_day']??'')!==$r['snapshot_day'])throw new InvalidArgumentException('Günlük kur değişti; fiyatı kontrol edip tekrar kaydedin.');
        $name=trim((string)($data['name']??''));$variant=trim((string)($data['variant']??''));$barcode=trim((string)($data['barcode']??''))?:null;$stock=filter_var($data['stock']??0,FILTER_VALIDATE_INT);$cost=(float)($data['cost']??0);$priceTl=(float)($data['price_tl']??0);$paid=(float)($data['paid']??0);$payment=(string)($data['payment']??'Debt');$supplier=trim((string)($data['supplier_name']??''));$debt='Bor'.chr(0xC3).chr(0xA7);if($payment==='Debt')$payment=$debt;
        if($name===''||$stock===false||$stock<0||$cost<0||$priceTl<0||$paid<0||$paid>(int)$stock*$cost||!in_array($payment,['Nakit','Kart','IBAN',$debt],true)||($paid>0&&$payment===$debt)||$r['value']<=0)throw new InvalidArgumentException('Ürün bilgilerini kontrol edin.');
        if($barcode!==null){$q=$db->prepare('SELECT id FROM products WHERE barcode=?');$q->execute([$barcode]);if($q->fetch())throw new InvalidArgumentException('Bu barkod başka bir üründe kayıtlı.');}$costUsd=$cost/$r['value'];$priceUsd=$priceTl/$r['value'];$image='';if(!empty($data['image']))$image=ilmek_save_image((string)$data['image']);
        api_write(function($db)use($name,$variant,$barcode,$stock,$cost,$costUsd,$priceUsd,$data,$image,$u,$r,$paid,$payment,$supplier,$debt){$supplierId=null;if($supplier!==''){$q=$db->prepare('SELECT id FROM suppliers WHERE name=? LIMIT 1');$q->execute([$supplier]);$supplierId=$q->fetchColumn();if(!$supplierId){$db->prepare('INSERT INTO suppliers(name) VALUES(?)')->execute([$supplier]);$supplierId=(int)$db->lastInsertId();}}$q=$db->prepare('INSERT INTO products(name,variant,barcode,supplier_id,category,stock,cost,cost_usd,price_usd,low_stock,image) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$name,$variant,$barcode,$supplierId,trim((string)($data['category']??'')),(int)$stock,$cost,$costUsd,$priceUsd,max(0,(int)($data['low_stock']??5)),$image]);$id=(int)$db->lastInsertId();if($stock){$db->prepare('INSERT INTO stock_log(product_id,name,qty,unit_cost,note,user_id) VALUES(?,?,?,?,?,?)')->execute([$id,$name,$stock,$cost,'Initial stock',$u['id']]);$total=(int)$stock*$cost;$db->prepare('INSERT INTO purchases(supplier_id,supplier_name,total,paid,payment,exchange_rate,rate_date,note,user_id) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$supplierId,$supplier,$total,$paid,$paid>0?$payment:$debt,$r['value'],$r['date'],'Initial stock',$u['id']]);$purchaseId=(int)$db->lastInsertId();$db->prepare("INSERT INTO purchase_items(purchase_id,product_id,name,variant,qty,unit_cost,unit_cost_usd) VALUES(?,?,?,'',?,?,?)")->execute([$purchaseId,$id,$name,$stock,$cost,$costUsd]);}ilmek_log($u,'Product created',$name);return true;});ilmek_json(['ok'=>true]);
    }
    if($route==='/api/stock') {
        $pid=(int)($data['product_id']??0);$qty=filter_var($data['qty']??0,FILTER_VALIDATE_INT);if(!$pid||$qty===false||$qty<1)throw new InvalidArgumentException('Ürün ve geçerli adet seçin.');
        $r=ilmek_rate();$cost=(float)($data['cost']??-1);$price=null;if($cost<0)throw new InvalidArgumentException('Alış maliyetini kontrol edin.');if(array_key_exists('price_tl',$data)&&$data['price_tl']!==null){if(($data['rate_day']??'')!==$r['snapshot_day'])throw new InvalidArgumentException('Günlük kur değişti; satış fiyatını kontrol edip tekrar kaydedin.');$price=(float)$data['price_tl']/$r['value'];}
        api_write(function($db)use($pid,$qty,$cost,$price,$r,$u){$q=$db->prepare('SELECT * FROM products WHERE id=? FOR UPDATE');$q->execute([$pid]);$p=$q->fetch();if(!$p)throw new InvalidArgumentException('Ürün bulunamadı.');$newstock=(int)$p['stock']+(int)$qty;$avg=$newstock?(((float)$p['stock']*(float)$p['cost']+(int)$qty*$cost)/$newstock):$cost;$avgUsd=$newstock?(((float)$p['stock']*(float)$p['cost_usd']+(int)$qty*$cost/(float)$r['value'])/$newstock):$cost/(float)$r['value'];$db->prepare('UPDATE products SET stock=?,cost=?,cost_usd=?,price_usd=COALESCE(?,price_usd) WHERE id=?')->execute([$newstock,$avg,$avgUsd,$price,$pid]);$db->prepare('INSERT INTO stock_log(product_id,name,qty,unit_cost,note,user_id) VALUES(?,?,?,?,?,?)')->execute([$pid,$p['name'],$qty,$cost,'Yeni alım',$u['id']]);ilmek_log($u,'Stok alımı',$p['name'].' · '.$qty.' adet · '.$cost.' TL/adet');return true;});ilmek_json(['ok'=>true]);
    }
    if($route==='/api/sales') {
        $snapshot=ilmek_rate();if(($data['rate_day']??'')!==$snapshot['snapshot_day'])throw new InvalidArgumentException('Günlük kur yenilendi; sepet fiyatlarını kontrol edip tekrar deneyin.');$payment=(string)($data['payment']??'');$items=$data['items']??[];if(!in_array($payment,['Nakit','Kart','IBAN'],true)||!is_array($items)||!$items)throw new InvalidArgumentException('Satış bilgileri veya günlük kur eksik.');
        $result=api_write(function($db)use($items,$payment,$snapshot,$u){$checked=[];$total=0;foreach($items as $item){$pid=(int)($item['product_id']??0);$qty=filter_var($item['qty']??0,FILTER_VALIDATE_INT);$q=$db->prepare('SELECT * FROM products WHERE id=? FOR UPDATE');$q->execute([$pid]);$p=$q->fetch();if(!$p||$qty===false||$qty<1||(int)$p['stock']<$qty)throw new InvalidArgumentException(($p?$p['name']:'Ürün').' için stok yetersiz.');$unit=(int)round((float)$p['price_usd']*(float)$snapshot['value'],0,PHP_ROUND_HALF_UP);$checked[]=[$p,(int)$qty,$unit];$total+=(int)$qty*$unit;}$db->prepare('INSERT INTO sales(total,payment,user_id,exchange_rate,rate_date) VALUES(?,?,?,?,?)')->execute([$total,$payment,$u['id'],$snapshot['value'],$snapshot['date']]);$sid=(int)$db->lastInsertId();foreach($checked as [$p,$qty,$unit]){$label=trim((string)($p['variant']??''))!==''?$p['name'].' · '.$p['variant']:$p['name'];$db->prepare('INSERT INTO sale_items(sale_id,product_id,name,qty,unit_price,unit_cost) VALUES(?,?,?,?,?,?)')->execute([$sid,$p['id'],$label,$qty,$unit,$p['cost']]);$db->prepare('UPDATE products SET stock=stock-? WHERE id=?')->execute([$qty,$p['id']]);}ilmek_log($u,'Satış tamamlandı',"Satış #$sid · $total TL · $payment · USD/TRY {$snapshot['value']}");return ['sale_id'=>$sid,'total'=>$total];});ilmek_json(['ok'=>true]+$result+['rate'=>$snapshot['value'],'rate_date'=>$snapshot['date'],'rate_day'=>$snapshot['snapshot_day']]);
    }
    if(preg_match('#^/api/expenses/(\d+)$#',$route,$m)){
        if($u['role']!=='admin')ilmek_json(['error'=>'Gider kayıtlarını yalnızca yönetici düzenleyebilir'],403);$eid=(int)$m[1];
        if($method==='DELETE'){$q=$db->prepare('DELETE FROM expenses WHERE id=?');$q->execute([$eid]);if(!$q->rowCount())throw new InvalidArgumentException('Gider kaydı bulunamadı.');ilmek_log($u,'Gider kaydı silindi',(string)$eid);ilmek_json(['ok'=>true]);}
        $amount=(int)round((float)($data['amount']??0),0,PHP_ROUND_HALF_UP);$payment=(string)($data['payment']??'');$title=trim((string)($data['title']??''))?:'Gider';if($amount<1||!in_array($payment,['Nakit','Kart','IBAN'],true))throw new InvalidArgumentException('Gider tutarını ve ödeme biçimini kontrol edin.');$q=$db->prepare('UPDATE expenses SET title=?,amount=?,payment=? WHERE id=?');$q->execute([$title,$amount,$payment,$eid]);if(!$q->rowCount()){$check=$db->prepare('SELECT COUNT(*) FROM expenses WHERE id=?');$check->execute([$eid]);if(!(int)$check->fetchColumn())throw new InvalidArgumentException('Gider kaydı bulunamadı.');}ilmek_log($u,'Gider kaydı düzenlendi',"$title · $amount TL");ilmek_json(['ok'=>true]);
    }
    if($route==='/api/expenses') {
        $amount=(int)round((float)($data['amount']??0),0,PHP_ROUND_HALF_UP);$payment=(string)($data['payment']??'');$title=trim((string)($data['title']??''))?:'Gider';if($amount<1||!in_array($payment,['Nakit','Kart','IBAN'],true))throw new InvalidArgumentException('Gider tutarı ve ödeme biçimini kontrol edin.');api_write(function($db)use($amount,$payment,$title,$u){$db->prepare('INSERT INTO expenses(title,amount,payment,user_id) VALUES(?,?,?,?)')->execute([$title,$amount,$payment,$u['id']]);ilmek_log($u,'Gider eklendi',"$title · $amount TL");return true;});ilmek_json(['ok'=>true]);
    }
    if($route==='/api/stock-adjustments') {
        if($u['role']!=='admin')ilmek_json(['error'=>'Stok düzeltmesini yalnızca yönetici yapabilir'],403);$pid=(int)($data['product_id']??0);$qty=filter_var($data['qty']??0,FILTER_VALIDATE_INT);$note=trim((string)($data['note']??''));if(!$pid||$qty===false||$qty===0||$note==='')throw new InvalidArgumentException('Ürün, açıklama ve geçerli adet girin.');api_write(function($db)use($pid,$qty,$note,$u){$q=$db->prepare('SELECT * FROM products WHERE id=? FOR UPDATE');$q->execute([$pid]);$p=$q->fetch();if(!$p||(int)$p['stock']+(int)$qty<0)throw new InvalidArgumentException('Ürün bulunamadı veya stok eksiye düşemez.');$db->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$qty,$pid]);$db->prepare('INSERT INTO stock_log(product_id,name,qty,unit_cost,note,user_id) VALUES(?,?,?,?,?,?)')->execute([$pid,$p['name'],$qty,$p['cost'],$note,$u['id']]);ilmek_log($u,'Stok düzeltildi',$p['name'].' · '.sprintf('%+d',(int)$qty).' adet · '.$note);return true;});ilmek_json(['ok'=>true]);
    }
    if($route==='/api/users') {
        if($u['role']!=='admin')ilmek_json(['error'=>'Yalnızca yönetici hesap açabilir'],403);$username=trim((string)($data['username']??''));$name=trim((string)($data['name']??''));$password=(string)($data['password']??'');$role=(string)($data['role']??'cashier');if(!preg_match('/^[A-Za-z0-9_.-]{3,32}$/',$username))throw new InvalidArgumentException('Kullanıcı adı 3–32 karakter olmalı.');if(strlen($password)<10||strlen($password)>256)throw new InvalidArgumentException('Parola 10–256 karakter olmalı.');if(!in_array($role,['cashier','admin'],true))throw new InvalidArgumentException('Rol seçin.');api_write(function($db)use($username,$name,$password,$role,$u){$db->prepare('INSERT INTO users(username,name,role,password_hash) VALUES(?,?,?,?)')->execute([$username,$name?:$username,$role,password_hash($password,PASSWORD_DEFAULT)]);ilmek_log($u,'Yeni hesap açıldı',$username.' · '.$role);return true;});ilmek_json(['ok'=>true]);
    }
    if(preg_match('#^/api/users/(\d+)/password$#',$route,$m)) {
        if($u['role']!=='admin')ilmek_json(['error'=>'Yalnızca yönetici parola yenileyebilir'],403);$id=(int)$m[1];$pw=(string)($data['password']??'');if(strlen($pw)<10||strlen($pw)>256)throw new InvalidArgumentException('Parola 10–256 karakter olmalı.');api_write(function($db)use($id,$pw,$u){$q=$db->prepare('SELECT id FROM users WHERE id=?');$q->execute([$id]);if(!$q->fetch())throw new InvalidArgumentException('Hesap bulunamadı.');$db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pw,PASSWORD_DEFAULT),$id]);$db->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$id]);ilmek_log($u,'Parola yenilendi',(string)$id);return true;});ilmek_json(['ok'=>true]);
    }
    if(preg_match('#^/api/users/(\d+)/toggle$#',$route,$m)) {
        if($u['role']!=='admin')ilmek_json(['error'=>'Yalnızca yönetici hesapları yönetebilir'],403);$id=(int)$m[1];if($id===$u['id'])throw new InvalidArgumentException('Kendi hesabınızı buradan kapatamazsınız.');api_write(function($db)use($id,$u){$q=$db->prepare('SELECT role,active FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Hesap bulunamadı.');if($row['role']==='admin'&&(int)$row['active']===1&&(int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn()<=1)throw new InvalidArgumentException('En az bir etkin yönetici hesabı kalmalı.');$active=1-(int)$row['active'];$db->prepare('UPDATE users SET active=? WHERE id=?')->execute([$active,$id]);if(!$active)$db->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$id]);ilmek_log($u,$active?'Hesap etkinleştirildi':'Hesap kapatıldı',(string)$id);return true;});ilmek_json(['ok'=>true]);
    }
    ilmek_json(['error'=>'Bulunamadı'],404);
} catch(InvalidArgumentException|DomainException $e) {
    ilmek_json(['error'=>$e->getMessage()],400);
} catch(PDOException $e) {
    if($e->getCode()==='23000')ilmek_json(['error'=>'Bu kullanıcı adı kullanımda'],409);
    ilmek_request_log('Database/API failure '.$method.' '.$route,$e);ilmek_json(['error'=>'Veritabanı işlemi tamamlanamadı. Ayarları kontrol edin.'],500);
} catch(Throwable $e) {
    ilmek_request_log('Unhandled API failure',$e);ilmek_json(['error'=>'Sunucu hatası; lütfen daha sonra tekrar deneyin.'],500);
}
