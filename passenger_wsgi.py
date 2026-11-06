"""WSGI bridge for cPanel/Passenger. Core route logic remains in server.py."""
from email.message import Message
from http import HTTPStatus
from io import BytesIO
from pathlib import Path
import json, os, secrets, sys, time, traceback

ROOT=Path(__file__).resolve().parent
DIAGNOSTIC_LOG=ROOT/'.passenger-diagnostics.log'

def diagnose(message,exc_info=None):
    """Keep private Passenger diagnostics outside the public web root."""
    try:
        parts=[time.strftime('%Y-%m-%d %H:%M:%S %z'),message]
        if exc_info: parts.extend(traceback.format_exception(*exc_info))
        payload=' '.join(parts[:2])+'\n'+(''.join(parts[2:]) if len(parts)>2 else '')
        fd=os.open(str(DIAGNOSTIC_LOG),os.O_WRONLY|os.O_CREAT|os.O_APPEND,0o600)
        try: os.write(fd,payload.encode('utf-8','replace'))
        finally: os.close(fd)
        os.chmod(str(DIAGNOSTIC_LOG),0o600)
    except Exception:
        pass

diagnose(f'WSGI ENTRY python={sys.version.split()[0]} executable={sys.executable}')
VENV_PYTHON=ROOT/'venv'/'bin'/'python'
# cPanel's system Python may be older than the application's Python. Re-exec
# Passenger through the per-app virtualenv created with Python 3.11+.
if sys.version_info < (3,11):
    if not VENV_PYTHON.exists():
        diagnose(f'VENV PYTHON MISSING path={VENV_PYTHON}')
        raise RuntimeError('Ilmek requires Python 3.11+. Create venv/bin/python with the installed Python 3.11 runtime.')
    try: os.execl(str(VENV_PYTHON),str(VENV_PYTHON),*sys.argv)
    except Exception: diagnose('VENV REEXEC FAILED',sys.exc_info());raise
if str(ROOT) not in sys.path: sys.path.insert(0,str(ROOT))
try: import server as panel
except Exception: diagnose('SERVER IMPORT FAILED',sys.exc_info());raise

try:
    panel.DATA.mkdir(parents=True,exist_ok=True)
    panel.db().close()
    diagnose(f'APP INIT OK python={sys.version.split()[0]} data_dir={panel.DATA}')
except Exception: diagnose('APP INIT FAILED',sys.exc_info());raise

class PassengerHandler(panel.Handler):
    def send_response(self,code,message=None):
        self._status=code
    def send_header(self,key,value):
        self._response_headers.append((key,value))
    def end_headers(self):
        pass

def _serve(environ,start_response):
    method=environ.get('REQUEST_METHOD','GET').upper()
    handler=PassengerHandler.__new__(PassengerHandler)
    handler.headers=Message()
    for key,value in environ.items():
        if key.startswith('HTTP_'):
            handler.headers[key[5:].replace('_','-')]=value
    if environ.get('CONTENT_TYPE'): handler.headers['Content-Type']=environ['CONTENT_TYPE']
    if environ.get('CONTENT_LENGTH'): handler.headers['Content-Length']=environ['CONTENT_LENGTH']
    if environ.get('HTTPS','').lower() in ('on','1','true') or environ.get('wsgi.url_scheme')=='https':
        handler.headers['X-Forwarded-Proto']='https'
    handler.path=environ.get('PATH_INFO') or '/'
    if environ.get('QUERY_STRING'): handler.path+='?'+environ['QUERY_STRING']
    handler.client_address=(environ.get('REMOTE_ADDR','127.0.0.1'),int(environ.get('REMOTE_PORT') or 0))
    length=int(environ.get('CONTENT_LENGTH') or 0)
    handler.rfile=BytesIO(environ['wsgi.input'].read(length) if length else b'')
    handler.wfile=BytesIO();handler._status=200;handler._response_headers=[]
    if method=='GET': panel.Handler.do_GET(handler)
    elif method=='POST': panel.Handler.do_POST(handler)
    else:
        handler.send_response(405);handler.send_header('Content-Type','application/json; charset=utf-8');handler.end_headers();handler.wfile.write(b'{"error":"Yontem desteklenmiyor"}')
    status=handler._status
    try: reason=HTTPStatus(status).phrase
    except ValueError: reason='Unknown'
    start_response(f'{status} {reason}',handler._response_headers)
    return [handler.wfile.getvalue()]

def application(environ,start_response):
    method=environ.get('REQUEST_METHOD','GET').upper()
    path=environ.get('PATH_INFO') or '/'
    diagnose(f'REQUEST {method} {path}')
    try: return _serve(environ,start_response)
    except Exception:
        request_id=secrets.token_hex(4)
        diagnose(f'REQUEST FAILED id={request_id} method={method} path={path}',sys.exc_info())
        body=json.dumps({'error':'Sunucu hatası','request_id':request_id},ensure_ascii=False).encode('utf-8')
        start_response('500 Internal Server Error',[
            ('Content-Type','application/json; charset=utf-8'),
            ('Content-Length',str(len(body))),
            ('Cache-Control','no-store'),
            ('X-Ilmek-Error-ID',request_id),
        ])
        return [body]
