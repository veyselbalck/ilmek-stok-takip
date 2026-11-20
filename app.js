'use strict';
/* İlmek front end. Plain JavaScript, no build step.
   Talks to cpanel-php/api.php (full feature set) or server.py (core features only).
   All user-visible text is Turkish. Interaction uses event delegation (data-act / data-input / data-change / data-key)
   so a strict Content-Security-Policy without 'unsafe-inline' scripts can be applied. */

/* ── State ─────────────────────────────────────────────────────────────── */
let user=null,data=emptyData(),usdTry=0,rateDate='',rateDay='',rateStale=false,page='home',cart=[],query='',
    rateTimer=null,saleSending=false,dataLoadSeq=0,reportPeriod=7,reportSalesPage=1,idx={items:new Map()};
const PAGES=['home','sales','stock','reports','staff','settings'],ADMIN_PAGES=['reports','staff'],PAYMENTS=['Nakit','Kart','IBAN'];

/* Every key the UI reads. The server may omit some (cashiers, the lighter Python server), so load() merges over this. */
function emptyData(){return {products:[],sales:[],sale_items:[],expenses:[],stock_log:[],activity:[],users:[],suppliers:[],purchases:[],purchase_items:[],supplier_payments:[],cash_closings:[],sale_returns:[],sale_return_items:[],stock_counts:[],settings:{shop_name:'İlmek',low_stock_default:5,default_theme:'lavender',report_period:7}}}

/* ── Helpers ───────────────────────────────────────────────────────────── */
const $=s=>document.querySelector(s),$$=s=>[...document.querySelectorAll(s)];
const tlRound=n=>{n=Number(n);return Number.isFinite(n)?Math.floor(n+Number.EPSILON*Math.abs(n)+0.5):0};
const nf0=new Intl.NumberFormat('tr-TR',{maximumFractionDigits:0}),nf2=new Intl.NumberFormat('tr-TR',{maximumFractionDigits:2}),nf4=new Intl.NumberFormat('tr-TR',{maximumFractionDigits:4});
const num=n=>nf0.format(Number(n)||0),fmt=n=>nf0.format(tlRound(n))+' ₺',dec=n=>nf2.format(n||0),dec4=n=>nf4.format(n||0);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const tl=usdValue=>usdTry?fmt(usdValue*usdTry):'—';                         /* USD price → today's TL, or a dash while the rate is unknown */
const price=p=>tlRound((Number(p.price_usd)||0)*usdTry);                      /* unit sale price in whole TL, same rounding as the server */
const isAdmin=()=>user?.role==='admin';
const roleName=r=>r==='admin'?'Yönetici':'Satış elemanı';
const store={get(k){try{return localStorage.getItem(k)}catch{return null}},set(k,v){try{localStorage.setItem(k,v)}catch{}}};

/* Dates. The server stores UTC "YYYY-MM-DD HH:MM:SS"; the browser shows local time. */
let now=new Date();
const asDate=d=>d instanceof Date?d:(typeof d==='string'&&/^\d{4}-\d\d-\d\d \d\d:/.test(d)?new Date(d.replace(' ','T')+'Z'):new Date(d));
const datekey=d=>asDate(d).toLocaleDateString('sv-SE');
let todaykey=datekey(now);
const shortdate=d=>asDate(d).toLocaleDateString('tr-TR',{day:'numeric',month:'short'}),clock=d=>asDate(d).toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'});
const daysAgo=n=>{const d=new Date();d.setDate(d.getDate()-n);return d};
/* A shop tablet can stay open overnight, so "today" is recomputed on every draw. Returns true when the day changed. */
function refreshToday(){const n=new Date(),k=datekey(n),changed=k!==todaykey;now=n;todaykey=k;const el=$('#today');if(el)el.textContent=n.toLocaleDateString('tr-TR',{weekday:'long',day:'numeric',month:'long',year:'numeric'});return changed}

/* Icons: 24px stroke icons, drawn inline so they render the same on every device (no emoji, no icon font). */
const ICONS={
  yarn:'<circle cx="11" cy="12" r="7.5"/><path d="M5.7 6.7c4.2.6 8.6 4.8 10.6 10.6M9.8 4.7c3.8 1.4 6.9 4.6 8.1 9.8M3.9 10.8c3 .4 6.6 3.8 8.6 8.4M16.3 17.3c1.4 1.9 3.3 2.6 5.2 1.7"/>',
  cart:'<path d="M3 4h2l2.2 10.2a1 1 0 0 0 1 .8h8.6a1 1 0 0 0 1-.76L20 8H6"/><circle cx="9.5" cy="19" r="1.3"/><circle cx="17" cy="19" r="1.3"/>',
  box:'<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5zM3 7.5l9 4.5 9-4.5M12 12v9"/>',
  inbox:'<path d="M4 14 6 5h12l2 9M4 14v5h16v-5M4 14h5l1 2h4l1-2h5"/>',
  money:'<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 12h.01M18 12h.01"/>',
  receipt:'<path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6"/>',
  chart:'<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  users:'<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.4"/><path d="M17 14c2.5 0 4 1.8 4 4.5"/>',
  search:'<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
  plus:'<path d="M12 5v14M5 12h14"/>',
  download:'<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M4 20h16"/>',
  upload:'<path d="M12 15V4M7.5 8.5 12 4l4.5 4.5M4 20h16"/>',
  refresh:'<path d="M20 11a8 8 0 1 0-2.3 6M20 5v6h-6"/>',
  alert:'<path d="M12 3.5 2.5 20h19zM12 10v4.5M12 17.5h.01"/>',
  check:'<path d="m5 12.5 4.5 4.5L19 7.5"/>',
  logout:'<path d="M10 4H5v16h5M15 8l4 4-4 4M19 12H9"/>',
  key:'<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 7l2 2"/>'
};
const ico=(name,cls='')=>`<svg class="ico ${cls}" viewBox="0 0 24 24" aria-hidden="true">${ICONS[name]||''}</svg>`;

let toastTimer;
function toast(text,kind){const t=$('#toast');t.textContent=text;t.classList.toggle('error',kind==='error');t.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>t.classList.remove('show'),kind==='error'?4200:2800)}
const fail=e=>toast(e?.message||'Bir sorun oluştu','error');
const setTitle=t=>{$('#title').textContent=t;document.title=t+' • İlmek'};

/* ── Data helpers shared by dashboards and reports ─────────────────────── */
function reindex(){idx={items:new Map()};for(const i of data.sale_items){const k=Number(i.sale_id);let list=idx.items.get(k);if(!list)idx.items.set(k,list=[]);list.push(i)}}
const itemsOf=id=>idx.items.get(Number(id))||[];
const productName=p=>p?.variant?p.name+' · '+p.variant:(p?.name||'');
const returnedQty=i=>Number(i.returned_qty||0);
const refundTotal=s=>{const n=Number(s.return_total);return Number.isFinite(n)&&n>=0?n:(data.sale_returns||[]).filter(r=>Number(r.sale_id)===Number(s.id)).reduce((a,r)=>a+(Number(r.total)||0),0)};
const netSale=s=>Math.max(0,(Number(s.total)||0)-refundTotal(s));
const liveSales=()=>data.sales.filter(s=>s.status!=='cancelled');
const lowStock=()=>data.products.filter(p=>p.stock<=p.low_stock);

/* Revenue, cost, expenses and units sold over the last `days` days (today included). */
function periodStats(days){
  const start=datekey(daysAgo(days-1)),inRange=d=>{const k=datekey(d);return k>=start&&k<=todaykey};
  const sales=liveSales().filter(s=>inRange(s.created)),units=new Map();
  let revenue=0,cost=0,unitTotal=0;
  for(const s of sales){revenue+=netSale(s);for(const i of itemsOf(s.id)){const q=Math.max(0,(Number(i.qty)||0)-returnedQty(i));cost+=q*(Number(i.unit_cost)||0);unitTotal+=q;units.set(i.name,(units.get(i.name)||0)+q)}}
  const expense=data.expenses.filter(e=>inRange(e.created)).reduce((a,e)=>a+(Number(e.amount)||0),0);
  return {sales,units,unitTotal,revenue,cost,gross:revenue-cost,expense,net:revenue-cost-expense};
}
/* One entry per day for the last `days` days: net sales and money spent on stock purchases. */
function dailySeries(days){
  const bought=['Yeni alım','İlk stok','Initial stock'],sold=new Map(),bought_=new Map(),out=[];
  for(const s of liveSales()){const k=datekey(s.created);sold.set(k,(sold.get(k)||0)+netSale(s))}
  for(const x of data.stock_log)if(bought.includes(x.note)&&Number(x.qty)>0){const k=datekey(x.created);bought_.set(k,(bought_.get(k)||0)+x.qty*(Number(x.unit_cost)||0))}
  for(let n=days-1;n>=0;n--){const d=daysAgo(n),k=datekey(d);out.push({key:k,date:d,sales:sold.get(k)||0,buy:bought_.get(k)||0})}
  return out;
}
const paymentTotals=sales=>PAYMENTS.map(m=>[m,sales.filter(s=>s.payment===m).reduce((a,s)=>a+netSale(s),0)]);

/* ── Server access ─────────────────────────────────────────────────────── */
/* Always returns parsed JSON or throws an Error whose message is safe to show (never a raw parse error). */
async function api(path,body,method){
  const opt={credentials:'same-origin',headers:{'Content-Type':'application/json'}};
  if(body!==undefined||method){opt.method=method||'POST';if(body!==undefined)opt.body=JSON.stringify(body)}
  let r;try{r=await fetch(path,opt)}catch{throw Error('Sunucuya ulaşılamadı. Bağlantınızı kontrol edip tekrar deneyin.')}
  let o=null;try{o=await r.json()}catch{}
  if(!r.ok){if(r.status===401&&user)logout();throw Error(o?.error||(r.status>=500?'Sunucu hatası. Biraz sonra tekrar deneyin.':'Bir sorun oluştu'))}
  if(o===null)throw Error('Sunucudan beklenmeyen bir yanıt geldi.');
  return o;
}
async function load(){
  const sequence=++dataLoadSeq;
  try{
    const latest=await api('/api/data');if(sequence!==dataLoadSeq)return;
    data={...emptyData(),...latest};reindex();
    reportPeriod=Number(data.settings.report_period)||7;
    if(!store.get('ilmek-theme'))applyTheme(data.settings.default_theme||'lavender',{persist:false});
    $('#shopName').textContent=data.settings.shop_name||'İlmek';
    render();
  }catch(e){if(sequence===dataLoadSeq)fail(e)}
}
function setRate(x){
  const changed=usdTry!==(x.value||0)||rateDay!==(x.snapshot_day||'')||rateStale!==!!x.stale,pay=$('#pay')?.value;
  usdTry=x.value||0;rateDate=x.date||'';rateDay=x.snapshot_day||'';rateStale=!!x.stale;
  $('#rate').textContent=usdTry?dec4(usdTry):'—';
  $('#rateDate').textContent=usdTry?(x.stale?'Yedek kur':'TCMB satış kuru')+', '+(rateDate||'tarih yok'):'Kur alınamadı';
  $('#rateDate').title=(x.source||'TCMB USD Döviz satış')+' · alışveriş günü '+rateDay;
  $('.ratebox').classList.toggle('stale',rateStale||!usdTry);
  if(changed){render();if(pay&&$('#pay'))$('#pay').value=pay}
}
async function getRate(){try{const x=await (await fetch('/api/rate',{cache:'no-store'})).json();if(x.error)throw Error(x.error);setRate(x);return x}catch{return null}}

/* ── Sign-in, setup, session ───────────────────────────────────────────── */
function authShell(inner){document.title='İlmek • Tuhafiye';$('#login').classList.remove('hidden');$('#app').classList.add('hidden');$('#loginBox').innerHTML=`<div class="brandicon big">${ico('yarn')}</div>${inner}`}
function showLogin(){
  user=null;
  authShell(`<h1>İlmek</h1><p class="login-sub">Hesabınızla giriş yapın</p><form id="loginForm"><label>Kullanıcı adı<input id="username" autocomplete="username" autocapitalize="none" spellcheck="false" required></label><label>Parola<input id="password" type="password" autocomplete="current-password" required></label><p class="form-error" id="authError" role="alert"></p><button class="primary full" type="submit">Panele gir</button></form>`);
  $('#loginForm').onsubmit=async e=>{e.preventDefault();const b=e.submitter||$('#loginForm button');b.disabled=true;$('#authError').textContent='';
    try{const o=await api('/api/login',{username:$('#username').value,password:$('#password').value});user=o.user;enter()}
    catch(err){$('#authError').textContent=err.message;b.disabled=false}};
}
function showSetup(requiresToken){
  authShell(`<div class="eyebrow">İlk kurulum</div><h1>Yönetici hesabı</h1><p class="login-sub">Dükkânın sahibi için güvenli bir hesap oluşturalım.</p><form id="setupForm">${requiresToken?'<label>Kurulum anahtarı<input id="setupToken" type="password" autocomplete="off" required></label>':''}<label>Adınız<input id="ownerName" autocomplete="name" required></label><label>Kullanıcı adı<input id="ownerUser" autocomplete="username" autocapitalize="none" minlength="3" required></label><label>Parola <small>(en az 10 karakter)</small><input id="ownerPass" type="password" autocomplete="new-password" minlength="10" required></label><p class="form-error" id="authError" role="alert"></p><button class="primary full" type="submit">Yönetici hesabını oluştur</button></form>`);
  $('#setupForm').onsubmit=async e=>{e.preventDefault();const b=e.submitter||$('#setupForm button');b.disabled=true;$('#authError').textContent='';
    try{const o=await api('/api/setup-admin',{name:$('#ownerName').value,username:$('#ownerUser').value,password:$('#ownerPass').value,setup_token:$('#setupToken')?.value||''});user=o.user;enter()}
    catch(err){$('#authError').textContent=err.message;b.disabled=false}};
}
async function start(){
  refreshToday();
  try{
    const b=await (await fetch('/api/bootstrap')).json();
    if(b.needs_setup){showSetup(b.requires_setup_token);return}
    const m=await fetch('/api/me',{credentials:'same-origin'});
    if(m.ok){user=(await m.json()).user;enter()}else showLogin();
  }catch{authShell('<h1>İlmek</h1><p class="login-sub">Sunucuya ulaşılamadı. Bağlantınızı kontrol edin.</p><button class="primary full" type="button" data-act="retryStart">Tekrar dene</button>')}
}
const allowed=p=>PAGES.includes(p)&&!(ADMIN_PAGES.includes(p)&&!isAdmin());
function enter(){
  $('#login').classList.add('hidden');$('#app').classList.remove('hidden');
  $('#avatar').textContent=(user.name||'?').trim().charAt(0).toLocaleUpperCase('tr');$('#avatar').title=user.name+' · '+roleName(user.role);
  const wanted=location.hash.match(/^#\/(home|sales|stock|reports|staff|settings)$/)?.[1]||'home';
  page=allowed(wanted)?wanted:'home';
  if(location.hash!=='#/'+page)history.replaceState({page},'',location.pathname+location.search+'#/'+page);
  syncChrome();load();getRate();
  clearInterval(rateTimer);rateTimer=setInterval(tick,5*60*1000);
}
function tick(){if(!user)return;if(refreshToday())load();getRate()}
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&user)tick()});
/* `user` is cleared first so a 401 from the logout request itself can never call logout() again.
   The request must be a POST (a body is given) or the server ignores it and the session stays valid. */
async function logout(){const had=user;user=null;clearInterval(rateTimer);cart=[];if(had){try{await api('/api/logout',{})}catch{}}page='home';history.replaceState({page:'home'},'',location.pathname+location.search+'#/home');showLogin()}
function changePassword(){
  modal('Kendi parolanızı değiştirin',`<label>Mevcut parola<input id="oldpass" type="password" autocomplete="current-password"></label><label>Yeni parola <small>(en az 10 karakter)</small><input id="newpass" type="password" minlength="10" autocomplete="new-password"></label>`,
    async()=>{await api('/api/password',{current:$('#oldpass').value,password:$('#newpass').value});user=null;closeLayer($('#modal'));showLogin();toast('Parolanız değişti; yeni parolanızla giriş yapın')},'Parolayı değiştir');
}

/* ── Navigation ────────────────────────────────────────────────────────── */
function syncChrome(){$('#backBtn').classList.toggle('hidden',page==='home')}
function go(p,fromHistory=false){
  if(!allowed(p))p='home';
  if(p!==page){if(p!=='sales')cart=[];query=''}
  page=p;syncChrome();
  if(!fromHistory&&location.hash!=='#/'+p)history.pushState({page:p},'',location.pathname+location.search+'#/'+p);
  render();window.scrollTo(0,0);$('#title').focus({preventScroll:true});
}
window.addEventListener('popstate',()=>{if(user)go(location.hash.replace(/^#\//,'')||'home',true)});
function render(){
  if(!user)return;refreshToday();syncChrome();
  const pages={home:isAdmin()?homePage:employeeHome,sales:salesPage,stock:stockPage,reports:reportsPage,staff:staffPage,settings:settingsPage};
  (pages[page]||pages.home)();
}

/* ── Layers: modal forms and confirmation dialogs (focus trap, Esc, focus restore) ─ */
const layers=[];
function openLayer(el,html){
  const prev=document.activeElement;el.innerHTML=html;el.classList.remove('hidden');document.body.classList.add('noscroll');
  layers.push({el,prev});
  const box=el.querySelector('.modalbox');
  (box.querySelector('input:not([type=hidden]):not([type=file]):not([disabled]),select,textarea')||box.querySelector('button.primary')||box).focus({preventScroll:true});
}
function closeLayer(el){
  const i=layers.findIndex(l=>l.el===el);if(i<0)return;
  const [l]=layers.splice(i,1);el.classList.add('hidden');el.innerHTML='';
  if(!layers.length)document.body.classList.remove('noscroll');
  if(l.prev?.isConnected)l.prev.focus({preventScroll:true});
}
document.addEventListener('keydown',e=>{
  const top=layers[layers.length-1];if(!top)return;
  if(e.key==='Escape'){e.preventDefault();top.cancel?top.cancel():closeLayer(top.el);return}
  if(e.key!=='Tab')return;
  const f=[...top.el.querySelectorAll('button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[href]')].filter(x=>x.offsetParent!==null);
  if(!f.length)return;const first=f[0],last=f[f.length-1];
  if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}
});
/* Form dialog. `save` may throw; its message is shown inside the dialog and the dialog stays open. */
function modal(title,inside,save,button='Kaydet',{cancel=true}={}){
  openLayer($('#modal'),`<div class="modalbox" role="dialog" aria-modal="true" aria-labelledby="modalTitle"><h2 id="modalTitle">${esc(title)}</h2><form id="modalForm" novalidate>${inside}<p class="form-error" id="modalError" role="alert"></p><div class="modalfooter">${cancel?'<button type="button" class="secondary" data-act="closeModal">Vazgeç</button>':''}<button type="submit" class="primary" id="modalSave">${esc(button)}</button></div></form></div>`);
  const form=$('#modalForm');
  form.addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target.id==='pb')e.preventDefault()});   /* barcode scanners send Enter */
  form.onsubmit=async e=>{e.preventDefault();const b=$('#modalSave');if(b.disabled)return;b.disabled=true;$('#modalError').textContent='';
    try{await save();closeLayer($('#modal'))}catch(err){const box=$('#modalError');if(box)box.textContent=err?.message||'Bir sorun oluştu'}
    finally{if(b.isConnected)b.disabled=false}};
}
/* Yes/no dialog (optionally with a text field). Resolves true/the field text, or null when cancelled. */
function ask(message,{title='Emin misiniz?',confirm:ok='Evet',danger=false,field=null}={}){
  return new Promise(resolve=>{
    const el=$('#dialog');let done=false;
    const finish=v=>{if(done)return;done=true;closeLayer(el);resolve(v)};
    openLayer(el,`<div class="modalbox small" role="alertdialog" aria-modal="true" aria-labelledby="dlgTitle" aria-describedby="dlgMsg"><h2 id="dlgTitle">${esc(title)}</h2><p id="dlgMsg" class="dlg-msg">${esc(message)}</p>${field?`<label>${esc(field.label)}<input id="dlgField" value="${esc(field.value||'')}" maxlength="200"></label>`:''}<div class="modalfooter"><button type="button" class="secondary" id="dlgNo">Vazgeç</button><button type="button" class="${danger?'danger':'primary'}" id="dlgYes">${esc(ok)}</button></div></div>`);
    layers[layers.length-1].cancel=()=>finish(null);
    $('#dlgNo').onclick=()=>finish(null);
    $('#dlgYes').onclick=()=>finish(field?$('#dlgField').value.trim():true);
    if(field)$('#dlgField').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();$('#dlgYes').click()}});else $('#dlgNo').focus();   /* safer default for destructive actions */
  });
}

/* ── Shared page pieces ────────────────────────────────────────────────── */
const tile=(act,icon,title,sub,{cls='',page:pg=''}={})=>`<button class="tile ${cls}" type="button" data-act="${act}"${pg?` data-page="${pg}"`:''}><span class="tile-icon">${ico(icon)}</span><b>${title}</b><small>${sub}</small></button>`;
const greeting=()=>{const h=new Date().getHours();return h<6?'İyi geceler':h<12?'Günaydın':h<18?'İyi günler':'İyi akşamlar'};
const statCard=(label,value,sub)=>`<div class="card stat"><div class="statlabel">${label}</div><div class="statvalue">${value}</div><div class="sub">${sub}</div></div>`;
const dayLabel=(d,days)=>days===1?'Bugün':days>7?String(d.getDate()).padStart(2,'0'):d.toLocaleDateString('tr-TR',{weekday:'short'});
function barsHtml(series,{buy=false}={}){
  const max=Math.max(1,...series.flatMap(x=>buy?[x.sales,x.buy]:[x.sales])),amounts=series.length<=7;
  return series.map(x=>{const h=v=>v?Math.max(3,v/max*100):0;return `<div class="barcol"><span class="baramount">${amounts&&x.sales?fmt(x.sales):''}</span><div class="barwell"><div class="bar${x.key===todaykey?' today':''}" style="height:${h(x.sales)}%" title="Satış ${esc(fmt(x.sales))}"></div>${buy?`<div class="bar buybar" style="height:${h(x.buy)}%" title="Alış ${esc(fmt(x.buy))}"></div>`:''}</div><span class="barlabel">${esc(dayLabel(x.date,series.length))}</span></div>`}).join('');
}
const chartSummary=(series,what)=>what+': '+series.map(x=>dayLabel(x.date,series.length)+' '+fmt(x.sales)).join(', ');
function paymentDonut(totals){
  const colors=['var(--accent)','var(--c2)','var(--c3)'],total=totals.reduce((a,x)=>a+(Number(x[1])||0),0);let angle=0;
  const stops=total?totals.map(([,v],i)=>{const s=angle;angle+=(Number(v)||0)/total*360;return colors[i]+' '+s+'deg '+angle+'deg'}):['var(--line) 0deg 360deg'];
  return `<div class="payment-chart"><div class="payment-donut" role="img" aria-label="${esc(totals.map(([n,v])=>n+' '+fmt(v)).join(', '))}" style="--donut-gradient:conic-gradient(${stops.join(',')})"><div class="payment-donut-center"><b>${fmt(total)}</b><small>ciro</small></div></div><div class="payment-legend">${totals.map(([n,v],i)=>`<div><span><i style="background:${colors[i]}"></i>${n}</span><b>${fmt(v)}</b></div>`).join('')}</div></div>`;
}
const saleRow=s=>`<div class="activityrow"><div class="bubble">${ico('plus')}</div><div><b>${esc(s.payment)} ile satış</b><small>${shortdate(s.created)}, ${clock(s.created)}</small></div><strong>${fmt(netSale(s))}</strong></div>`;
const lowRow=p=>`<div class="activityrow"><div class="bubble warn">${ico('alert')}</div><div><b>${esc(productName(p))}</b><small>${esc(p.category||'Kategorisiz')}</small></div><span class="pill warn">${p.stock<=0?'Tükendi':p.stock+' adet'}</span></div>`;

/* ── Home (administrator) ──────────────────────────────────────────────── */
function homePage(){
  const today=periodStats(1),month=periodStats(30),low=lowStock(),series=dailySeries(7),recent=liveSales().slice(0,5);
  setTitle(greeting());
  $('#content').innerHTML=`<p class="lede">Dükkânınızın bugünkü durumu burada.</p>
  <section class="tiles tiles-8" aria-label="Hızlı işlemler">
    ${tile('go','cart','Satış yap','Kasaya geç',{cls:'tile-main',page:'sales'})}
    ${tile('openProduct','box','Ürün ekle','Yeni ürün ve başlangıç stoğu')}
    ${tile('openStockIn','inbox','Stok girişi','Mevcut ürüne yeni alım ekle')}
    ${tile('openExpense','money','Gider ekle','Dükkân masrafını kaydet')}
    ${tile('openCashClose','receipt','Gün sonu kasası','Nakit, kart ve IBAN sayımı')}
    ${tile('go','chart','Raporlar','Ciro, kâr ve satış geçmişi',{page:'reports'})}
    ${tile('go','users','Çalışan hesapları','Yetki ve parola yönetimi',{page:'staff'})}
    ${tile('go','search','Ürünleri bul','Stok ve fiyat düzenleme',{page:'stock'})}
  </section>
  <section class="grid stats" aria-label="Özet">
    ${statCard('Bugünkü satış',fmt(today.revenue),`${today.sales.length} işlem, ${num(today.unitTotal)} ürün`)}
    ${statCard('Bugünkü tahmini kâr',fmt(today.gross),'Satış tutarı − ürün alış maliyeti')}
    ${statCard('Ürün çeşidi',num(data.products.length),`Toplam ${num(data.products.reduce((a,p)=>a+p.stock,0))} adet, ${low.length} azalan`)}
    ${statCard('Son 30 gün net',fmt(month.net),'Brüt kâr − kayıtlı giderler')}
  </section>
  <section class="grid two">
    <div class="card"><div class="sectionhead"><h2>Son 7 gün satışları</h2><button class="textbtn" data-act="go" data-page="reports">Tüm kayıtlar →</button></div><div class="chart" role="img" aria-label="${esc(chartSummary(series,'Son 7 günün satışları'))}">${barsHtml(series)}</div></div>
    <div class="card"><div class="sectionhead"><h2>Bugünkü ödeme dağılımı</h2></div>${paymentDonut(paymentTotals(today.sales))}</div>
  </section>
  <section class="grid two">
    <div class="card"><div class="sectionhead"><h2>Son satışlar</h2><button class="textbtn" data-act="go" data-page="reports">Hepsini gör →</button></div>${recent.length?`<div class="activity">${recent.map(saleRow).join('')}</div>`:'<div class="empty">Henüz satış kaydı yok. İlk satışı yapınca burada görünür.</div>'}</div>
    <div class="card"><div class="sectionhead"><h2>Stok azalanlar</h2><button class="textbtn" data-act="go" data-page="stock">Stoklara git →</button></div>${low.length?`<div class="activity">${low.slice(0,5).map(lowRow).join('')}</div>`:'<div class="empty">Tüm stoklar yeterli görünüyor.</div>'}</div>
  </section>`;
}

/* ── Home (cashier) ────────────────────────────────────────────────────── */
function employeeHome(){
  const low=lowStock(),today=periodStats(1);
  setTitle('Merhaba, '+user.name);
  $('#content').innerHTML=`<p class="lede">Ne yapmak istersiniz? Büyük düğmelerden seçin.</p>
  <section class="tiles tiles-6" aria-label="Hızlı işlemler">
    ${tile('go','cart','Satış yap','Ürünleri seç, ödemeyi al',{cls:'tile-main',page:'sales'})}
    ${tile('openProduct','box','Ürün ekle','Yeni ürün ve başlangıç stoğu')}
    ${tile('openStockIn','inbox','Stok girişi','Mevcut ürüne yeni alım ekle')}
    ${tile('go','search','Ürün bul / stok gör',`${data.products.length} ürün, ${low.length} azalan stok`,{page:'stock'})}
    ${tile('openExpense','money','Gider ekle','Dükkân masrafını kaydet')}
    ${tile('openCashClose','receipt','Gün sonu kasası','Bugünkü nakit, kart ve IBAN sayımı')}
  </section>
  <section class="grid stats employee-stats">
    ${statCard('Bugünkü satış',num(today.sales.length),'işlem')}
    ${statCard('Satılan ürün',num(today.unitTotal),'adet')}
  </section>
  ${low.length?`<div class="card low-notice"><div class="sectionhead"><h2>Stok azalıyor</h2></div><div class="activity">${low.slice(0,5).map(lowRow).join('')}</div></div>`:''}`;
}

/* ── Products and stock ────────────────────────────────────────────────── */
function stockRowsHtml(){
  const q=query.toLocaleLowerCase('tr'),admin=isAdmin(),cols=admin?6:5;
  const list=data.products.filter(p=>(productName(p)+' '+(p.category||'')+' '+(p.barcode||'')).toLocaleLowerCase('tr').includes(q));
  return list.map(p=>`<tr><td><div class="prodcell">${p.image?`<img src="${esc(p.image)}" class="thumb" alt="" loading="lazy">`:'<span class="thumb ph" aria-hidden="true"></span>'}<b>${esc(productName(p))}</b></div></td><td>${esc(p.category||'—')}</td><td><span class="pill${p.stock<=p.low_stock?' warn':''}">${p.stock} adet</span></td>${admin?`<td>${tl(p.cost_usd||0)} <small class="usd">$${dec4(p.cost_usd||0)}</small></td>`:''}<td><b>${tl(p.price_usd)}</b>${admin?` <small class="usd">$${dec4(p.price_usd)}</small>`:''}</td><td class="rowactions"><button class="textbtn" data-act="openProduct" data-id="${p.id}">Düzenle</button>${admin?`<button class="textbtn danger-text" data-act="deleteProduct" data-id="${p.id}">Sil</button>`:''}</td></tr>`).join('')||`<tr><td colspan="${cols}" class="empty">${data.products.length?'Aramaya uyan ürün yok.':'Henüz ürün yok. İlk ürününüzü ekleyin.'}</td></tr>`;
}
function stockPage(){
  const admin=isAdmin();setTitle('Ürün ve stoklar');
  $('#content').innerHTML=`<div class="pagehead"><h2>Dükkân envanteri <span class="pill">${data.products.length} ürün</span></h2><div class="actions">${admin?'<button class="secondary" data-act="openStockCount">Stok sayımı</button><button class="secondary" data-act="openAdjustment">Stok düzelt</button>':''}<button class="secondary" data-act="openStockIn">Stok girişi</button><button class="primary" data-act="openProduct">${ico('plus')}Ürün ekle</button></div></div>
  <div class="filterbar"><label class="sr-only" for="stockSearch">Ürün ara</label><input id="stockSearch" type="search" placeholder="Ürün ara veya barkod okut…" value="${esc(query)}" data-input="stockSearch" data-key="stockSearch" autocomplete="off"></div>
  <div class="tablewrap"><table><thead><tr><th>Ürün</th><th>Kategori</th><th>Stok</th>${admin?'<th>Alış maliyeti (bugün)</th>':''}<th>Satış fiyatı (bugün)</th><th><span class="sr-only">İşlemler</span></th></tr></thead><tbody id="stockBody">${stockRowsHtml()}</tbody></table></div>
  <div class="hint">Etiket fiyatları her gün güncel USD/TRY kuruyla yeniden hesaplanır ve tam TL gösterilir. Satış sırasında o anki TL tutarı kayda geçer.</div>`;
}
const photoError={decode:'Bu fotoğraf biçimi açılamadı. iPhone için Ayarlar > Kamera > Biçimler > "En Uyumlu" seçeneğini seçip tekrar deneyin.',read:'Fotoğraf okunamadı. JPEG biçiminde yeniden deneyin.',convert:'Fotoğraf JPEG biçimine dönüştürülemedi.'};
async function photoData(){
  const f=$('#photoGallery')?.files?.[0];if(!f)return '';
  let bitmap=null,url='',img=null;
  try{
    if('createImageBitmap' in window)try{bitmap=await createImageBitmap(f)}catch{}
    if(!bitmap){img=new Image();url=URL.createObjectURL(f);await new Promise((ok,no)=>{img.onload=ok;img.onerror=()=>no(Error(photoError.decode));img.src=url})}
    const w=bitmap?.width||img?.naturalWidth,h=bitmap?.height||img?.naturalHeight;if(!w||!h)throw Error(photoError.read);
    const scale=Math.min(1,1200/Math.max(w,h)),canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(w*scale));canvas.height=Math.max(1,Math.round(h*scale));
    const ctx=canvas.getContext('2d');if(!ctx)throw Error(photoError.convert);ctx.drawImage(bitmap||img,0,0,canvas.width,canvas.height);
    let blob=await new Promise(ok=>canvas.toBlob(ok,'image/jpeg',.86));if(!blob)blob=await new Promise(ok=>canvas.toBlob(ok,'image/png'));if(!blob)throw Error(photoError.convert);
    return await new Promise((ok,no)=>{const r=new FileReader();r.onload=()=>ok(r.result);r.onerror=()=>no(Error(photoError.read));r.readAsDataURL(blob)});
  }finally{if(bitmap?.close)bitmap.close();if(url)URL.revokeObjectURL(url)}
}
async function ensureRate(){if(usdTry&&rateDay)return {value:usdTry,snapshot_day:rateDay};const x=await getRate();if(!x?.value)throw Error('Güncel kur alınamadı. Bağlantınızı kontrol edip tekrar deneyin.');return x}
async function openProduct(id){
  await ensureRate();
  const p=id?data.products.find(x=>x.id===id):null;if(id&&!p)throw Error('Ürün bulunamadı');
  const isEdit=!!p,showCost=!isEdit||isAdmin(),cats=[...new Set(data.products.map(x=>x.category).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'tr'));
  const inside=`<label>Ürün adı<input id="pn" required autocomplete="off" value="${esc(p?.name||'')}"></label>
  <div class="formrow"><label>Barkod <small>(isteğe bağlı)</small><input id="pb" inputmode="numeric" autocomplete="off" value="${esc(p?.barcode||'')}"></label><label>Kategori<input id="pc" list="catList" autocomplete="off" value="${esc(p?.category||'')}"><datalist id="catList">${cats.map(c=>`<option value="${esc(c)}">`).join('')}</datalist></label></div>
  <label>Ürün fotoğrafı <small>(kamera veya galeriden)</small><input id="photoGallery" type="file" accept="image/*"></label>
  <div class="formrow">${isEdit?'':'<label>Başlangıç stoğu<input id="ps" type="number" min="0" step="1" value="0"></label>'}<label>Az stok uyarı eşiği<input id="low" type="number" min="0" step="1" value="${isEdit?(p.low_stock??5):Number(data.settings.low_stock_default??5)}"></label></div>
  <div class="formrow">${showCost?`<label>Adet alış maliyeti <small>(bugünkü TL)</small><input id="pcost" type="number" min="0" step="0.01" value="${isEdit?((p.cost_usd||0)*usdTry).toFixed(2):'0'}"></label>`:''}<label>Adet satış fiyatı <small>(bugünkü TL)</small><input id="price" type="number" min="0" step="1" placeholder="Örn. 25" value="${isEdit?price(p):''}"></label></div>
  <div class="hint">Alış ve satış fiyatı USD karşılığıyla kaydedilir; TL karşılığı güncel kurla hesaplanır. ${isEdit?'Maliyet değişikliği mevcut stok için yeni maliyet kabul edilir.':'Varyantları ve barkodları ayrı ürün olarak ekleyebilirsiniz.'}</div>`;
  modal(isEdit?'Ürünü düzenle':'Yeni ürün',inside,async()=>{
    const name=$('#pn').value.trim();if(!name)throw Error('Ürün adı eksik. Ürün adını girin.');
    if($('#price').value.trim()==='')throw Error('Satış fiyatını girin.');
    const rate=await ensureRate(),image=await photoData(),stock=+($('#ps')?.value||0),cost=+($('#pcost')?.value||0);
    const body={name,variant:p?.variant||'',barcode:$('#pb').value.trim(),category:$('#pc').value.trim(),price_tl:+$('#price').value,rate_day:rate.snapshot_day,image,low_stock:+$('#low').value,paid:stock*cost,payment:'Nakit'};
    if(isAdmin())body.cost_tl=cost;
    if(isEdit)await api('/api/products/'+p.id,body);else{body.stock=stock;body.cost=cost;await api('/api/products',body)}
    await load();toast(isEdit?'Ürün bilgileri güncellendi':'Ürün eklendi');
  });
}
async function deleteProduct(id){
  const p=data.products.find(x=>x.id===id);if(!p)return;
  if(!await ask(`"${productName(p)}" ürünü ve mevcut stoğu silinsin mi? Satış veya alış geçmişi olan ürün silinemez.`,{title:'Ürünü sil',confirm:'Sil',danger:true}))return;
  await api('/api/products/'+id,undefined,'DELETE');await load();toast('Ürün silindi');
}
function openStockIn(id){
  if(!data.products.length)return openProduct();
  const options=data.products.map(p=>`<option value="${p.id}"${p.id===id?' selected':''}>${esc(productName(p))} (stok ${p.stock})</option>`).join('');
  modal('Alış ve stok kaydı',`<label>Ürün<select id="si" required>${options}</select></label><div class="formrow"><label>Alınan adet<input id="sq" type="number" min="1" step="1" value="1" required></label><label>Adet alış maliyeti <small>(TL)</small><input id="sc" type="number" min="0" step="0.01" required></label></div><label>Ödeme türü<select id="purchasePay">${PAYMENTS.map(m=>`<option>${m}</option>`).join('')}</select></label><label>Not <small>(isteğe bağlı)</small><input id="purchaseNote" placeholder="İrsaliye veya alış notu"></label>`,async()=>{
    if($('#sc').value.trim()==='')throw Error('Adet alış maliyetini girin.');
    const rate=await ensureRate(),qty=+$('#sq').value,cost=+$('#sc').value;
    await api('/api/purchases',{items:[{product_id:+$('#si').value,qty,unit_cost:cost}],paid:qty*cost,payment:$('#purchasePay').value,note:$('#purchaseNote').value,rate_day:rate.snapshot_day});
    await load();toast('Alış kaydedildi, stok güncellendi');
  });
}
function openAdjustment(){
  modal('Stok düzeltme',`<label>Ürün<select id="adjp">${data.products.map(p=>`<option value="${p.id}">${esc(productName(p))} (stok ${p.stock})</option>`).join('')}</select></label><div class="formrow"><label>Adet farkı<input id="adjq" type="number" step="1" placeholder="Örn. -2 veya 3"></label><label>Açıklama<input id="adjn" placeholder="Sayım farkı / hasarlı ürün"></label></div><div class="hint">Eksi adet stoktan düşer, artı adet ekler. Değişiklik, kimin yaptığını belirten hareket kaydına eklenir.</div>`,async()=>{
    await api('/api/stock-adjustments',{product_id:+$('#adjp').value,qty:+$('#adjq').value,note:$('#adjn').value});await load();toast('Stok düzeltmesi kaydedildi');
  });
}
function openStockCount(){
  if(!isAdmin())return;
  const rows=data.products.map(p=>`<div class="count-row"><span>${esc(productName(p))} <small>· sistemde ${p.stock}</small></span><input type="number" min="0" step="1" aria-label="${esc(productName(p))} sayım adedi" data-count-product="${p.id}" value="${p.stock}"></div>`).join('');
  modal('Stok sayımı',rows+'<label>Açıklama<input id="countNote" placeholder="Örn. Aylık sayım"></label><div class="hint">Sayımı kaydedince farklar stoğa işlenir ve hareket geçmişine eklenir.</div>',async()=>{
    const fields=$$('[data-count-product]');if(fields.some(x=>x.value.trim()===''))throw Error('Tüm ürünlerin sayım adetlerini girin.');
    await api('/api/stock-counts',{items:fields.map(x=>({product_id:+x.dataset.countProduct,counted:+x.value})),note:$('#countNote').value});await load();toast('Stok sayımı kaydedildi');
  });
}

/* ── Sales (point of sale) ─────────────────────────────────────────────── */
function saleVisible(){const q=query.toLocaleLowerCase('tr');return data.products.filter(p=>p.stock>0&&(productName(p)+' '+(p.barcode||'')).toLocaleLowerCase('tr').includes(q))}
/* The search box is never re-rendered while typing; only the grid and cart are (otherwise focus is lost on every key). */
function renderSaleGrid(){
  const list=saleVisible();
  $('#saleGrid').innerHTML=list.map(p=>`<button class="producttile" type="button" data-act="addCart" data-id="${p.id}">${p.image?`<img class="productshot" src="${esc(p.image)}" alt="" loading="lazy">`:''}<b>${esc(productName(p))}</b><span>${p.stock} adet stokta</span><strong>${tl(p.price_usd)}</strong>${isAdmin()?`<small class="usd-price">$${dec4(p.price_usd)}</small>`:''}</button>`).join('')||`<div class="empty">${query?'Aramaya uyan, stokta ürün yok.':'Stokta ürün yok.'}</div>`;
  $('#saleCount').textContent=list.length+' ürün';
}
function renderCart(){
  const total=cart.reduce((a,x)=>a+x.qty*price(x.p),0);
  $('#cartRows').innerHTML=cart.length?cart.map((x,i)=>`<div class="cartrow"><div><b>${esc(productName(x.p))}</b><small>${fmt(price(x.p))} / adet</small></div><div class="qty"><button type="button" aria-label="Bir azalt" data-act="qtyDown" data-i="${i}">−</button><span aria-label="${x.qty} adet">${x.qty}</span><button type="button" aria-label="Bir artır" data-act="qtyUp" data-i="${i}">+</button></div><button class="remove" type="button" aria-label="${esc(productName(x.p))} ürününü sepetten çıkar" data-act="removeLine" data-i="${i}">×</button></div>`).join(''):'<div class="empty">Başlamak için soldan ürün seçin.</div>';
  $('#cartTotal').textContent=fmt(total);
  const bar=$('#cartBar');if(bar){bar.classList.toggle('hidden',!cart.length);$('#cartBarText').textContent=cart.reduce((a,x)=>a+x.qty,0)+' ürün, '+fmt(total)}
  const b=$('#finishBtn');b.disabled=!cart.length||saleSending;b.textContent=saleSending?'Kaydediliyor…':'Satışı tamamla';
}
function salesPage(){
  setTitle('Yeni satış');
  $('#content').innerHTML=`<div class="saleslayout"><section><div class="pagehead"><h2>Ürün seç</h2><span class="pill" id="saleCount"></span></div>${usdTry?'':'<div class="hint warn">Güncel dolar kuru alınamadı; fiyatlar gösterilemiyor ve satış yapılamaz. Bağlantıyı kontrol edin.</div>'}<div class="filterbar"><label class="sr-only" for="saleSearch">Ürün ara</label><input id="saleSearch" type="search" placeholder="Ürün ara veya barkod okut…" value="${esc(query)}" data-input="saleSearch" data-key="saleSearch" autocomplete="off"></div><div class="productsgrid" id="saleGrid"></div></section>
  <aside class="card cartcard" aria-label="Satış sepeti"><div class="sectionhead"><h2>Satış sepeti</h2><button class="textbtn" type="button" data-act="clearCart">Temizle</button></div><div id="cartRows"></div><div class="totalrow"><span>Toplam</span><strong id="cartTotal"></strong></div><label>Ödeme biçimi<select id="pay" required><option value="" selected disabled>Ödeme türü seçin</option>${PAYMENTS.map(m=>`<option value="${m}">${m}</option>`).join('')}</select></label><button class="primary full" id="finishBtn" type="button" data-act="finishSale">Satışı tamamla</button></aside></div>
  <button type="button" class="cartbar hidden" id="cartBar" data-act="toCart"><span id="cartBarText"></span><b>Sepete git ↓</b></button>`;
  renderSaleGrid();renderCart();
}
function addCart(id){
  const p=data.products.find(x=>x.id===id);if(!p||p.stock<1)return;
  const line=cart.find(x=>x.p.id===id);
  if(line){if(line.qty>=p.stock)return toast('Stokta yeterli adet yok','error');line.qty++;line.p=p}else cart.push({p,qty:1});
  renderCart();
}
function changeQty(i,d){
  const x=cart[i];if(!x)return;const fresh=data.products.find(p=>p.id===x.p.id)||x.p;
  if(d>0&&x.qty>=fresh.stock)return toast('Stokta yeterli adet yok','error');
  x.qty+=d;x.p=fresh;if(x.qty<=0)cart.splice(i,1);renderCart();
}
async function finishSale(){
  if(!cart.length||saleSending)return;
  const payment=$('#pay')?.value;if(!payment){toast('Satışı tamamlamadan önce ödeme türünü seçin','error');$('#pay')?.focus();return}
  saleSending=true;renderCart();
  try{
    const fresh=await getRate();if(!fresh?.value)throw Error('Günlük kur alınamadı; satış bekletildi.');
    const sold=cart.map(x=>({product_id:x.p.id,qty:x.qty}));
    const result=await api('/api/sales',{payment,rate_day:fresh.snapshot_day,items:sold});
    for(const s of sold){const p=data.products.find(p=>p.id===s.product_id);if(p)p.stock=Math.max(0,p.stock-s.qty)}
    cart=[];if($('#pay'))$('#pay').value='';
    toast('Satış kaydedildi: '+fmt(result.total));
    if(page==='sales'){renderSaleGrid();renderCart()}
    load();
  }catch(e){fail(e)}
  finally{saleSending=false;if(page==='sales'&&$('#cartRows'))renderCart()}
}

/* ── Reports (administrator) ───────────────────────────────────────────── */
const SALES_PAGE_SIZE=20;
function salesHistoryHtml(){
  const count=Math.max(1,Math.ceil(data.sales.length/SALES_PAGE_SIZE));reportSalesPage=Math.min(Math.max(1,reportSalesPage),count);
  const from=(reportSalesPage-1)*SALES_PAGE_SIZE,rows=data.sales.slice(from,from+SALES_PAGE_SIZE);
  if(!data.sales.length)return '<div class="empty">Satış kayıtları burada görünecek.</div>';
  const body=rows.map(s=>{
    const cancelled=s.status==='cancelled',refund=refundTotal(s),items=itemsOf(s.id);
    const units=cancelled?0:items.reduce((a,i)=>a+Math.max(0,i.qty-returnedQty(i)),0),cost=items.reduce((a,i)=>a+Math.max(0,i.qty-returnedQty(i))*(Number(i.unit_cost)||0),0);
    /* Buttons carry the sale id of THEIR OWN row (the row index must never be used to look the sale up). */
    return `<tr class="${cancelled?'cancelled':''}"><td>${shortdate(s.created)} ${clock(s.created)}${s.exchange_rate?`<small class="rowsub">Kur ${dec(s.exchange_rate)}, ${esc(s.rate_date)}</small>`:''}</td><td><span class="pill">${esc(s.payment)}</span></td><td><b>${cancelled?'—':fmt(netSale(s))}</b>${refund>0?` <small>İade ${fmt(refund)}</small>`:''}${cancelled?' <span class="pill warn">İptal</span>':''}</td><td>${units} adet</td><td>${cancelled?'—':fmt(netSale(s)-cost)}</td><td class="rowactions"><button class="textbtn" data-act="inspectSale" data-id="${s.id}">İncele</button>${cancelled?'<small>İptal edildi</small>':`<button class="textbtn" data-act="returnSale" data-id="${s.id}">İade</button><button class="textbtn danger-text" data-act="cancelSale" data-id="${s.id}">İptal et</button>`}</td></tr>`;
  }).join('');
  return `<div class="tablewrap"><table><thead><tr><th>Tarih</th><th>Ödeme</th><th>Tutar</th><th>Ürün</th><th>Tahmini kâr</th><th><span class="sr-only">İşlemler</span></th></tr></thead><tbody>${body}</tbody></table></div>
  <div class="pagination"><span>${from+1}–${Math.min(from+SALES_PAGE_SIZE,data.sales.length)} / ${data.sales.length} satış</span><div><button class="secondary" data-act="salesPageStep" data-d="-1"${reportSalesPage<=1?' disabled':''}>‹ Önceki</button><span>Sayfa ${reportSalesPage} / ${count}</span><button class="secondary" data-act="salesPageStep" data-d="1"${reportSalesPage>=count?' disabled':''}>Sonraki ›</button></div></div>`;
}
function expensesHtml(){
  return data.expenses.length?`<div class="activity">${data.expenses.slice(0,15).map(e=>`<div class="activityrow"><div class="bubble warn">−</div><div><b>${esc(e.title)}</b><small>${esc(e.payment)}, ${shortdate(e.created)}</small></div><strong>− ${fmt(e.amount)}</strong><button class="textbtn" data-act="openExpense" data-id="${e.id}">Düzenle</button><button class="textbtn danger-text" data-act="deleteExpense" data-id="${e.id}">Sil</button></div>`).join('')}</div>`:'<div class="empty">Henüz gider kaydı yok.</div>';
}
function supplierPanel(){
  const list=data.suppliers.map(v=>{const active=Number(v.active??1)===1;return `<div class="activityrow"><div class="bubble">${ico('inbox')}</div><div><b>${esc(v.name)}</b><small>${esc(v.phone||'')}${v.note?(v.phone?', ':'')+esc(v.note):''}${active?'':' (arşivde)'}</small></div>${active?`<button class="textbtn" data-act="openSupplier" data-id="${v.id}">Düzenle</button><button class="textbtn danger-text" data-act="archiveSupplier" data-id="${v.id}">Sil / arşivle</button>`:`<button class="textbtn" data-act="restoreSupplier" data-id="${v.id}">Geri al</button>`}</div>`}).join('');
  return `<section class="card section-gap"><div class="sectionhead"><h2>Tedarikçiler</h2><button class="secondary" data-act="openSupplier">${ico('plus')}Tedarikçi ekle</button></div>${list||'<div class="empty">Henüz tedarikçi yok.</div>'}</section>`;
}
function cashClosePanel(){
  const rows=data.cash_closings.map(c=>`<tr><td>${esc(c.closing_date)}</td><td>${fmt(c.counted_cash)}</td><td>${fmt(c.counted_card)}</td><td>${fmt(c.counted_iban)}</td><td>${esc(c.note||'')}</td><td class="rowactions"><button class="textbtn" data-act="openCashClose" data-day="${esc(c.closing_date)}">Düzelt</button><button class="textbtn danger-text" data-act="deleteCashClose" data-day="${esc(c.closing_date)}">Sil</button></td></tr>`).join('');
  return `<section class="card section-gap"><div class="sectionhead"><h2>Gün sonu kasa kayıtları</h2></div>${rows?`<div class="tablewrap"><table><thead><tr><th>Tarih</th><th>Nakit</th><th>Kart</th><th>IBAN</th><th>Not</th><th><span class="sr-only">İşlemler</span></th></tr></thead><tbody>${rows}</tbody></table></div>`:'<div class="empty">Henüz kasa kapanışı yok.</div>'}</section>`;
}
function reportsPage(){
  const days=reportPeriod,label=days===1?'Bugün':days===7?'Son 7 gün':'Son 30 gün',st=periodStats(days),series=dailySeries(days),best=[...st.units].sort((a,b)=>b[1]-a[1]).slice(0,5),bestMax=Math.max(1,...best.map(x=>x[1]));
  setTitle('Kayıtlar ve raporlar');
  $('#content').innerHTML=`<div class="pagehead"><h2>İşletme özeti</h2><div class="actions"><button class="secondary" data-act="exportData">${ico('download')}Yedek indir</button><button class="primary" data-act="makeBackup">${ico('refresh')}Şimdi yedekle</button><button class="secondary" data-act="pickRestore">${ico('upload')}Yedek geri yükle</button><input type="file" id="restoreFile" accept=".json,.gz,application/json,application/gzip" data-change="restoreBackup" hidden></div></div>
  <section class="grid stats">${statCard('Toplam ciro',fmt(st.revenue),label)}${statCard('Tahmini brüt kâr',fmt(st.gross),'Ciro − ürün alış maliyetleri')}${statCard('Kayıtlı gider',fmt(st.expense),label)}${statCard('Tahmini net',fmt(st.net),'Brüt kâr − giderler')}</section>
  <section class="card section-gap"><div class="sectionhead"><h2>Satış ve alış grafiği</h2><div class="sectionctl"><div class="trend-legend"><span><i></i>Satış</span><span><i class="legend-buy"></i>Alış</span></div><select aria-label="Rapor dönemi" data-change="reportPeriod">${[1,7,30].map(v=>`<option value="${v}"${v===reportPeriod?' selected':''}>${v===1?'Günlük':v===7?'Haftalık':'Aylık'}</option>`).join('')}</select></div></div><div class="chart trend-chart" style="--chart-count:${series.length}" role="img" aria-label="${esc(chartSummary(series,label+' satışları'))}">${barsHtml(series,{buy:true})}</div><div class="trend-note">${series.some(x=>x.sales||x.buy)?'Satış ve alış tutarları TL cinsindendir.':'Seçilen dönemde henüz satış veya alış kaydı yok.'}</div></section>
  <section class="card section-gap"><div class="sectionhead"><h2>Satış geçmişi</h2><span class="pill">${data.sales.length} kayıt</span></div>${salesHistoryHtml()}</section>
  <section class="grid two section-gap"><div class="card"><div class="sectionhead"><h2>Giderler</h2><button class="secondary" data-act="openExpense">${ico('plus')}Gider ekle</button></div>${expensesHtml()}</div><div class="card"><div class="sectionhead"><h2>Ödeme biçimine göre ciro</h2></div>${paymentDonut(paymentTotals(st.sales))}</div></section>
  <section class="card section-gap"><div class="sectionhead"><h2>En çok satılan ürünler</h2><span class="pill">${label}</span></div>${best.length?`<div class="top-product-list">${best.map(([name,qty])=>`<div class="top-product-row"><span>${esc(name)}</span><div class="top-product-track"><i style="width:${Math.max(4,qty/bestMax*100)}%"></i></div><b>${qty} adet</b></div>`).join('')}</div>`:'<div class="empty">Bu dönemde satış yok.</div>'}</section>
  ${supplierPanel()}${cashClosePanel()}
  <div class="hint">Dönem raporları sunucudan gelen son kayıtlara göre hesaplanır (yaklaşık son 35 gün ve en yeni satışlar). Yedekler sunucuda tutulur; indirdiğiniz yedek dosyasını Drive hesabınıza da yükleyebilirsiniz.</div>`;
}
function inspectSale(id){
  const s=data.sales.find(x=>Number(x.id)===Number(id));if(!s)return;
  const returns=(data.sale_returns||[]).filter(r=>Number(r.sale_id)===Number(id));
  const rows=itemsOf(id).map(i=>`<div class="activityrow"><div><b>${esc(i.name)}</b><small>Satılan ${i.qty}, iade ${returnedQty(i)}, kalan ${Math.max(0,i.qty-returnedQty(i))} adet</small></div><strong>${fmt((Number(i.unit_price)||0)*Number(i.qty||0))}</strong></div>`).join('');
  modal('Satış ayrıntısı, #'+id,`<p><b>${shortdate(s.created)} ${clock(s.created)}</b>, ${esc(s.payment)}. Toplam ${fmt(s.total)}, iade ${fmt(refundTotal(s))}.</p><p>Durum: ${s.status==='cancelled'?'<b>İptal edildi</b>':'Tamamlandı'}</p>${s.cancel_reason?`<p>İptal nedeni: ${esc(s.cancel_reason)}</p>`:''}${s.cancelled_at?`<p>İptal zamanı: ${shortdate(s.cancelled_at)} ${clock(s.cancelled_at)}</p>`:''}<h3>Satılan ürünler</h3><div class="activity">${rows||'<div class="empty">Ürün kaydı yok.</div>'}</div>${returns.length?'<h3>İadeler</h3>'+returns.map(r=>`<p>${esc(r.reason||'İade')}: ${fmt(r.total)}</p>`).join(''):''}${s.status==='cancelled'?'<div class="hint">İptal edildi; ciroya ve raporlara dahil değil.</div>':''}`,()=>{},'Kapat',{cancel:false});
}
async function cancelSale(id){
  const reason=await ask('Ürünler stoğa geri eklenecek ve satış rapordan düşecek. İptal nedenini yazın.',{title:`Satış #${id} iptal edilsin mi?`,confirm:'Satışı iptal et',danger:true,field:{label:'İptal nedeni',value:'Hatalı satış'}});
  if(reason===null)return;
  await api('/api/sales/'+id+'/cancel',{reason});await load();toast('Satış iptal edildi, ürünler stoğa döndü');
}
function returnSale(id){
  const fields=itemsOf(id).map(i=>{const left=i.qty-returnedQty(i);return `<label>${esc(i.name)} (kalan ${left} adet)<input type="number" min="0" max="${left}" step="1" value="0" data-return-item="${i.id}"></label>`}).join('');
  modal('Ürün iadesi, satış #'+id,fields+'<label>İade nedeni<input id="returnReason" required placeholder="Örn. yanlış ürün"></label><div class="hint">İade adedi stoğa eklenir; ciro ve tahmini kâr güncellenir. İşlem geçmişte kalır.</div>',async()=>{
    const items=$$('[data-return-item]').map(x=>({sale_item_id:+x.dataset.returnItem,qty:+x.value})).filter(x=>x.qty>0);
    if(!items.length)throw Error('İade edilecek en az bir adet girin.');
    await api('/api/sales/'+id+'/return',{items,reason:$('#returnReason').value});await load();toast('İade kaydedildi');
  });
}

/* ── Expenses, cash close, suppliers ───────────────────────────────────── */
function openExpense(id){
  const e=id?data.expenses.find(x=>x.id===id):null;if(id&&!e)throw Error('Gider kaydı bulunamadı.');
  modal(e?'Gideri düzenle':'Gider ekle',`<label>Gider açıklaması<input id="et" value="${esc(e?.title||'')}" placeholder="Örn. Dükkân kirası"></label><div class="formrow"><label>Tutar <small>(TL)</small><input id="ea" type="number" min="1" step="1" value="${e?.amount||''}" required></label><label>Ödeme biçimi<select id="ep">${PAYMENTS.map(m=>`<option${e?.payment===m?' selected':''}>${m}</option>`).join('')}</select></label></div>`,async()=>{
    await api(e?'/api/expenses/'+e.id:'/api/expenses',{title:$('#et').value||'Gider',amount:+$('#ea').value,payment:$('#ep').value});await load();toast(e?'Gider güncellendi':'Gider kaydedildi');
  },e?'Değişiklikleri kaydet':'Kaydet');
}
async function deleteExpense(id){
  const e=data.expenses.find(x=>x.id===id);if(!e)return;
  if(!await ask(`"${e.title}" gider kaydı silinsin mi?`,{title:'Gideri sil',confirm:'Sil',danger:true}))return;
  await api('/api/expenses/'+id,undefined,'DELETE');await load();toast('Gider kaydı silindi');
}
async function openCashClose(day=''){
  const x=await api('/api/cash-close'+(day?'?date='+encodeURIComponent(day):'')),e=x.expected,c=x.closing,date=x.date,field={Nakit:'cash',Kart:'card',IBAN:'iban'};
  const fields=PAYMENTS.map(k=>`<label>${k} (beklenen ${fmt(e[k])})<input type="number" min="0" step="1" data-count-pay="${k}" placeholder="Gerçekte sayılan tutar" value="${c?c['counted_'+field[k]]:''}" required></label>`).join('');
  const del=c&&isAdmin()?`<button class="secondary danger-text" type="button" data-act="deleteCashClose" data-day="${esc(date)}">Bu kapanışı sil</button>`:'';
  modal((c?'Gün sonu kasasını düzelt: ':'Gün sonu kasa kontrolü: ')+date,`<div class="hint">Beklenen tutar satışlardan; giderler ve peşin alışlar düşülerek hesaplandı. ${c?'Önceden girilmiş sayımı düzeltiyorsunuz.':''}</div>${fields}<label>Not <small>(isteğe bağlı)</small><input id="cashNote" value="${esc(c?.note||'')}" placeholder="Kasa farkı açıklaması"></label>${del}`,async()=>{
    const inputs=$$('[data-count-pay]');if(inputs.some(i=>i.value.trim()===''))throw Error('Nakit, kart ve IBAN için sayılan tutarları girin.');
    const counted={};inputs.forEach(i=>counted[i.dataset.countPay]=+i.value);
    const r=await api('/api/cash-close',{date,counted,note:$('#cashNote').value});await load();
    toast('Kasa kaydedildi. Fark: '+Object.entries(r.difference).map(([k,v])=>k+' '+fmt(v)).join(' / '));
  },c?'Düzeltmeyi kaydet':'Günü kapat');
}
async function deleteCashClose(day){
  if(!await ask(day+' tarihli gün sonu kasa kaydı silinsin mi?',{title:'Kasa kaydını sil',confirm:'Sil',danger:true}))return;
  await api('/api/cash-close?date='+encodeURIComponent(day),undefined,'DELETE');closeLayer($('#modal'));await load();toast('Gün sonu kaydı silindi');
}
function openSupplier(id){
  const v=id?data.suppliers.find(x=>x.id===id):null;if(id&&!v)throw Error('Tedarikçi bulunamadı.');
  modal(v?'Tedarikçiyi düzenle':'Tedarikçi ekle',`<label>Tedarikçi adı<input id="supplierNewName" required value="${esc(v?.name||'')}"></label><label>Telefon<input id="supplierPhone" type="tel" value="${esc(v?.phone||'')}"></label><label>Not<input id="supplierNote" value="${esc(v?.note||'')}"></label>`,async()=>{
    await api(v?'/api/suppliers/'+v.id:'/api/suppliers',{name:$('#supplierNewName').value,phone:$('#supplierPhone').value,note:$('#supplierNote').value});await load();toast(v?'Tedarikçi güncellendi':'Tedarikçi kaydedildi');
  },v?'Değişiklikleri kaydet':'Kaydet');
}
async function archiveSupplier(id){
  const v=data.suppliers.find(x=>x.id===id);if(!v)return;
  if(!await ask(`"${v.name}" listeden kaldırılsın (arşivlensin) mi? Alış geçmişi korunur.`,{title:'Tedarikçiyi arşivle',confirm:'Arşivle',danger:true}))return;
  await api('/api/suppliers/'+id,undefined,'DELETE');await load();toast('Tedarikçi arşivlendi; geçmiş korundu');
}
async function restoreSupplier(id){await api('/api/suppliers/'+id+'/restore',{});await load();toast('Tedarikçi geri alındı')}

/* ── Staff accounts (administrator) ────────────────────────────────────── */
async function staffPage(){
  if(!isAdmin())return;
  setTitle('Çalışan hesapları');
  $('#content').innerHTML=`<div class="pagehead"><h2>Ekibiniz</h2><button class="primary" data-act="openUser">${ico('plus')}Hesap ekle</button></div><div class="hint">Satış ve stok işlemleri kullanıcıyla kaydedilir. Kâr, ciro raporları, giderler ve yedekler yöneticiye özeldir.</div><div id="userlist" class="card"><div class="empty">Hesaplar yükleniyor…</div></div>
  <div class="card section-gap"><div class="sectionhead"><h2>Son hareketler</h2><small>Son 100 işlem</small></div>${data.activity?.length?`<div class="activity">${data.activity.map(a=>`<div class="activityrow"><div class="bubble">${ico('check')}</div><div><b>${esc(a.name||'Hesap kapalı')}: ${esc(a.action)}</b><small>${a.details?esc(a.details)+', ':''}${shortdate(a.created)} ${clock(a.created)}</small></div></div>`).join('')}</div>`:'<div class="empty">Henüz hareket yok.</div>'}</div>`;
  try{
    const {users}=await api('/api/users');if(page!=='staff'||!$('#userlist'))return;
    $('#userlist').innerHTML=`<div class="tablewrap"><table><thead><tr><th>Ad</th><th>Kullanıcı adı</th><th>Rol</th><th>Durum</th><th><span class="sr-only">Hesap işlemleri</span></th></tr></thead><tbody>${users.map(x=>`<tr><td>${esc(x.name)}</td><td>${esc(x.username)}</td><td>${roleName(x.role)}</td><td><span class="pill${x.active?'':' warn'}">${x.active?'Etkin':'Kapalı'}</span></td><td class="rowactions">${x.id!==user.id?`<button class="textbtn" data-act="resetUserPassword" data-id="${x.id}">Parola yenile</button><button class="textbtn${x.active?' danger-text':''}" data-act="toggleUser" data-id="${x.id}">${x.active?'Hesabı kapat':'Hesabı aç'}</button>`:'<small>Siz</small>'}</td></tr>`).join('')}</tbody></table></div>`;
  }catch(e){fail(e)}
}
function openUser(){
  modal('Yeni hesap',`<label>Çalışanın adı<input id="un" required></label><label>Kullanıcı adı<input id="uu" minlength="3" autocapitalize="none" required></label><label>Hesap türü<select id="ur"><option value="cashier">Satış elemanı</option><option value="admin">Yönetici</option></select></label><label>Geçici parola <small>(en az 10 karakter)</small><input id="up" type="password" minlength="10" autocomplete="new-password" required></label><div class="hint">Satış elemanı satış ve stok işlemi yapar. Yönetici kâr, ciro, gider, yedek ve hesap yönetimini de görebilir.</div>`,async()=>{
    await api('/api/users',{name:$('#un').value,username:$('#uu').value,role:$('#ur').value,password:$('#up').value});await load();toast('Hesap oluşturuldu');
  },'Hesabı oluştur');
}
async function toggleUser(id){
  if(!await ask('Hesabın durumu değiştirilsin mi? Geçmiş hareketler korunur.',{title:'Hesap durumu',confirm:'Değiştir'}))return;
  await api('/api/users/'+id+'/toggle',{});await load();toast('Hesap durumu güncellendi');
}
function resetUserPassword(id){
  modal('Çalışan parolasını yenile',`<label>Yeni geçici parola<input id="newpass" type="password" minlength="10" autocomplete="new-password" required></label><div class="hint">Yeni parolayı çalışana güvenli yoldan iletin. Bu işlem çalışanın açık oturumlarını kapatır.</div>`,async()=>{
    await api('/api/users/'+id+'/password',{password:$('#newpass').value});toast('Parola yenilendi');
  },'Parolayı yenile');
}

/* ── Settings, backup, themes ──────────────────────────────────────────── */
/* [accent, accent-soft, background, card, text, line, muted text, text-on-accent ('' = pick white or dark automatically), label, optional text contrast target].
   applyTheme() nudges muted and accent text until they reach the contrast target (WCAG AA 4.5:1; 7:1 for "Yüksek kontrast"),
   so a new theme can never ship with unreadable text. */
const THEMES={
  sage:['#3b684e','#e8efe8','#f7f5f0','#fffefa','#252a25','#ecebe4','#666b63','#ffffff','Adaçayı'],
  ocean:['#28647a','#e3f0f4','#eff7f8','#fbffff','#203139','#dce9eb','#5a6c73','#ffffff','Deniz'],
  rose:['#a44f63','#f7e8ec','#fbf1f2','#fffafa','#35272b','#f0dfe3','#786469','#ffffff','Gül'],
  plum:['#704d83','#efe8f5','#f6f1f8','#fffaff','#2f2933','#e7deec','#6f6475','#ffffff','Erik'],
  sand:['#9a6234','#f5eadb','#f8f1e8','#fffdf8','#342d25','#eee2d1','#71675b','#ffffff','Kum'],
  night:['#8fc69e','#294337','#17231e','#202f28','#f1f5f2','#35463d','#b1c0b6','#0f2318','Gece'],
  lavender:['#7459a6','#ede8f8','#f5f2fa','#fdfbff','#2c2634','#e5deef','#6e6478','#ffffff','Lavanta'],
  mint:['#34786f','#e5f3ef','#f1f8f5','#fbfffd','#263432','#dcece6','#5c706b','#ffffff','Nane'],
  coral:['#b45b49','#fae9e4','#fcf3f0','#fffdfc','#392c29','#f0dfda','#7a6560','#ffffff','Mercan'],
  sky:['#46749b','#e6f0f8','#f2f7fb','#fcfeff','#28323b','#dce7ef','#5d6c79','#ffffff','Gökyüzü'],
  peach:['#b9470e','#fde9dc','#fff5ee','#fffdfb','#3a2a22','#f2dfd2','#7a6258','','Şeftali'],
  olive:['#5b6a1a','#edf0d9','#f6f7ec','#fdfdf7','#2b2d1c','#e4e8cd','#6b6e52','','Zeytin'],
  coffee:['#7a4a2b','#f0e4d8','#f8f1ea','#fffcf8','#33261d','#e9dacb','#7d6a5c','','Kahve'],
  fuchsia:['#b0246f','#fbe3f0','#fdf0f6','#fffafc','#3a2230','#f3d9e6','#7e6272','','Fuşya'],
  teal:['#0f766e','#d9f2ee','#ecf8f6','#fbfffe','#1d3330','#cfe9e4','#587570','','Turkuaz'],
  saffron:['#9a5b00','#faeccb','#fdf6e4','#fffdf7','#352a12','#f0e2bd','#756a4a','','Safran'],
  burgundy:['#8c1d40','#f5e0e7','#faf0f3','#fffcfc','#34202a','#ecd6dd','#7a626a','','Bordo'],
  slate:['#334155','#e4e9f0','#f2f4f8','#ffffff','#1e2530','#dbe1ea','#5a6575','','Çelik'],
  coal:['#f2b880','#3a2f27','#1c1917','#262220','#f5efe9','#3b3532','#b9aea4','','Kömür'],
  /* High contrast: pure black on white (or white on black), black outlines and separators, 7:1 text. Several accent colours. */
  contrast:['#0a3d91','#e6eefc','#ffffff','#ffffff','#000000','#000000','#1c1c1c','','Mavi',7],
  'contrast-green':['#0b5d1e','#e3f5e8','#ffffff','#ffffff','#000000','#000000','#1c1c1c','','Yeşil',7],
  'contrast-red':['#a30000','#fde8e8','#ffffff','#ffffff','#000000','#000000','#1c1c1c','','Kırmızı',7],
  'contrast-purple':['#5b1fa6','#efe6fb','#ffffff','#ffffff','#000000','#000000','#1c1c1c','','Mor',7],
  'contrast-orange':['#8a3b00','#fdeee0','#ffffff','#ffffff','#000000','#000000','#1c1c1c','','Turuncu',7],
  'contrast-yellow':['#ffd400','#2a2400','#000000','#000000','#ffffff','#9a9a9a','#ececec','','Sarı',7],
  'contrast-cyan':['#00e5ff','#00262b','#000000','#000000','#ffffff','#9a9a9a','#ececec','','Turkuaz',7],
  'contrast-lime':['#66ff66','#052b05','#000000','#000000','#ffffff','#9a9a9a','#ececec','','Limon',7],
  'contrast-pink':['#ff7ad9','#2b0a22','#000000','#000000','#ffffff','#9a9a9a','#ececec','','Pembe',7]
};
const mixHex=(a,b,t)=>{const p=h=>[1,3,5].map(i=>parseInt(h.slice(i,i+2),16));const x=p(a),y=p(b);return '#'+x.map((v,i)=>Math.round(v+(y[i]-v)*t).toString(16).padStart(2,'0')).join('')};
const lum=h=>{const [r,g,b]=[1,3,5].map(i=>parseInt(h.slice(i,i+2),16)/255).map(v=>v<=.03928?v/12.92:((v+.055)/1.055)**2.4);return .2126*r+.7152*g+.0722*b};
const contrast=(a,b)=>{const x=lum(a),y=lum(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05)};
/* Nudges `color` toward `toward` until it reaches `min` contrast on every surface (WCAG: 4.5 text, 3 for control borders). */
function readable(color,surfaces,min,toward){let c=color;for(let t=.04;t<=1&&Math.min(...surfaces.map(s=>contrast(c,s)))<min;t+=.04)c=mixHex(color,toward,t);return c}
function applyTheme(name,{persist=true}={}){
  const key=THEMES[name]?name:'lavender',t=THEMES[key],root=document.documentElement,surfaces=[t[1],t[3],t[2]],set=(k,v)=>root.style.setProperty(k,v);
  const dark=lum(t[2])<.2,target=t[9]||4.5,onAccent=t[7]||(contrast('#ffffff',t[0])>=contrast('#0f1a14',t[0])?'#ffffff':'#0f1a14');
  ['--accent','--accent-soft','--bg','--paper','--ink','--line'].forEach((v,i)=>set(v,t[i]));set('--on-accent',onAccent);
  set('--muted',readable(t[6],surfaces,target,t[4]));                           /* secondary text */
  set('--accent-text',readable(t[0],surfaces,target,t[4]));                     /* accent used as text colour */
  set('--field',target>4.5?t[4]:readable(mixHex(t[3],t[4],.5),[t[3],t[2]],3,t[4]));   /* input / secondary-button borders (full black or white in high contrast) */
  set('--accent-strong',dark?mixHex(t[0],'#ffffff',.14):mixHex(t[0],'#000000',.16));
  root.dataset.theme=key;root.dataset.tone=dark?'dark':'light';root.dataset.hc=target>4.5?'1':'0';$('meta[name=theme-color]')?.setAttribute('content',t[2]);
  if(persist)store.set('ilmek-theme',key);
  $$('.swatch').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.theme===key)));
}
function settingsPage(){
  const admin=isAdmin(),x=data.settings,current=store.get('ilmek-theme')||x.default_theme||'lavender';
  setTitle('Dükkân ayarları');
  const groups={'Açık':[],'Koyu':[],'Yüksek kontrast, açık zemin':[],'Yüksek kontrast, siyah zemin':[]};
  for(const [k,t] of Object.entries(THEMES))groups[t[9]>4.5?(lum(t[2])<.2?'Yüksek kontrast, siyah zemin':'Yüksek kontrast, açık zemin'):lum(t[2])<.2?'Koyu':'Açık'].push(`<button type="button" class="swatch" data-act="theme" data-theme="${k}" aria-pressed="${k===current}" aria-label="${t[8]} teması"><span class="swatch-chip" style="background:linear-gradient(135deg,${t[0]} 50%,${t[2]} 50%);border-color:${t[5]}"></span><span>${t[8]}</span></button>`);
  const swatches=Object.entries(groups).map(([g,list])=>`<div class="swatch-group"><span class="swatch-title">${g}</span><div class="swatches">${list.join('')}</div></div>`).join('');
  $('#content').innerHTML=`<div class="pagehead"><h2>Hesap ve panel ayarları</h2></div><div class="settings-grid">
  <section class="card settings-card"><h3>Kişisel tercihler</h3><div class="account-summary"><b>${esc(user.name)}</b><span>${roleName(user.role)} hesabı</span></div><div class="field"><span class="field-label" id="themeLabel">Bu cihazın teması</span><div role="group" aria-labelledby="themeLabel">${swatches}</div></div><p class="settings-help">Tema bu cihazda hemen uygulanır${admin?'; "Dükkân ayarlarını kaydet" derseniz yeni cihazlar için varsayılan olur':''}.</p><div class="settings-actions"><button class="secondary" data-act="changePassword">${ico('key')}Parolamı değiştir</button><button class="secondary danger-text" data-act="logout">${ico('logout')}Oturumu kapat</button></div></section>
  ${admin?`<section class="card settings-card"><h3>Dükkân ve rapor ayarları</h3><label>Dükkân adı<input id="settingShopName" maxlength="80" value="${esc(x.shop_name||'İlmek')}" required></label><label>Yeni ürünler için düşük stok uyarı eşiği<input id="settingLowStock" type="number" min="0" max="100000" step="1" value="${Number(x.low_stock_default??5)}" required></label><label>Raporlar ilk açıldığında gösterilecek dönem<select id="settingPeriod">${[1,7,30].map(v=>`<option value="${v}"${Number(x.report_period||7)===v?' selected':''}>${v===1?'Günlük':v===7?'Haftalık':'Aylık'}</option>`).join('')}</select></label><p class="settings-help">Dolar kuru TCMB'den günlük alınır; TL fiyatlar tam liraya yuvarlanır. Satışta ödeme yöntemi zorunludur.</p><div class="settings-actions"><button class="primary" data-act="saveSettings">Dükkân ayarlarını kaydet</button><button class="secondary" data-act="go" data-page="staff">Çalışan hesapları</button></div></section>`:''}</div>`;
}
async function saveSettings(){
  const body={shop_name:$('#settingShopName').value.trim(),low_stock_default:+$('#settingLowStock').value,default_theme:store.get('ilmek-theme')||data.settings.default_theme||'lavender',report_period:+$('#settingPeriod').value};
  const r=await api('/api/settings',body);data.settings={...data.settings,...r.settings};reportPeriod=Number(r.settings.report_period)||7;
  $('#shopName').textContent=r.settings.shop_name;toast('Ayarlar kaydedildi');settingsPage();
}
async function makeBackup(){const r=await api('/api/backup');toast(r.drive?.message||`Yedek alındı, ${r.count} yedek tutuluyor`)}
async function exportData(){
  const json=await api('/api/export'),a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([JSON.stringify(json,null,2)],{type:'application/json'}));a.download='ilmek-yedek-'+todaykey+'.json';a.click();
  setTimeout(()=>URL.revokeObjectURL(a.href),1000);toast('Yedek dosyası indirildi');
}
async function restoreBackup(input){
  const file=input.files?.[0];if(!file)return;
  try{
    if(!await ask('Yedeği geri yükleyince mevcut ürün, satış, alış ve rapor verileri yedektekiyle değişecek. Önce otomatik bir güvenlik yedeği alınır.',{title:'Yedek geri yüklensin mi?',confirm:'Geri yükle',danger:true}))return;
    let text;
    if(file.name.toLowerCase().endsWith('.gz')){if(!window.DecompressionStream)throw Error('Bu tarayıcı .gz yedeğini açamıyor; JSON yedeğini seçin.');text=await new Response(file.stream().pipeThrough(new DecompressionStream('gzip'))).text()}
    else text=await file.text();
    let backup;try{backup=JSON.parse(text)}catch{throw Error('Yedek dosyası okunamadı; dosya bozuk olabilir.')}
    await api('/api/restore',{backup});await load();toast('Yedek geri yüklendi');
  }finally{input.value=''}
}

/* ── Event delegation ──────────────────────────────────────────────────── */
const actions={
  go:el=>go(el.dataset.page),retryStart:()=>start(),closeModal:()=>closeLayer($('#modal')),
  openProduct:el=>openProduct(el.dataset.id?+el.dataset.id:undefined),deleteProduct:el=>deleteProduct(+el.dataset.id),
  openStockIn:()=>openStockIn(),openStockCount:()=>openStockCount(),openAdjustment:()=>openAdjustment(),
  openExpense:el=>openExpense(el.dataset.id?+el.dataset.id:undefined),deleteExpense:el=>deleteExpense(+el.dataset.id),
  openCashClose:el=>openCashClose(el.dataset.day||''),deleteCashClose:el=>deleteCashClose(el.dataset.day),
  addCart:el=>addCart(+el.dataset.id),qtyUp:el=>changeQty(+el.dataset.i,1),qtyDown:el=>changeQty(+el.dataset.i,-1),removeLine:el=>{cart.splice(+el.dataset.i,1);renderCart()},
  clearCart:()=>{cart=[];renderCart()},finishSale:()=>finishSale(),
  toCart:()=>$('.cartcard')?.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'}),
  inspectSale:el=>inspectSale(+el.dataset.id),cancelSale:el=>cancelSale(+el.dataset.id),returnSale:el=>returnSale(+el.dataset.id),
  salesPageStep:el=>{reportSalesPage+=Number(el.dataset.d);reportsPage()},
  openSupplier:el=>openSupplier(el.dataset.id?+el.dataset.id:undefined),archiveSupplier:el=>archiveSupplier(+el.dataset.id),restoreSupplier:el=>restoreSupplier(+el.dataset.id),
  openUser:()=>openUser(),resetUserPassword:el=>resetUserPassword(+el.dataset.id),toggleUser:el=>toggleUser(+el.dataset.id),
  changePassword:()=>changePassword(),logout:()=>logout(),saveSettings:()=>saveSettings(),theme:el=>applyTheme(el.dataset.theme),
  exportData:()=>exportData(),makeBackup:()=>makeBackup(),pickRestore:()=>$('#restoreFile').click()
};
const inputs={
  saleSearch(el){query=el.value;renderSaleGrid()},
  stockSearch(el){query=el.value;$('#stockBody').innerHTML=stockRowsHtml()}
};
const changes={reportPeriod(el){reportPeriod=+el.value;reportsPage()},restoreBackup(el){return restoreBackup(el)}};
const keys={
  saleSearch(e){
    if(e.key!=='Enter')return;const v=e.target.value.trim().toLocaleLowerCase('tr'),p=data.products.find(x=>x.barcode&&x.barcode.toLocaleLowerCase('tr')===v);
    if(!p)return;e.preventDefault();
    if(p.stock>0){e.target.value='';query='';renderSaleGrid();addCart(p.id)}else toast('Bu ürünün stoğu yok','error');
  },
  stockSearch(e){
    if(e.key!=='Enter')return;const v=e.target.value.trim().toLocaleLowerCase('tr'),p=data.products.find(x=>x.barcode&&x.barcode.toLocaleLowerCase('tr')===v);
    if(p){e.preventDefault();query=productName(p);e.target.value=query;$('#stockBody').innerHTML=stockRowsHtml()}
  }
};
const run=(fn,...args)=>{try{Promise.resolve(fn(...args)).catch(fail)}catch(e){fail(e)}};
document.addEventListener('click',e=>{const el=e.target.closest('[data-act]');if(!el||el.disabled)return;const fn=actions[el.dataset.act];if(fn){e.preventDefault();run(fn,el)}});
document.addEventListener('input',e=>{const k=e.target.dataset?.input;if(k&&inputs[k])inputs[k](e.target)});
document.addEventListener('change',e=>{const k=e.target.dataset?.change;if(k&&changes[k])run(changes[k],e.target)});
document.addEventListener('keydown',e=>{const k=e.target.dataset?.key;if(k&&keys[k])keys[k](e)});

/* ── Start ─────────────────────────────────────────────────────────────── */
applyTheme(store.get('ilmek-theme')||'lavender',{persist:false});
$('#brandIcon').innerHTML=ico('yarn');
start();
