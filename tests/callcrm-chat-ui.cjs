// Discussion interne (popup, sur Nextcloud Talk) : badge non lus, liste et collègues, messages sans HTML, envoi, alerte de nouveau message.
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {JSDOM}=require('jsdom');
const src=fs.readFileSync('callcrm/js/app.js','utf8');
const part=(a,b)=>src.slice(src.indexOf(a),src.indexOf(b,src.indexOf(a)));
const code=part('const h =','async function api(')+part('/* ---- Discussion interne','(async () => {\n  try { me = await api');
const tick=(ms=30)=>new Promise(r=>setTimeout(r,ms));
function setup(){
 const dom=new JSDOM('<!doctype html><head></head><body><main id="callcrm"></main></body>',{url:'https://cloud.example.test/apps/callcrm/',pretendToBeVisual:true});
 const w=dom.window,calls=[];
 let rooms=[{token:'r1',type:1,name:'alice',displayName:'Alice',unreadMessages:2,lastActivity:100,lastMessage:{id:5,actorId:'alice',actorDisplayName:'Alice',message:'Bonjour'}},
  {token:'r2',type:2,name:'grp',displayName:'Équipe Motos',unreadMessages:0,lastActivity:90,lastMessage:{id:3,actorId:'bob',actorDisplayName:'Bob',message:'ok'}},
  {token:'n',type:6,name:'note',displayName:'Note',unreadMessages:9,lastActivity:1}];
 const history=[{id:5,actorType:'users',actorId:'alice',actorDisplayName:'Alice',message:'<img src=x onerror=alert(1)> {file}',timestamp:100,systemMessage:'',
   messageParameters:{file:{type:'file',id:'42',name:'devis.pdf',size:'20480',link:'https://cloud.example.test/f/42'}}},
  {id:4,actorType:'users',actorId:'sam',actorDisplayName:'Sam',message:'Salut',timestamp:90,systemMessage:'',messageParameters:{}}];
 const fetch=async(url,o={})=>{calls.push([url,o.method||'GET',o.body?JSON.parse(o.body):null,o.headers||{}]);
  const json=d=>({ok:true,status:200,headers:new Map(),json:async()=>({ocs:{data:d}})});
  if(url.includes('/api/v4/room')&&(o.method||'GET')==='GET')return json(rooms);
  if(url.includes('/api/v4/room')&&o.method==='POST')return json({token:'r9',type:1,name:'carl',displayName:'Carl'});
  if(url.includes('/api/v1/chat/')&&o.method==='POST')return json({id:6,actorType:'users',actorId:'sam',actorDisplayName:'Sam',message:JSON.parse(o.body).message,timestamp:200,systemMessage:'',messageParameters:{}});
  if(url.includes('lookIntoFuture=0'))return json(history);
  if(url.includes('lookIntoFuture=1'))return new Promise((res,rej)=>{o.signal?.addEventListener('abort',()=>{const e=new Error('a');e.name='AbortError';rej(e);});});
  return json([]);};
 const ctx=vm.createContext({document:w.document,window:w,Node:w.Node,setTimeout,clearTimeout,setInterval:()=>0,URLSearchParams,AbortController:w.AbortController,fetch,
  tok:()=>'T',me:{uid:'sam',role:'commercial'},api:async p=>{calls.push([p]);return [{uid:'alice',name:'Alice',role:'Commercial'},{uid:'carl',name:'Carl Été',role:'Superviseur'}];}});
 vm.runInContext(code+';crmChat();',ctx);
 return {w,doc:w.document,calls,ctx,setRooms:r=>{rooms=r;}};
}
test('badge des non lus (hors notes et annonces) et en-têtes Talk',async()=>{
 const t=setup();await tick();
 const b=t.doc.querySelector('.crm-chat-fab .crm-chat-badge');assert.equal(b.hidden,false);assert.equal(b.textContent,'2');
 const c=t.calls.find(x=>String(x[0]).includes('/ocs/v2.php/apps/spreed/api/v4/room'));assert.equal(c[3]['OCS-APIRequest'],'true');assert.equal(c[3].requesttoken,'T');
});
test('liste : conversations puis collègues sans conversation, recherche sans accents',async()=>{
 const t=setup();await tick();t.doc.querySelector('.crm-chat-fab').click();await tick();
 const txt=t.doc.querySelector('.crm-chat-list').textContent;
 assert.match(txt,/Conversations/);assert.match(txt,/Équipe Motos/);assert.match(txt,/Carl Été/);
 assert.equal([...t.doc.querySelectorAll('.crm-chat-row strong')].filter(x=>x.textContent==='Alice').length,1,'Alice une seule fois (déjà en conversation)');
 const s=t.doc.querySelector('.crm-chat-panel input[type=search]');s.value='ete';s.dispatchEvent(new t.w.Event('input'));
 assert.deepEqual([...t.doc.querySelectorAll('.crm-chat-row strong')].map(x=>x.textContent),['Carl Été']);
 t.doc.querySelector('.crm-chat-row').click();await tick();
 const post=t.calls.find(x=>x[1]==='POST'&&String(x[0]).includes('/api/v4/room'));assert.deepEqual(post[2],{roomType:1,invite:'carl'});
 assert.match(t.doc.querySelector('.crm-chat-head strong').textContent,/Carl/);
});
test('conversation : texte jamais interprété en HTML, pièce jointe, envoi par Entrée',async()=>{
 const t=setup();await tick();t.doc.querySelector('.crm-chat-fab').click();await tick();
 [...t.doc.querySelectorAll('.crm-chat-row')].find(x=>x.textContent.includes('Alice')).click();await tick();
 const msgs=t.doc.querySelector('.crm-chat-msgs');
 assert.equal(msgs.querySelector('img[onerror]'),null,'pas de HTML injecté');assert.match(msgs.textContent,/<img src=x onerror=alert\(1\)>/);
 const a=msgs.querySelector('a.crm-chat-file');assert.equal(a.getAttribute('href'),'https://cloud.example.test/f/42');assert.match(a.textContent,/devis\.pdf · 20 Ko/);
 assert.ok(msgs.querySelector('.crm-chat-msg.mine'),'mon message à droite');
 const ta=t.doc.querySelector('.crm-chat-compose textarea');ta.value='Je rappelle le client';
 ta.dispatchEvent(new t.w.KeyboardEvent('keydown',{key:'Enter',bubbles:true}));await tick();
 const sent=t.calls.find(x=>x[1]==='POST'&&String(x[0]).includes('/api/v1/chat/r1'));assert.deepEqual(sent[2],{message:'Je rappelle le client'});
 assert.equal(ta.value,'');assert.match(msgs.lastChild.textContent,/Je rappelle le client/);
 assert.equal(t.doc.querySelector('.crm-chat-fab .crm-chat-badge').hidden,true,'lu : badge effacé');
});
test('nouveau message reçu panneau fermé : alerte cliquable',async()=>{
 const t=setup();await tick();
 t.setRooms([{token:'r1',type:1,name:'alice',displayName:'Alice',unreadMessages:3,lastActivity:300,lastMessage:{id:9,actorId:'alice',actorDisplayName:'Alice',message:'Tu peux reprendre mon client ?'}}]);
 vm.runInContext('void 0',t.ctx);
 // relance du rafraîchissement comme le ferait la minuterie
 t.doc.dispatchEvent(new t.w.Event('visibilitychange'));await tick();
 const toast=t.doc.querySelector('.crm-chat-toast');assert.ok(toast,'alerte affichée');assert.match(toast.textContent,/Tu peux reprendre mon client/);
 toast.click();await tick();assert.equal(t.doc.querySelector('.crm-chat-panel').hidden,false);assert.match(t.doc.querySelector('.crm-chat-head strong').textContent,/Alice/);
});
