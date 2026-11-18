// SMS : fenêtre de rédaction (modèles, compteur), envoi depuis la fiche, réglages admin, et aucun bouton tant que l'option est désactivée.
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {JSDOM}=require('jsdom');
const src=fs.readFileSync('callcrm/js/app.js','utf8');
const part=(a,b)=>src.slice(src.indexOf(a),src.indexOf(b,src.indexOf(a)));
const code=part('const h =','async function api(')+part('/** Administration : accès à l\'API SMS ICOSNET','/** Administration : liste de documents');
const tick=()=>new Promise(r=>setTimeout(r,30));
function setup(sms,settings){
 const dom=new JSDOM('<main id="r"></main>',{url:'https://cloud.example.test'}),document=dom.window.document,root=document.getElementById('r');
 const calls=[];
 const ctx=vm.createContext({document,window:dom.window,Node:dom.window.Node,root,setTimeout,clearTimeout,URLSearchParams,confirm:()=>true,
  me:{role:'admin',uid:'sam',can:{call:true},sms},
  api:async(path,o={})=>{calls.push([path,o.body]);if(path==='/sms/settings'&&!o.method)return settings;if(path==='/sms/settings')return{...settings,...o.body,has_password:true};if(path.endsWith('/sms'))return{ok:true,segments:1};if(path==='/sms/credit')return{credit:'1500.00'};return{};}});
 vm.runInContext(code,ctx);return{dom,document,ctx,calls,root};
}
test('les boutons SMS sont tous conditionnés à l’activation par l’administrateur',()=>{
 for(const m of src.matchAll(/'✉ SMS[^']*'/g)){
  const before=src.slice(Math.max(0,m.index-700),m.index);
  assert.match(before,/me\.sms\?\.(enabled|bulk)/,'bouton sans condition : '+src.slice(m.index-120,m.index+20));
 }
 assert.match(src,/\.\.\.\(me\.sms\?\.enabled\?\[\['sms','SMS'\]\]:\[\]\)/,'filtre SMS de la chronologie masqué si désactivé');
});
test('fenêtre SMS : modèle, compteur arabe et envoi',async()=>{
 const t=setup({enabled:true,templates:[{name:'Relance',text:'Bonjour {prenom}, rappelez-nous.'}]});
 t.ctx.clientSms({id:7,firstname:'Karim',lastname:'Benali',phone:'0550123456'});
 const dlg=t.document.querySelector('.crm-sms-dialog');assert.ok(dlg);assert.match(dlg.textContent,/SMS à Karim Benali/);
 const ta=dlg.querySelector('textarea'),sel=dlg.querySelector('select'),go=[...dlg.querySelectorAll('button')].find(b=>b.textContent==='Envoyer');
 assert.equal(go.disabled,true,'vide : envoi bloqué');
 sel.value='0';sel.dispatchEvent(new t.dom.window.Event('change'));assert.equal(ta.value,'Bonjour {prenom}, rappelez-nous.');
 ta.value='س'.repeat(71);ta.dispatchEvent(new t.dom.window.Event('input'));assert.match(dlg.querySelector('.crm-sms-counter').textContent,/71 caractères · 2 SMS · arabe/);
 ta.value='a'.repeat(800);ta.dispatchEvent(new t.dom.window.Event('input'));assert.equal(go.disabled,true,'plus de 5 SMS bloqué');
 ta.value='Bonjour {prenom}';ta.dispatchEvent(new t.dom.window.Event('input'));go.click();await tick();
 assert.deepEqual(JSON.parse(JSON.stringify(t.calls.at(-1))),['/prospects/7/sms',{text:'Bonjour {prenom}'}]);
 assert.match(dlg.textContent,/SMS envoyé \(1 SMS facturé\)/);
});
test('réglages admin : désactivé, mot de passe jamais réaffiché, enregistrement',async()=>{
 const t=setup({enabled:false},{enabled:false,url:'',username:'',has_password:false,sender:'',dlr:false,max_bulk:300,commercial_send:true,templates:[]});
 t.root.append(t.ctx.smsSettingsCard());await tick();
 const card=t.document.querySelector('.crm-sms-settings');
 assert.match(card.textContent,/Option désactivée : aucun bouton SMS/);
 const enable=card.querySelector('label.crm-sms-check input');assert.equal(enable.checked,false);
 const pass=card.querySelector('input[type=password]');assert.equal(pass.value,'');
 card.querySelector('input[type=url]').value='https://sms.icosnet.test:8443';pass.value='secret';
 [...card.querySelectorAll('button')].find(b=>b.textContent==='+ Ajouter un modèle').click();
 const tpl=card.querySelector('.crm-sms-template');tpl.querySelector('input').value='Relance';tpl.querySelector('textarea').value='Bonjour {prenom}';
 [...card.querySelectorAll('button')].find(b=>b.textContent==='Enregistrer').click();await tick();
 const body=t.calls.find(c=>c[0]==='/sms/settings'&&c[1])[1];
 assert.equal(body.enabled,false);assert.equal(body.url,'https://sms.icosnet.test:8443');assert.equal(body.password,'secret');
 assert.deepEqual(JSON.parse(JSON.stringify(body.templates)),[{name:'Relance',text:'Bonjour {prenom}'}]);
 assert.equal(pass.value,'','mot de passe effacé du champ après enregistrement');
 [...card.querySelectorAll('button')].find(b=>b.textContent==='Vérifier le crédit').click();await tick();assert.match(card.textContent,/Crédit restant : 1500.00 DA/);
});
