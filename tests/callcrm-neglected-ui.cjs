// À relancer : tableau triable, filtres (commercial, groupe, produit, changement, jours) et ouverture de la fiche.
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {JSDOM}=require('jsdom');
const src=fs.readFileSync('callcrm/js/app.js','utf8');
const part=(a,b)=>src.slice(src.indexOf(a),src.indexOf(b,src.indexOf(a)));
const code=part('const h =','async function api(')+part('function sortableHeading(','let personalResultSort')+part('/** À relancer : filtres','function discovery(');
const tick=()=>new Promise(r=>setTimeout(r,40));
const rows=[{id:1,lastname:'<b>Benali</b>',firstname:'Karim',phone:'0770000001',status:'interesse',c2:'Motos',c5:'Alger',last_call_at:1700000000,last_result:'injoignable',days_idle:30,owner:'Alice',assigned_to:'alice',last_caller:'bob',last_caller_name:'Bob',changed:true,source:'formulaire'},
 {id:2,lastname:'Zeta',firstname:'',phone:'0770000002',status:'nouveau',c2:'',c5:'',last_call_at:null,last_result:'',days_idle:12,owner:'Bob',assigned_to:'bob',last_caller:null,last_caller_name:'',changed:false,source:''}];
function setup(role){
 const dom=new JSDOM('<main id="r"></main>',{url:'https://cloud.example.test'}),document=dom.window.document,root=document.getElementById('r');
 const calls=[],opened=[];
 const ctx=vm.createContext({document,window:dom.window,Node:dom.window.Node,root,setTimeout,clearTimeout,URLSearchParams,fmt:t=>'D'+t,ST:{interesse:'Intéressé',nouveau:'Nouveau',injoignable:'Injoignable'},STC:{},
  badge:k=>{const s=document.createElement('span');s.className='badge';s.textContent=k;return s;},nm:r=>((r.lastname||'')+' '+(r.firstname||'')).trim(),
  me:{role,uid:role==='commercial'?'alice':'sam',can:{call:true,all:role!=='commercial'},commerciaux:[{uid:'alice',name:'Alice'},{uid:'bob',name:'Bob'}],
   groups:[{id:'callcrm_motos',name:'Motos'}],teams:{callcrm_motos:['alice']},options:{c2:['Motos','Cuisine équipée']}},
  detail:id=>opened.push(id),show:(...n)=>root.replaceChildren(...n.flat()),
  api:async path=>{calls.push(new URLSearchParams(path.split('?')[1]));return{rows,total:2,truncated:false,has_more:false,next_offset:50};}});
 vm.runInContext(code,ctx);vm.runInContext('neglectedView()',ctx);return{dom,document,ctx,calls,opened};
}
test('manager: table with last caller, current commercial, change flag and product',async()=>{
 const t=setup('superviseur');await tick();
 const table=t.document.querySelector('table.crm-neglected-table');assert.ok(table,'table rendered');
 const heads=[...table.querySelectorAll('thead th')].map(th=>th.textContent);
 for(const label of ['Client','Source','Produit demandé','Dernier appel','Jours sans suivi','Dernier commercial ayant appelé','Commercial actuel','Changement'])assert.ok(heads.some(x=>x.includes(label)),label);
 const first=table.querySelector('tbody tr');assert.ok(first.classList.contains('crm-changed'));
 assert.equal(first.querySelector('b'),null,'client name rendered as text');
 assert.match(first.textContent,/Bob/);assert.match(first.textContent,/Alice/);assert.match(first.textContent,/Changé/);assert.match(first.textContent,/Motos/);assert.match(first.textContent,/Formulaire réseaux sociaux/);
 assert.match(table.querySelectorAll('tbody tr')[1].textContent,/Jamais appelé/);
 assert.match(t.document.body.textContent,/2 dossiers à relancer/);
 first.querySelector('button.crm-link').click();assert.deepEqual(t.opened,[1]);
 assert.equal(first.querySelector('a[href="tel:0770000001"]').getAttribute('aria-label'),'Appeler <b>Benali</b> Karim');
});
test('manager: filters and sort are sent to the server',async()=>{
 const t=setup('superviseur');await tick();
 const sel=label=>t.document.querySelector(`select[aria-label="${label}"]`);
 const change=(el,v)=>{el.value=v;el.dispatchEvent(new t.dom.window.Event('change'));};
 change(sel('Commercial actuel'),'bob');await tick();assert.equal(t.calls.at(-1).get('assigned'),'bob');
 change(sel('Groupe de commerciaux'),'callcrm_motos');await tick();assert.equal(t.calls.at(-1).get('uids'),'alice');
 change(sel('Produit demandé'),'Motos');await tick();assert.equal(t.calls.at(-1).get('product'),'Motos');
 change(sel('Source du prospect'),'import');await tick();assert.equal(t.calls.at(-1).get('source'),'import');
 change(sel('Changement de commercial'),'yes');await tick();assert.equal(t.calls.at(-1).get('changed'),'yes');
 const days=t.document.querySelector('input[aria-label="Nombre de jours sans suivi"]');days.value='30';days.dispatchEvent(new t.dom.window.Event('input'));await new Promise(r=>setTimeout(r,400));
 assert.equal(t.calls.at(-1).get('days'),'30');
 assert.equal(t.calls[0].get('sort'),'days');assert.equal(t.calls[0].get('dir'),'desc');
 t.document.querySelector('button[data-sort-key="owner"]').click();await tick();assert.equal(t.calls.at(-1).get('sort'),'owner');assert.equal(t.calls.at(-1).get('dir'),'asc');
 t.document.querySelector('button[data-sort-key="owner"]').click();await tick();assert.equal(t.calls.at(-1).get('dir'),'desc');
 assert.equal(t.document.querySelector('th[aria-sort="descending"] button').dataset.sortKey,'owner');
});
test('commercial: no team filters, own list only',async()=>{
 const t=setup('commercial');await tick();
 assert.equal(t.document.querySelector('select[aria-label="Commercial actuel"]'),null);
 assert.equal(t.document.querySelector('select[aria-label="Groupe de commerciaux"]'),null);
 assert.equal(t.calls[0].get('assigned'),null);assert.equal(t.calls[0].get('uids'),null);
});
