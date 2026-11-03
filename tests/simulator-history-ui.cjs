const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {JSDOM}=require('jsdom');
const tick=()=>new Promise(r=>setImmediate(r));
for(const edition of ['callcrm','nextcloud'])test(edition+': all call outcomes show date, reason, note without technical user ID',()=>{
 const source=fs.readFileSync(edition+'/js/app.js','utf8'),dom=new JSDOM('<body>');
 const ctx=vm.createContext({document:dom.window.document,Node:dom.window.Node,ST:{},STD:{},me:{},fmt:String,fmtS:String,T:x=>x});
 vm.runInContext(source.slice(source.indexOf('const h ='),source.indexOf('async function api('))+source.slice(source.indexOf('function attemptsBox('),source.indexOf('async function detail(')),ctx);
 for(const key of ['interesse','injoignable','rappel','pas_interesse','non_eligible','faux_numero','transmis','custom']){
  ctx.c={result:key,created_at:12345,reason:'Autre',note:'Commentaire client',user_id:'UUID-TECHNIQUE',callback_at:23456};
  ctx.STD[key]={callback:true};const attempts=vm.runInContext('attemptsBox([c])',ctx);assert.ok(!attempts.textContent.includes('UUID-TECHNIQUE'));assert.ok(attempts.textContent.includes('Autre'));const el=vm.runInContext('callHistoryEntry(c)',ctx);assert.ok(!el.textContent.includes('UUID-TECHNIQUE'));for(const s of [key,'12345','Autre','Commentaire client'])assert.ok(el.textContent.includes(s));
 }
});
function simulator(){
 const source=fs.readFileSync('callcrm/js/app.js','utf8'),dom=new JSDOM('<body><main><textarea data-k="remarque">Note existante</textarea></main></body>');let saves=0,fail=false,resolveSave;
 const cfg={models:[{name:'Classique',price:37000}],extras:[{name:'Tiroir',price:5000}],equip:[{name:'Colonne',price:0,deduct:0.6,when:'no'}],hl_mult:2,wt_deduct:0.6,depth:0.65,m2:17500,labour:15000,plinth:5000,coef:1.3,range:10,round:1000};
 const ctx=vm.createContext({document:dom.window.document,window:dom.window,Node:dom.window.Node,root:dom.window.document.querySelector('main'),URLSearchParams,me:{can:{call:true}},nm:p=>p.lastname||'Client',navigator:{clipboard:{writeText:async()=>{}}},alert:()=>{},api:async(path,options)=>{
  if(path==='/sim')return{cfg};if(path.startsWith('/prospects?'))return[{id:1,lastname:'Client'}];saves++;await new Promise(r=>resolveSave=r);if(fail)throw Error('Échec réseau');return{remarque:'Note existante\n\n*Estimation*',note:'*Estimation*'};
 }});
 vm.runInContext(source.slice(source.indexOf('const h ='),source.indexOf('async function api('))+source.slice(source.indexOf('const dirtyChecks'),source.indexOf('function chronology('))+source.slice(source.indexOf('let SIM ='),source.indexOf('/** Simulations déjà')),ctx);
 return{dom,ctx,get saves(){return saves;},finish(){resolveSave();},fail(){fail=true;}};
}
test('simulator stays open, validates dimensions and saves once without dropping local client edits',async()=>{
 const x=simulator();x.ctx.p={id:1,lastname:'Client',remarque:'Note existante'};await vm.runInContext('openSim(p)',x.ctx);const doc=x.dom.window.document,bg=doc.querySelector('.sim-bg');
 bg.click();doc.dispatchEvent(new x.dom.window.KeyboardEvent('keydown',{key:'Escape'}));assert.ok(bg.isConnected);
 const save=[...doc.querySelectorAll('button')].find(b=>b.textContent.includes('Enregistrer dans la note'));const width=bg.querySelector('input');
 width.value='0';width.dispatchEvent(new x.dom.window.Event('input'));assert.ok(save.disabled);width.value='4';width.dispatchEvent(new x.dom.window.Event('input'));assert.ok(!save.disabled);
 doc.querySelector('textarea').value='Note modifiée localement';save.click();save.click();assert.equal(x.saves,1);x.finish();await tick();assert.ok(bg.isConnected);assert.ok(save.disabled);assert.equal(doc.querySelector('textarea').value,'Note modifiée localement\n\n*Estimation*');
 bg.querySelector('[aria-label="Fermer"]').click();assert.ok(!bg.isConnected);
});
test('global simulator requires selecting a client and preserves inputs after failure',async()=>{
 const x=simulator();await vm.runInContext('openSim()',x.ctx);const doc=x.dom.window.document,save=[...doc.querySelectorAll('button')].find(b=>b.textContent.includes('Enregistrer dans la note'));assert.ok(save.disabled);
 const search=doc.querySelector('[type=search]');search.value='Client';search.dispatchEvent(new x.dom.window.Event('input'));await tick();const pick=doc.querySelector('select');pick.value='1';pick.dispatchEvent(new x.dom.window.Event('change'));assert.ok(!save.disabled,doc.body.innerHTML);
 x.fail();save.click();x.finish();await tick();assert.ok(!save.disabled);assert.ok(doc.querySelector('.sim-bg'));assert.match(doc.body.textContent,/Échec réseau/);assert.equal(doc.querySelector('.sim-form input').value,'4');
});
