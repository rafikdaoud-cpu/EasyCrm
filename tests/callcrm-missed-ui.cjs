const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync('callcrm/js/app.js','utf8');
const part=(from,to)=>source.slice(source.indexOf(from),source.indexOf(to));
const code=part('const h =','async function api(')+part('/* ───────── Appels manqués','/* ───────── Simulateur de devis');
const tick=()=>new Promise(r=>setTimeout(r,20));
const now=Math.floor(Date.now()/1000);
const rows=[{id:1,phone:'0770000001',count:3,first_at:now-7200,last_at:now-600,attempts:0,first_attempt_at:0,prospect_id:42,name:'<b>Benali</b>',assigned_to:'alice',assigned_name:'Alice'},
 {id:2,phone:'0770000002',count:1,first_at:now-300,last_at:now-300,attempts:1,first_attempt_at:now-100,prospect_id:null,name:'',assigned_to:null,assigned_name:''},
 {id:3,phone:'0770000003',count:1,first_at:now-900,last_at:now-900,attempts:0,first_attempt_at:0,prospect_id:7,name:'Autre',assigned_to:'bob',assigned_name:'Bob'}];
const setup=(role,extra={})=>{
 const dom=new JSDOM('<main id="r"><aside class="crm-sidebar"><button data-k="journee"></button><button data-k="manques"></button></aside></main>',{url:'https://cloud.example.test'}),document=dom.window.document,root=document.querySelector('main');
 const calls=[],opened=[],created=[],went=[];
 const ctx=vm.createContext({document,window:dom.window,Node:dom.window.Node,root,setTimeout,URLSearchParams,fmt:String,alert:()=>{},API:'/api',
  me:{role,uid:role==='commercial'?'alice':'sam',can:{call:true},commerciaux:[{uid:'alice',name:'Alice'},{uid:'bob',name:'Bob'}],prefs:{goal:20}},
  detail:id=>opened.push(String(id)),addForm:(p,n)=>created.push([p,n]),go:k=>went.push(k),show:(...n)=>root.append(...n.flat()),
  api:async(path,opt)=>{calls.push([path,opt&&JSON.parse(JSON.stringify(opt.body||null))]);
   if(path.startsWith('/missed?')||path==='/missed')return{rows:role==='commercial'?rows.filter(r=>r.assigned_to==='alice'||!r.assigned_to):rows,stats:{missed:5,done:2,open:3,by:{callback:1,client_recalled:1},attempted:2,avg_delay:1800,median_delay:900},commercials:role==='commercial'?null:{alice:'Alice',bob:'Bob'}};
   if(path==='/goals')return{rows:[{uid:'alice',name:'Alice',day:30,week:150,personal:true,today:12,this_week:90},{uid:'bob',name:'Bob',day:20,week:0,personal:false,today:25,this_week:25}],default:20};
   if(path.startsWith('/prospects?')){const q=new URLSearchParams(path.split('?')[1]);return q.get('rappels')?[{id:9,assigned_to:'alice',callback_at:now-60},{id:10,assigned_to:'alice',callback_at:now+3600}]:[{id:11},{id:42}];}
   if(extra.fail)throw new Error('Cet appel a déjà été pris par un collègue.');return{ok:true};},...extra.ctx});
 vm.runInContext(code,ctx);return{dom,document,root,ctx,calls,opened,created,went};
};

test('commercial sees own missed calls, can take the unassigned ones, call back and close',async()=>{
 const t=setup('commercial');const card=vm.runInContext('missedCard()',t.ctx);t.root.append(card);await tick();
 const mine=card.querySelectorAll(':scope > div > .crm-missed-row');assert.equal(mine.length,1);
 assert.match(mine[0].textContent,/3 appels manqués/);assert.equal(mine[0].querySelector('b'),null,'client name is text');
 assert.equal(card.querySelector('.crm-count').textContent,'1');
 assert.equal(t.root.querySelector('[data-k="journee"] .ovd').textContent,'1','sidebar badge');
 const pool=card.querySelector('.crm-missed-pool');assert.match(pool.querySelector('summary').textContent,/1 appel manqué à prendre/);
 assert.equal(pool.querySelector('.crm-btn'),null,'cannot call back before taking it');
 pool.querySelector('button').click();await tick();assert.ok(t.calls.some(([p])=>p==='/missed/2/take'));
 const link=mine[0].querySelector('a.crm-btn');assert.equal(link.getAttribute('href'),'tel:0770000001');
 link.dispatchEvent(new t.dom.window.MouseEvent('click',{bubbles:true,cancelable:true}));await tick();assert.deepEqual(t.opened,['42']);
 const how=mine[0].querySelector('select');how.value='manual_wrong';how.dispatchEvent(new t.dom.window.Event('change'));await tick();
 assert.equal(JSON.stringify(t.calls.find(([p])=>p==='/missed/1/close')[1]),'{"how":"manual_wrong"}');
});
test('unknown number call back opens the new client form prefilled',async()=>{
 const t=setup('commercial');const r=rows[1];const a=vm.runInContext('missedCallLink',t.ctx)({...r,assigned_to:'alice'});t.root.append(a);
 a.dispatchEvent(new t.dom.window.MouseEvent('click',{bubbles:true,cancelable:true}));await tick();
 assert.equal(t.created[0][0].phone,'0770000002');assert.match(t.created[0][1],/Appel manqué/);
});
test('manager page shows indicators, filters the pool and reassigns',async()=>{
 const t=setup('superviseur');vm.runInContext('missedPage()',t.ctx);await tick();
 const k=Array.from(t.root.querySelectorAll('.crm-kpi'),x=>x.textContent);
 assert.ok(k.some(x=>/En attente3/.test(x)));assert.ok(k.some(x=>/Délai moyen avant rappel30 min/.test(x)));assert.ok(k.some(x=>/1 rappelés · 1 ont rappelé/.test(x)));
 assert.equal(t.root.querySelectorAll('.crm-missed-row').length,3);
 assert.equal(t.root.querySelector('[data-k="manques"] .ovd').textContent,'1','badge counts unassigned');
 const who=t.root.querySelector('select[aria-label="Filtre"]');who.value='pool';who.dispatchEvent(new t.dom.window.Event('change'));await tick();
 assert.equal(t.root.querySelectorAll('.crm-missed-row').length,1);
 const sel=t.root.querySelector('.crm-missed-row select[aria-label="Commercial"]');sel.value='bob';sel.dispatchEvent(new t.dom.window.Event('change'));await tick();
 assert.equal(JSON.stringify(t.calls.find(([p])=>p==='/missed/2/assign')[1]),'{"uid":"bob"}');
});
test('call session chains missed calls, overdue callbacks then new files and reports at the end',async()=>{
 const t=setup('commercial');vm.runInContext('missedCache=null',t.ctx);await vm.runInContext('startSession()',t.ctx);
 assert.equal(JSON.stringify(vm.runInContext('callSession.ids',t.ctx)),'["42","9","11"]','missed with file, overdue only, then new without duplicates');
 assert.deepEqual(t.opened,['42']);
 const bar=vm.runInContext('sessionBar("42")',t.ctx);t.root.append(bar);assert.match(bar.textContent,/0 traité · 2 restants/);
 assert.equal(vm.runInContext('sessionNext(true)',t.ctx),true);assert.deepEqual(t.opened,['42','9']);
 [...bar.querySelectorAll('button')].find(b=>b.textContent==='Passer ce dossier').click();assert.deepEqual(t.opened,['42','9','11']);
 assert.equal(vm.runInContext('sessionNext(true)',t.ctx),true,'last file ends the session');
 assert.equal(vm.runInContext('callSession',t.ctx),null);assert.deepEqual(t.went,['journee']);
 assert.equal(vm.runInContext('sessionNext(true)',t.ctx),false,'no session: normal next behaviour');
});
test('goals card shows progress and saves personal goals',async()=>{
 const t=setup('superviseur');const card=vm.runInContext('goalsCard()',t.ctx);t.root.append(card);await tick();
 const tr=card.querySelectorAll('tbody tr');assert.equal(tr.length,2);assert.match(tr[0].textContent,/12 \/ 30/);assert.match(tr[0].textContent,/90 \/ 150/);
 assert.equal(tr[1].querySelector('input').value,'','default goal stays empty');assert.equal(tr[1].querySelector('input').placeholder,'20');
 assert.ok(tr[1].querySelector('.crm-progress.done'),'goal reached is marked');
 tr[1].querySelectorAll('input')[1].value='100';tr[1].querySelector('button').click();await tick();
 assert.equal(JSON.stringify(t.calls.find(([p,b])=>p==='/goals'&&b)[1]),'{"uid":"bob","day":0,"week":100}');
});
test('quick WhatsApp replies insert text with the client name',async()=>{
 const dom=new JSDOM('<main></main>');const document=dom.window.document;
 const ctx=vm.createContext({document,window:dom.window,Node:dom.window.Node,root:document.querySelector('main'),setTimeout,me:{role:'commercial',prefs:{wa_quick:['Bonjour {nom}, merci pour votre appel.','Voici le devis.']}},api:async()=>({templates:[]})});
 vm.runInContext(source.slice(source.indexOf('const h ='),source.indexOf('async function api('))+source.slice(source.indexOf('const waCanSend'),source.indexOf('function callDone()')),ctx);
 const box=vm.runInContext('waComposer',ctx)({windowOpen:true,clientName:'Karim',send:async()=>({}),done:()=>{}});
 const chips=box.querySelectorAll('.crm-chip');assert.equal(chips.length,2);chips[0].click();chips[1].click();
 assert.equal(box.querySelector('textarea').value,'Bonjour Karim, merci pour votre appel.\nVoici le devis.');
});
