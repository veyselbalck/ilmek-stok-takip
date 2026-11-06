"""Self-hosted inventory and POS panel. Python 3.11+, SQLite, no third-party packages."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from http.cookies import SimpleCookie
from pathlib import Path
import base64, hashlib, hmac, json, os, re, secrets, sqlite3, threading, time
import urllib.request, urllib.parse, webbrowser, zipfile
import xml.etree.ElementTree as ET
from decimal import Decimal, ROUND_HALF_UP
from zoneinfo import ZoneInfo

ROOT=Path(__file__).resolve().parent
# cPanel Application Manager may not expose per-app environment variables.
# Load a private KEY=VALUE file from the app directory before resolving storage.
config=ROOT/'.ilmek.env'
if config.is_file():
    for line in config.read_text(encoding='utf-8').splitlines():
        line=line.strip()
        if not line or line.startswith('#') or '=' not in line: continue
        key,value=line.split('=',1);key=key.strip();value=value.strip()
        if re.fullmatch(r'[A-Z_][A-Z0-9_]*',key): os.environ.setdefault(key,value)
DATA=Path(os.environ.get('ILMEK_DATA_DIR',str(ROOT))).resolve(); DB=DATA/'stok.db'; BACKUPS=DATA/'backups'; IMAGES=DATA/'images'
lock=threading.Lock()
login_failures={}

def db():
    c=sqlite3.connect(DB,timeout=20); c.row_factory=sqlite3.Row; c.execute('PRAGMA journal_mode=WAL'); c.execute('PRAGMA foreign_keys=ON')
    c.executescript('''CREATE TABLE IF NOT EXISTS products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,category TEXT DEFAULT '',stock INTEGER NOT NULL DEFAULT 0,cost REAL NOT NULL DEFAULT 0,cost_usd REAL NOT NULL DEFAULT 0,price_usd REAL NOT NULL DEFAULT 0,low_stock INTEGER NOT NULL DEFAULT 5,image TEXT DEFAULT '',created TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS sales(id INTEGER PRIMARY KEY,created TEXT DEFAULT CURRENT_TIMESTAMP,total REAL NOT NULL,payment TEXT NOT NULL,user_id INTEGER,status TEXT NOT NULL DEFAULT 'completed',cancel_reason TEXT DEFAULT '',exchange_rate REAL DEFAULT 0,rate_date TEXT DEFAULT '');
    CREATE TABLE IF NOT EXISTS sale_items(id INTEGER PRIMARY KEY,sale_id INTEGER REFERENCES sales(id),product_id INTEGER REFERENCES products(id),name TEXT,qty INTEGER,unit_price REAL,unit_cost REAL);
    CREATE TABLE IF NOT EXISTS expenses(id INTEGER PRIMARY KEY,created TEXT DEFAULT CURRENT_TIMESTAMP,title TEXT,amount REAL,payment TEXT,user_id INTEGER);
    CREATE TABLE IF NOT EXISTS stock_log(id INTEGER PRIMARY KEY,created TEXT DEFAULT CURRENT_TIMESTAMP,product_id INTEGER,name TEXT,qty INTEGER,unit_cost REAL,note TEXT,user_id INTEGER);
    CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,username TEXT UNIQUE COLLATE NOCASE,name TEXT NOT NULL,role TEXT NOT NULL CHECK(role IN ('admin','cashier')),password_hash TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,created TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS sessions(token_hash TEXT PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id),created REAL NOT NULL,last REAL NOT NULL);
    CREATE TABLE IF NOT EXISTS activity_log(id INTEGER PRIMARY KEY,created TEXT DEFAULT CURRENT_TIMESTAMP,user_id INTEGER,action TEXT NOT NULL,details TEXT DEFAULT '');
    CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT);''')
    for table,col,typ in [('products','image',"TEXT DEFAULT ''"),('products','cost_usd','REAL NOT NULL DEFAULT 0'),('sales','user_id','INTEGER'),('sales','status',"TEXT NOT NULL DEFAULT 'completed'"),('sales','cancel_reason',"TEXT DEFAULT ''"),('sales','exchange_rate','REAL DEFAULT 0'),('sales','rate_date',"TEXT DEFAULT ''"),('stock_log','user_id','INTEGER'),('expenses','user_id','INTEGER')]:
        cols={x[1] for x in c.execute('PRAGMA table_info('+table+')')}
        if col not in cols: c.execute('ALTER TABLE '+table+' ADD COLUMN '+col+' '+typ)
    return c

def hash_pw(value,salt=None):
    if len(value)<10 or len(value)>256: raise ValueError('Parola 10–256 karakter aralığında olmalı')
    salt=salt or secrets.token_bytes(16); return salt.hex()+':'+hashlib.pbkdf2_hmac('sha256',value.encode(),salt,600000).hex()
def log(c,user,action,details=''): c.execute('INSERT INTO activity_log(user_id,action,details) VALUES(?,?,?)',(user['id'],action,details[:400]))
def make_session(user_id):
    token=secrets.token_urlsafe(32);now=time.time();c=db();c.execute('INSERT INTO sessions(token_hash,user_id,created,last) VALUES(?,?,?,?)',(hashlib.sha256(token.encode()).hexdigest(),user_id,now,now));c.commit();c.close();return token
def user_for(handler):
    cookie=SimpleCookie(); cookie.load(handler.headers.get('Cookie','')); morsel=cookie.get('ilmek_session'); token=morsel.value if morsel else ''
    if not token:return None
    key=hashlib.sha256(token.encode()).hexdigest();now=time.time();c=db();sess=c.execute('SELECT user_id,created,last FROM sessions WHERE token_hash=?',(key,)).fetchone()
    if not sess:c.close();return None
    if now-sess['last']>12*3600 or now-sess['created']>7*24*3600:c.execute('DELETE FROM sessions WHERE token_hash=?',(key,));c.commit();c.close();return None
    row=c.execute('SELECT id,username,name,role,active FROM users WHERE id=?',(sess['user_id'],)).fetchone()
    if not row or not row['active']:c.execute('DELETE FROM sessions WHERE token_hash=?',(key,));c.commit();c.close();return None
    c.execute('UPDATE sessions SET last=? WHERE token_hash=?',(now,key));c.commit();c.close();return dict(row)
def backup():
    BACKUPS.mkdir(exist_ok=True)
    with lock:
        stamp=time.strftime('stok-%Y-%m-%d_%H-%M-%S'); temp=BACKUPS/(stamp+'.sqlite3'); path=BACKUPS/(stamp+'.zip'); c=db(); dst=sqlite3.connect(temp); c.backup(dst); dst.close(); c.close()
        with zipfile.ZipFile(path,'w',compression=zipfile.ZIP_DEFLATED) as z:
            z.write(temp,'stok.db')
            if IMAGES.exists():
                for image in IMAGES.glob('*.webp'): z.write(image,'images/'+image.name)
        temp.unlink(missing_ok=True)
    for old in sorted(BACKUPS.glob('stok-*.zip'),key=lambda x:x.stat().st_mtime,reverse=True)[30:]: old.unlink(missing_ok=True)
    return path,drive_upload(path)
def drive_upload(path):
    client=os.environ.get('GOOGLE_DRIVE_CLIENT_ID'); secret=os.environ.get('GOOGLE_DRIVE_CLIENT_SECRET'); refresh=os.environ.get('GOOGLE_DRIVE_REFRESH_TOKEN'); folder=os.environ.get('GOOGLE_DRIVE_FOLDER_ID')
    if not all((client,secret,refresh)): return {'enabled':False,'message':'Drive ayarlanmamış · yerel yedek aktif'}
    try:
        form=urllib.parse.urlencode({'client_id':client,'client_secret':secret,'refresh_token':refresh,'grant_type':'refresh_token'}).encode(); req=urllib.request.Request('https://oauth2.googleapis.com/token',data=form,headers={'Content-Type':'application/x-www-form-urlencoded'})
        with urllib.request.urlopen(req,timeout=15) as r: access=json.load(r)['access_token']
        meta={'name':path.name,'mimeType':'application/zip'}
        if folder: meta['parents']=[folder]
        boundary='ilmek-'+secrets.token_hex(12); body=b'--'+boundary.encode()+b'\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n'+json.dumps(meta).encode()+b'\r\n--'+boundary.encode()+b'\r\nContent-Type: application/zip\r\n\r\n'+path.read_bytes()+b'\r\n--'+boundary.encode()+b'--'
        req=urllib.request.Request('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name',data=body,headers={'Authorization':'Bearer '+access,'Content-Type':'multipart/related; boundary='+boundary})
        with urllib.request.urlopen(req,timeout=45) as r: result=json.load(r)
        return {'enabled':True,'message':'Drive yedeği tamamlandı','name':result.get('name')}
    except Exception as e: return {'enabled':True,'message':'Drive yüklenemedi; yerel yedek güvende','detail':str(e)[:180]}
def daily_backup():
    while True:
        time.sleep(3600)
        try:
            if time.time()-max((p.stat().st_mtime for p in BACKUPS.glob('stok-*.zip')),default=0)>22*3600: backup()
        except Exception: pass

rate_lock=threading.Lock()
def fetch_tcmb_rate():
    req=urllib.request.Request('https://www.tcmb.gov.tr/kurlar/today.xml',headers={'User-Agent':'IlmekPOS/1.0'})
    with urllib.request.urlopen(req,timeout=10) as r: root=ET.fromstring(r.read())
    node=root.find("Currency[@CurrencyCode='USD']")
    value=node.findtext('ForexSelling') if node is not None else None
    if not value: raise ValueError('TCMB USD döviz satış kuru XML içinde bulunamadı')
    d=root.attrib.get('Date','')
    if re.fullmatch(r'\d{2}/\d{2}/\d{4}',d):d=f'{d[6:10]}-{d[:2]}-{d[3:5]}'
    elif re.fullmatch(r'\d{2}\.\d{2}\.\d{4}',d):d=f'{d[6:10]}-{d[3:5]}-{d[:2]}'
    return float(value),d or time.strftime('%Y-%m-%d')

def daily_rate():
    day=time.strftime('%Y-%m-%d',time.gmtime(time.time()+3*3600))
    with rate_lock:
        c=db(); settings={x['key']:x['value'] for x in c.execute('SELECT key,value FROM settings WHERE key LIKE "rate%"')}
        if settings.get('rate_day')==day and settings.get('rate'):
            result={'value':float(settings['rate']),'date':settings.get('rate_date',''),'snapshot_day':day,'source':settings.get('rate_source','TCMB · Döviz satış'),'stale':settings.get('rate_stale')=='1'};c.close();return result
        old_value=settings.get('rate'); old_date=settings.get('rate_date',''); c.close()
        try:
            value,published=fetch_tcmb_rate(); source='TCMB · USD Döviz satış'; stale=False
        except Exception:
            value=float(old_value or 0); published=old_date or 'önbellek';source='Son kayıtlı kur · çevrimdışı yedek';stale=True
        c=db(); values={'rate':str(value),'rate_date':published,'rate_day':day,'rate_source':source,'rate_stale':'1' if stale else '0'}
        c.executemany("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",values.items());c.commit();c.close()
        return {'value':value,'date':published,'snapshot_day':day,'source':source,'stale':stale}

def round_tl(value):
    return int(Decimal(str(value)).quantize(Decimal('1'),rounding=ROUND_HALF_UP))

def rate():
    return daily_rate()

class Handler(BaseHTTPRequestHandler):
    def log_message(self,*a): pass
    def send(self,obj,status=200,headers=None):
        b=json.dumps(obj,ensure_ascii=False).encode(); self.send_response(status); self.send_header('Content-Type','application/json; charset=utf-8'); self.send_header('Content-Length',str(len(b))); self.send_header('Cache-Control','no-store'); self.send_header('X-Content-Type-Options','nosniff')
        for k,v in (headers or {}).items(): self.send_header(k,v)
        self.end_headers(); self.wfile.write(b)
    def body(self):
        n=int(self.headers.get('Content-Length',0))
        if n>5*1024*1024: raise ValueError('İstek boyutu çok büyük')
        return json.loads(self.rfile.read(n) or b'{}')
    def cookie(self,token):
        secure='; Secure' if self.headers.get('X-Forwarded-Proto')=='https' else ''
        return {'Set-Cookie':f'ilmek_session={token}; HttpOnly; SameSite=Strict; Path=/; Max-Age=604800{secure}'}
    def require(self,admin=False):
        u=user_for(self)
        if not u: self.send({'error':'Oturum açın'},401); return None
        if admin and u['role']!='admin': self.send({'error':'Bu bölüm yalnızca yöneticiye açık'},403); return None
        return u
    def do_GET(self):
        route=self.path.split('?')[0]
        if route in ('/','/app.js','/style.css'):
            f=ROOT / {'/':'index.html','/app.js':'app.js','/style.css':'style.css'}[route]; b=f.read_bytes(); self.send_response(200); self.send_header('Content-Type','text/html; charset=utf-8' if f.suffix=='.html' else 'text/javascript; charset=utf-8' if f.suffix=='.js' else 'text/css; charset=utf-8'); self.send_header('Content-Length',str(len(b))); self.send_header('X-Content-Type-Options','nosniff'); self.end_headers(); self.wfile.write(b); return
        if route=='/api/bootstrap':
            c=db(); need=c.execute("SELECT count(*) FROM users WHERE role='admin' AND active=1").fetchone()[0]==0; c.close(); return self.send({'needs_setup':need,'requires_setup_token':bool(os.environ.get('ILMEK_SETUP_TOKEN'))})
        if route=='/api/rate':
            x=rate()
            if x.get('value'):
                c=db(); c.execute("INSERT INTO settings(key,value) VALUES('rate',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",(str(x['value']),)); c.commit(); c.close()
            return self.send(x)
        u=self.require()
        if not u:return
        if route=='/api/me': return self.send({'user':u})
        if route=='/api/data':
            c=db(); products=[dict(x) for x in c.execute('SELECT * FROM products ORDER BY name COLLATE NOCASE')]
            sql='SELECT id,created,total,payment,user_id,status,cancel_reason,exchange_rate,rate_date FROM sales'; params=()
            if u['role']!='admin': sql+=' WHERE user_id=?'; params=(u['id'],)
            sales=[dict(x) for x in c.execute(sql+' ORDER BY id DESC LIMIT 100',params)]; ids=[x['id'] for x in sales]
            items=[]
            if ids:
                marks=','.join('?' for _ in ids); items=[dict(x) for x in c.execute(f'SELECT * FROM sale_items WHERE sale_id IN ({marks}) ORDER BY id DESC LIMIT 500',ids)]
            expenses=[dict(x) for x in c.execute('SELECT * FROM expenses ORDER BY id DESC LIMIT 100')] if u['role']=='admin' else []
            logrows=[dict(x) for x in c.execute('SELECT s.*,u.name AS by_name FROM stock_log s LEFT JOIN users u ON u.id=s.user_id ORDER BY s.id DESC LIMIT 50')]
            activity=[dict(x) for x in c.execute('SELECT a.id,a.created,u.name,a.action,a.details FROM activity_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')] if u['role']=='admin' else []
            staff=[dict(x) for x in c.execute('SELECT id,name,role FROM users')] if u['role']=='admin' else []
            c.close()
            if u['role']!='admin':
                for p in products: p.pop('cost',None)
                for p in products: p.pop('cost_usd',None)
                for i in items: i.pop('unit_cost',None)
                for row in logrows: row.pop('unit_cost',None)
            return self.send({'products':products,'sales':sales,'sale_items':items,'expenses':expenses,'stock_log':logrows,'activity':activity,'users':staff,'user':u})
        if route=='/api/users':
            if u['role']!='admin':return self.send({'error':'Bu bölüm yalnızca yöneticiye açık'},403)
            c=db(); out=[dict(x) for x in c.execute('SELECT id,username,name,role,active,created FROM users ORDER BY role,name')]; c.close(); return self.send({'users':out})
        if route=='/api/backup' or route=='/api/export':
            if u['role']!='admin':return self.send({'error':'Bu bölüm yalnızca yöneticiye açık'},403)
            if route=='/api/backup':
                path,cloud=backup(); return self.send({'ok':True,'count':len(list(BACKUPS.glob('stok-*.sqlite3'))),'drive':cloud})
            c=db(); out={t:[dict(x) for x in c.execute('SELECT * FROM '+t)] for t in ['products','sales','sale_items','expenses','stock_log','activity_log','settings']}; c.close(); return self.send(out)
        m=re.fullmatch(r'/images/([a-f0-9]{32}\.webp)',route)
        if m:
            if not self.require():return
            p=IMAGES/m.group(1)
            if not p.exists():return self.send({'error':'Görsel bulunamadı'},404)
            b=p.read_bytes(); self.send_response(200); self.send_header('Content-Type','image/webp'); self.send_header('Content-Length',str(len(b))); self.send_header('Cache-Control','private, max-age=3600'); self.end_headers(); self.wfile.write(b); return
        return self.send({'error':'Bulunamadı'},404)
    def do_POST(self):
        route=self.path.split('?')[0]
        try:data=self.body()
        except Exception as e:return self.send({'error':str(e)},400)
        if route=='/api/setup-admin':
            expected=os.environ.get('ILMEK_SETUP_TOKEN','')
            if expected and not hmac.compare_digest(str(data.get('setup_token','')),expected):return self.send({'error':'Kurulum anahtarı yanlış'},403)
            username=str(data.get('username','')).strip(); name=str(data.get('name','')).strip(); password=str(data.get('password',''))
            if len(password)<10:return self.send({'error':'Yönetici parolası en az 10 karakter olmalı'},400)
            if not re.fullmatch(r'[A-Za-z0-9_.-]{3,32}',username):return self.send({'error':'Kullanıcı adı 3–32 karakter olmalı; harf, sayı, nokta, tire kullanın'},400)
            c=db()
            if c.execute("SELECT count(*) FROM users WHERE role='admin' AND active=1").fetchone()[0]:c.close();return self.send({'error':'Yönetici hesabı zaten kurulu'},409)
            try:
                cur=c.execute("INSERT INTO users(username,name,role,password_hash) VALUES(?,?,'admin',?)",(username,name or username,hash_pw(password))); u={'id':cur.lastrowid,'username':username,'name':name or username,'role':'admin','active':1};log(c,u,'Yönetici hesabı oluşturuldu');c.commit();c.close()
            except sqlite3.IntegrityError:c.close();return self.send({'error':'Bu kullanıcı adı kullanımda'},409)
            token=make_session(u['id']);return self.send({'user':u},headers=self.cookie(token))
        if route=='/api/login':
            ip=self.client_address[0]; failures=[t for t in login_failures.get(ip,[]) if time.time()-t<900]
            if len(failures)>=8:return self.send({'error':'Çok sayıda deneme oldu. 15 dakika sonra tekrar deneyin.'},429)
            c=db(); u=c.execute('SELECT * FROM users WHERE username=? COLLATE NOCASE AND active=1',(str(data.get('username','')).strip(),)).fetchone(); c.close()
            valid=False
            if u:
                try:salt,expected=u['password_hash'].split(':'); valid=hmac.compare_digest(hashlib.pbkdf2_hmac('sha256',str(data.get('password','')).encode(),bytes.fromhex(salt),600000).hex(),expected)
                except Exception:pass
            if not valid:login_failures[ip]=failures+[time.time()];return self.send({'error':'Kullanıcı adı veya parola hatalı'},401)
            login_failures.pop(ip,None)
            token=make_session(u['id']);return self.send({'user':{'id':u['id'],'username':u['username'],'name':u['name'],'role':u['role']}},headers=self.cookie(token))
        u=self.require()
        if not u:return
        c=db()
        try:
            if route=='/api/logout':
                cookie=SimpleCookie();cookie.load(self.headers.get('Cookie',''));m=cookie.get('ilmek_session')
                if m:c.execute('DELETE FROM sessions WHERE token_hash=?',(hashlib.sha256(m.value.encode()).hexdigest(),))
                c.close();return self.send({'ok':True},headers={'Set-Cookie':'ilmek_session=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0'})
            cancel=re.fullmatch(r'/api/sales/(\d+)/cancel',route)
            if cancel:
                if u['role']!='admin':c.close();return self.send({'error':'Satış iptalini yalnızca yönetici yapabilir'},403)
                sid=int(cancel.group(1));sale=c.execute('SELECT status FROM sales WHERE id=?',(sid,)).fetchone()
                if not sale or sale['status']!='completed':raise ValueError('Satış bulunamadı veya zaten iptal edilmiş')
                lines=list(c.execute('SELECT product_id,qty,name FROM sale_items WHERE sale_id=?',(sid,)))
                for item in lines:c.execute('UPDATE products SET stock=stock+? WHERE id=?',(item['qty'],item['product_id']))
                reason=str(data.get('reason','')).strip() or 'Hatalı satış';c.execute("UPDATE sales SET status='cancelled',cancel_reason=? WHERE id=?",(reason,sid));log(c,u,'Satış iptal edildi',f"#{sid} · {reason}")
            if route=='/api/password':
                row=c.execute('SELECT password_hash FROM users WHERE id=?',(u['id'],)).fetchone();salt,expected=row['password_hash'].split(':');oldok=hmac.compare_digest(hashlib.pbkdf2_hmac('sha256',str(data.get('current','')).encode(),bytes.fromhex(salt),600000).hex(),expected);new=str(data.get('password',''))
                if not oldok:raise ValueError('Mevcut parola doğru değil')
                if len(new)<10 or len(new)>256:raise ValueError('Yeni parola 10–256 karakter aralığında olmalı')
                c.execute('UPDATE users SET password_hash=? WHERE id=?',(hash_pw(new),u['id']));c.execute('DELETE FROM sessions WHERE user_id=?',(u['id'],));log(c,u,'Kendi parolasını değiştirdi');c.commit();c.close();return self.send({'ok':True},headers={'Set-Cookie':'ilmek_session=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0'})
            if route=='/api/products':
                r=rate();rate_day=data.get('rate_day');name=str(data.get('name','')).strip();stock=int(data.get('stock',0));cost=Decimal(str(data.get('cost',0)));price_tl=Decimal(str(data.get('price_tl',0)));image=''
                if rate_day!=r['snapshot_day']:raise ValueError('Günlük kur değişti; fiyatı kontrol edip tekrar kaydedin')
                if not name or stock<0 or cost<0 or price_tl<0 or r['value']<=0:raise ValueError('Ürün bilgilerini kontrol edin')
                rate_decimal=Decimal(str(r['value']));price=float((price_tl/rate_decimal).quantize(Decimal('0.00000001'),rounding=ROUND_HALF_UP));cost_usd=float((cost/rate_decimal).quantize(Decimal('0.00000001'),rounding=ROUND_HALF_UP))
                raw=data.get('image','')
                if raw:
                    m=re.fullmatch(r'data:image/webp;base64,([A-Za-z0-9+/=]+)',raw)
                    if not m:raise ValueError('Ürün fotoğrafı WebP biçiminde olmalı')
                    b=base64.b64decode(m.group(1),validate=True)
                    if len(b)>3*1024*1024 or not b.startswith(b'RIFF') or b[8:12]!=b'WEBP':raise ValueError('Fotoğraf 3 MB altında WebP olmalı')
                    IMAGES.mkdir(exist_ok=True); image=secrets.token_hex(16)+'.webp';(IMAGES/image).write_bytes(b);image='/images/'+image
                cur=c.execute('INSERT INTO products(name,category,stock,cost,cost_usd,price_usd,low_stock,image) VALUES(?,?,?,?,?,?,?,?)',(name,str(data.get('category','')),stock,float(cost),cost_usd,price,max(0,int(data.get('low_stock',5))),image))
                if stock:c.execute('INSERT INTO stock_log(product_id,name,qty,unit_cost,note,user_id) VALUES(?,?,?,?,?,?)',(cur.lastrowid,name,stock,cost,'İlk stok',u['id']))
                log(c,u,'Ürün eklendi',name)
            elif route=='/api/stock':
                p=c.execute('SELECT * FROM products WHERE id=?',(int(data['product_id']),)).fetchone();q=int(data['qty'])
                if not p or q<1:raise ValueError('Ürün ve geçerli adet seçin')
                r=rate();cost=Decimal(str(data.get('cost',p['cost'])));price_tl=data.get('price_tl');price=None
                if price_tl is not None:
                    if data.get('rate_day')!=r['snapshot_day']:raise ValueError('Günlük kur değişti; satış fiyatını kontrol edip tekrar kaydedin')
                    price=float((Decimal(str(price_tl))/Decimal(str(r['value']))).quantize(Decimal('0.00000001'),rounding=ROUND_HALF_UP))
                avg=((Decimal(str(p['stock']))*Decimal(str(p['cost'])))+(Decimal(q)*cost))/(Decimal(str(p['stock']+q))) if p['stock']+q else cost
                avg_usd=((Decimal(str(p['stock']))*Decimal(str(p['cost_usd'])))+(Decimal(q)*cost/Decimal(str(r['value']))))/(Decimal(str(p['stock']+q))) if p['stock']+q else cost/Decimal(str(r['value']))
                c.execute('UPDATE products SET stock=stock+?,cost=?,cost_usd=?,price_usd=COALESCE(?,price_usd) WHERE id=?',(q,float(avg),float(avg_usd),price,p['id']));c.execute('INSERT INTO stock_log(product_id,name,qty,unit_cost,note,user_id) VALUES(?,?,?,?,?,?)',(p['id'],p['name'],q,float(cost),'Yeni alım',u['id']));log(c,u,'Stok alımı',f"{p['name']} · {q} adet · {cost:g} TL/adet")
            elif route=='/api/sales':
                snapshot=rate();items=data.get('items',[]);method=data.get('payment');r=snapshot['value']
                if data.get('rate_day')!=snapshot['snapshot_day']:raise ValueError('Günlük kur yenilendi; sepet fiyatlarını kontrol edip tekrar deneyin')
                if method not in ('Nakit','Kart','IBAN') or not items or r<=0:raise ValueError('Satış bilgileri veya günlük kur eksik')
                checked=[];total=0
                for item in items:
                    p=c.execute('SELECT * FROM products WHERE id=?',(int(item['product_id']),)).fetchone();q=int(item['qty'])
                    if not p or q<1 or p['stock']<q:raise ValueError((p['name'] if p else 'Ürün')+' için stok yetersiz')
                    unit=round_tl(Decimal(str(p['price_usd']))*Decimal(str(r)));checked.append((p,q,unit));total+=q*unit
                sid=c.execute('INSERT INTO sales(total,payment,user_id,exchange_rate,rate_date) VALUES(?,?,?,?,?)',(total,method,u['id'],r,snapshot['date'])).lastrowid
                for p,q,unit in checked:c.execute('INSERT INTO sale_items(sale_id,product_id,name,qty,unit_price,unit_cost) VALUES(?,?,?,?,?,?)',(sid,p['id'],p['name'],q,unit,p['cost']));c.execute('UPDATE products SET stock=stock-? WHERE id=?',(q,p['id']))
                log(c,u,'Satış tamamlandı',f'Satış #{sid} · {total} TL · {method} · USD/TRY {r}')
            elif route=='/api/expenses':
                if u['role']!='admin':c.close();return self.send({'error':'Giderleri yalnızca yönetici kaydedebilir'},403)
                amount=round(float(data['amount']))
                if amount<1 or data.get('payment') not in ('Nakit','Kart','IBAN'):raise ValueError('Gider tutarı ve ödeme biçimini kontrol edin')
                title=str(data.get('title','Gider')).strip() or 'Gider';c.execute('INSERT INTO expenses(title,amount,payment,user_id) VALUES(?,?,?,?)',(title,amount,data['payment'],u['id']));log(c,u,'Gider eklendi',f'{title} · {amount} TL')
            elif route=='/api/stock-adjustments':
                if u['role']!='admin':c.close();return self.send({'error':'Stok düzeltmesini yalnızca yönetici yapabilir'},403)
                p=c.execute('SELECT * FROM products WHERE id=?',(int(data['product_id']),)).fetchone();q=int(data['qty']);note=str(data.get('note','')).strip()
                if not p or not q or not note or p['stock']+q<0:raise ValueError('Ürün, açıklama ve geçerli adet girin; stok eksiye düşemez')
                c.execute('UPDATE products SET stock=stock+? WHERE id=?',(q,p['id']));c.execute('INSERT INTO stock_log(product_id,name,qty,unit_cost,note,user_id) VALUES(?,?,?,?,?,?)',(p['id'],p['name'],q,p['cost'],note,u['id']));log(c,u,'Stok düzeltildi',f"{p['name']} · {q:+} adet · {note}")
            elif route=='/api/users':
                if u['role']!='admin':c.close();return self.send({'error':'Yalnızca yönetici hesap açabilir'},403)
                username=str(data.get('username','')).strip();name=str(data.get('name','')).strip();password=str(data.get('password',''))
                if not re.fullmatch(r'[A-Za-z0-9_.-]{3,32}',username):raise ValueError('Kullanıcı adı 3–32 karakter olmalı')
                if len(password)<10:raise ValueError('Parola en az 10 karakter olmalı')
                role=data.get('role','cashier')
                if role not in ('cashier','admin'):raise ValueError('Rol seçin')
                cur=c.execute('INSERT INTO users(username,name,role,password_hash) VALUES(?,?,?,?)',(username,name or username,role,hash_pw(password)));log(c,u,'Yeni hesap açıldı',username+' · '+role)
            elif re.fullmatch(r'/api/users/\d+/password',route):
                if u['role']!='admin':c.close();return self.send({'error':'Yalnızca yönetici parola yenileyebilir'},403)
                target=int(route.split('/')[3]);password=str(data.get('password',''))
                if len(password)<10:raise ValueError('Parola en az 10 karakter olmalı')
                if not c.execute('SELECT 1 FROM users WHERE id=?',(target,)).fetchone():raise ValueError('Hesap bulunamadı')
                c.execute('UPDATE users SET password_hash=? WHERE id=?',(hash_pw(password),target));c.execute('DELETE FROM sessions WHERE user_id=?',(target,));log(c,u,'Parola yenilendi',str(target))
            elif re.fullmatch(r'/api/users/\d+/toggle',route):
                if u['role']!='admin':c.close();return self.send({'error':'Yalnızca yönetici hesapları yönetebilir'},403)
                target=int(route.split('/')[3]);row=c.execute('SELECT role,active FROM users WHERE id=?',(target,)).fetchone()
                if not row:raise ValueError('Hesap bulunamadı')
                if target==u['id']:raise ValueError('Kendi hesabınızı buradan kapatamazsınız')
                newactive=1-int(row['active'])
                if row['role']=='admin' and row['active'] and c.execute("SELECT count(*) FROM users WHERE role='admin' AND active=1").fetchone()[0]<=1:raise ValueError('En az bir etkin yönetici hesabı kalmalı')
                c.execute('UPDATE users SET active=? WHERE id=?',(newactive,target))
                if not newactive:c.execute('DELETE FROM sessions WHERE user_id=?',(target,))
                log(c,u,'Hesap etkinleştirildi' if newactive else 'Hesap kapatıldı',str(target))
            else:c.close();return self.send({'error':'Bulunamadı'},404)
            c.commit();c.close()
            if route=='/api/sales':return self.send({'ok':True,'sale_id':sid,'total':total,'rate':r,'rate_date':snapshot['date'],'rate_day':snapshot['snapshot_day']})
            return self.send({'ok':True})
        except sqlite3.IntegrityError:c.rollback();c.close();return self.send({'error':'Bu kullanıcı adı kullanımda'},409)
        except (ValueError,KeyError,TypeError,sqlite3.Error) as e:c.rollback();c.close();return self.send({'error':str(e)},400)

if __name__=='__main__':
    DATA.mkdir(parents=True,exist_ok=True);db().close();backup();threading.Thread(target=daily_backup,daemon=True).start();port=os.environ.get('PORT','8000');host=os.environ.get('HOST','0.0.0.0');print(f'İlmek Paneli: http://{host}:{port}')
    server=ThreadingHTTPServer((host,int(port)),Handler)
    if os.environ.get('OPEN_BROWSER')=='1':webbrowser.open('http://localhost:'+port)
    server.serve_forever()
