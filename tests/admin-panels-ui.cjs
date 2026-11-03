const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {JSDOM}=require('jsdom');
for(const edition of ['callcrm','nextcloud']) test(edition+': collapsible admin panels preserve edits, handlers and per-user state',async()=>{
 const source=fs.readFileSync(`${edition}/js/app.js`,'utf8');
 const dom=new JSDOM('<body><main id="app"></main></body>',{url:'https://example.test'}),doc=dom.window.document;
 const ctx=vm.createContext({document:doc,window:dom.window,Node:dom.window.Node,localStorage:dom.window.localStorage,root:doc.querySelector('main'),me:{uid:'alice'},T:s=>s});
 vm.runInContext(source.slice(source.indexOf('const h ='),source.indexOf('async function api('))+source.slice(source.indexOf('function adminPanels()'),source.indexOf('function admin()')),ctx);
 const render=()=>{doc.querySelector('main').innerHTML='<div class="crm-page"><h2>Administration</h2><div class="card"><h3>Champs</h3><input value="Avant"><button>Enregistrer</button></div><div class="card"><h3>Simulateur</h3><p>Chargement…</p></div></div>';vm.runInContext('adminPanels()',ctx);};
 const settle=()=>new Promise(r=>setTimeout(r,10));
 render();let panels=[...doc.querySelectorAll('details')];assert.ok(panels.every(p=>p.open));
 const input=doc.querySelector('input');input.value='Modification en cours';let clicks=0;const save=panels[0].querySelector('button');save.addEventListener('click',()=>clicks++);
 panels[0].querySelector('summary').click();await settle();assert.equal(panels[0].open,false);
 panels[0].querySelector('summary').click();await settle();assert.equal(doc.querySelector('input'),input);assert.equal(input.value,'Modification en cours');save.click();assert.equal(clicks,1);
 doc.querySelector('.crm-admin-panel-tools button').click();await settle();assert.ok(panels.every(p=>!p.open));
 render();await settle();assert.ok([...doc.querySelectorAll('details')].every(p=>!p.open),'closed state restored');
 const body=doc.querySelectorAll('.crm-admin-panel-body')[1];body.replaceChildren(doc.createElement('h3'),doc.createElement('input'));assert.equal(body.closest('details').open,false,'async refresh retains collapsed wrapper');
 vm.runInContext("me.uid='bob'",ctx);render();await settle();assert.ok([...doc.querySelectorAll('details')].every(p=>p.open),'another user starts expanded');
 Object.defineProperty(ctx,'localStorage',{value:{getItem(){throw Error('blocked');},setItem(){throw Error('blocked');}}});render();doc.querySelector('summary').click();await settle();assert.equal(doc.querySelector('details').open,false,'works without storage');
});
