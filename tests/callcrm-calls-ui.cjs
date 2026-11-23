const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync('callcrm/js/app.js','utf8');
const part=(from,to)=>source.slice(source.indexOf(from),source.indexOf(to));
const tick=()=>new Promise(r=>setTimeout(r,20));

test('answered inbound call opens the known file, offers a new file for an unknown number, never discards edits',async()=>{
 const dom=new JSDOM('<main></main>',{url:'https://cloud.example.test'}),document=dom.window.document,root=document.querySelector('main');
 const opened=[],created=[];let rows=[];
 const ctx=vm.createContext({document,window:dom.window,Node:dom.window.Node,root,setTimeout,fmt:String,dirtyChecks:new Map(),callBusy:{pid:null,until:0},
  me:{can:{create:true}},detail:id=>opened.push(id),addForm:(preset,note)=>created.push([preset,note]),alert:()=>{},
  api:async path=>{if(path==='/yeastar/runtime')return{enabled:true,extension:'1029',linkus_url:''};if(path.startsWith('/yeastar/incoming'))return{rows};throw new Error(path);}});
 vm.runInContext(part('const h =','async function api(')+part('let yeastarRuntime=','function yeastarSettingsCard()'),ctx);
 await vm.runInContext('refreshYeastarRuntime()',ctx);
 const poll=()=>vm.runInContext('pollYeastar()',ctx);
 rows=[{id:1,call_id:'k',phone:'0770000001',direction:'inbound',state:'RING',prospect_id:42,known:true}];await poll();assert.deepEqual(opened,[]);
 rows=[{id:2,call_id:'k',phone:'0770000001',direction:'inbound',state:'ANSWER',prospect_id:42,known:true}];await poll();assert.deepEqual(opened,[42]);
 assert.equal(vm.runInContext('callBusy.pid',ctx),'42');
 rows=[{id:3,call_id:'k',phone:'0770000001',direction:'inbound',state:'ANSWERED',prospect_id:42,known:true}];await poll();assert.deepEqual(opened,[42],'opened once per call');
 rows=[{id:4,call_id:'u',phone:'0770000002',direction:'inbound',state:'ANSWER',prospect_id:null,known:false}];await poll();
 assert.equal(created.length,1);assert.equal(created[0][0].phone,'0770000002');assert.match(created[0][1],/inconnu/);
 rows=[{id:5,call_id:'h',phone:'0770000003',direction:'inbound',state:'ANSWER',prospect_id:null,known:true}];await poll();
 assert.equal(created.length,1,'a number owned by another file is never duplicated');assert.deepEqual(opened,[42]);
 ctx.dirtyChecks.set('call',{label:'x',check:()=>true});
 rows=[{id:6,call_id:'d',phone:'0770000004',direction:'inbound',state:'ANSWER',prospect_id:7,known:true}];await poll();
 assert.deepEqual(opened,[42],'unsaved edits keep the notification button instead of navigating');
 assert.ok([...document.querySelectorAll('.crm-yeastar-popup')].some(p=>/Ouvrir la fiche/.test(p.textContent)));
 rows=[{id:7,call_id:'o',phone:'0770000005',direction:'outbound',state:'ANSWER',prospect_id:9,known:true}];ctx.dirtyChecks.clear();await poll();
 assert.deepEqual(opened,[42],'outbound calls do not navigate');
});

test('callback reminder popup is disabled: no window and no request, even when a callback is due and nothing is open',async()=>{
 const dom=new JSDOM('<main></main>',{url:'https://cloud.example.test'}),document=dom.window.document,root=document.querySelector('main');
 const due={id:9,assigned_to:'alice',callback_at:1,phone:'0770000009',lastname:'Client'};let calls=0;
 const ctx=vm.createContext({document,window:dom.window,Node:dom.window.Node,root,setTimeout:()=>{},fmt:String,nm:p=>p.lastname,waNum:String,waOf:p=>String(p.phone),saveSnooze:()=>{},snooze:{},popup:false,myPresence:null,
  dirtyChecks:new Map(),yeastarPopups:new Map(),pbxState:s=>s==='BYE'?'Terminé':s,detail:()=>{},me:{uid:'alice',can:{call:true}},callBusy:{pid:null,until:0},api:async()=>{calls++;return [due];}});
 vm.runInContext(part('const h =','async function api(')+source.slice(source.indexOf('function callDone()'),source.lastIndexOf('(async () => {')),ctx);
 await vm.runInContext('checkReminders()',ctx);await tick();
 assert.equal(document.querySelectorAll('.ccp-bg').length,0);
 assert.equal(calls,0);
});

test('recordings tab groups calls by number, opens a number, and still offers the detailed list',async()=>{
 const dom=new JSDOM('<main></main>',{url:'https://cloud.example.test'}),document=dom.window.document,root=document.querySelector('main');
 const requests=[],opened=[];const ev=n=>new dom.window.Event(n);
 const rows=[{id:1,phone:'0770000001',extension:'1007',user_id:'alice',direction:'inbound',call_status:'ANSWERED',started_at:'2026-11-17 10:00:00',talk_duration:'42',state:'ready',prospect_id:42,client:'<b>Dupont</b>',created_at:1},
  {id:2,phone:'0770000002',extension:'6200',user_id:'',direction:'inbound',call_status:'NO ANSWER',started_at:'2026-11-17 10:05:00',talk_duration:'0',state:'no_recording',prospect_id:null,client:'',created_at:2}];
 const groups=[{phone:'0770000001',calls:3,inbound:2,outbound:1,answered:1,audios:1,last_at:Math.floor(Date.now()/1000)-600,first_at:1,prospect_id:42,client:'<b>Dupont</b>'},
  {phone:'0770000002',calls:1,inbound:1,outbound:0,answered:0,audios:0,last_at:Math.floor(Date.now()/1000)-60,first_at:1,prospect_id:null,client:''}];
 const ctx=vm.createContext({document,window:dom.window,Node:dom.window.Node,root,setTimeout,clearTimeout,URLSearchParams,API:'/apps/callcrm/api',fmt:String,confirm:()=>true,
  agoTxt:t=>'il y a '+Math.round((Date.now()/1000-t)/60)+' min',
  me:{commerciaux:[{uid:'alice',name:'Alice'}]},detail:id=>opened.push(id),show:(...n)=>root.replaceChildren(...n.flat()),
  api:async(path,opt)=>{requests.push([path,opt]);if(!path.startsWith('/yeastar/recordings/all'))return{ok:true};const q=new URLSearchParams(path.split('?')[1]);
   if(q.get('group')==='1')return{rows:groups,has_more:true};if(q.get('phone'))return{rows:rows.filter(r=>r.phone===q.get('phone')),has_more:false};return{rows,has_more:true};}});
 vm.runInContext(part('const h =','async function api(')+part('function recordingsPage()','function yeastarSettingsCard()'),ctx);
 vm.runInContext('recordingsPage()',ctx);await tick();
 let q=new URLSearchParams(requests[0][0].split('?')[1]);assert.equal(q.get('group'),'1');assert.equal(q.get('audio'),'');assert.match(q.get('from'),/^\d{4}-\d{2}-\d{2}$/);
 const g=document.querySelectorAll('.crm-group');assert.equal(g.length,2);
 assert.match(g[0].querySelector('summary').textContent,/3appels.*2 entrants.*1 sortant.*1 sans réponse.*🎧 1/);
 assert.equal(document.querySelector('.crm-group b'),null,'client name is text');
 g[0].open=true;g[0].dispatchEvent(ev('toggle'));await tick();
 assert.equal(new URLSearchParams(requests.at(-1)[0].split('?')[1]).get('phone'),'0770000001');
 assert.equal(g[0].querySelectorAll('.crm-recording').length,1);assert.equal(g[0].querySelector('audio').getAttribute('src'),'/apps/callcrm/api/yeastar/recordings/1/audio');
 const n=requests.length;g[0].open=false;g[0].dispatchEvent(ev('toggle'));g[0].open=true;g[0].dispatchEvent(ev('toggle'));await tick();assert.equal(requests.length,n,'loaded once');
 [...document.querySelectorAll('button')].find(b=>b.textContent==='Afficher les numéros suivants').click();await tick();
 assert.equal(new URLSearchParams(requests.at(-1)[0].split('?')[1]).get('offset'),'50');
 const grp=[...document.querySelectorAll('label')].find(l=>/Regrouper par numéro/.test(l.textContent)).querySelector('input');grp.checked=false;grp.dispatchEvent(ev('change'));await tick();
 q=new URLSearchParams(requests.at(-1)[0].split('?')[1]);assert.equal(q.get('group'),'');
 assert.equal(document.querySelectorAll('.crm-recording').length,2);
 document.querySelector('.crm-recording a[href="#"]').click();assert.deepEqual(opened,[42]);
 const second=document.querySelectorAll('.crm-recording')[1];second.querySelector('input').value='42';
 [...second.querySelectorAll('button')].find(b=>b.textContent==='Rattacher au dossier').click();await tick();
 assert.equal(JSON.stringify(requests.find(([p])=>p==='/yeastar/recordings/2/attach')[1].body),'{"prospect_id":42}');
});

test('call history sends filters and sorts, and only managers get the commercial column', async () => {
  for (const role of ['commercial', 'superviseur']) {
    const dom = new JSDOM('<main></main>', {url:'https://cloud.example.test'});
    const document = dom.window.document;
    const main = document.querySelector('main');
    const requests = [];
    const ctx = vm.createContext({
      document, Node:dom.window.Node, URLSearchParams,
      me:{role, commerciaux:[{uid:'a', name:'Jeanne'}]}, detail:()=>{},
      show:box=>main.replaceChildren(box),
      api:async path=>{
        requests.push(new URLSearchParams(path.split('?')[1]));
        return {rows:[{started_at:'2026-11-23 14:00:00',user_id:'a',lastname:'Client',
          prospect_id:12,phone:'0550123456',direction:'inbound',call_status:'ANSWERED',talk_duration:32}],has_more:true};
      }
    });
    vm.runInContext(part('const h =','async function api(')+part('function callHistoryPage()','function recordingsPage()'),ctx);
    vm.runInContext('callHistoryPage()',ctx);
    await tick();
    const manager = role==='superviseur';
    assert.equal(!!document.querySelector('[aria-label=Commercial]'),manager);
    assert.equal([...document.querySelectorAll('th')].some(th=>th.textContent.includes('Commercial')),manager);
    document.querySelector('[aria-label=Client]').value='Client Exemple';
    document.querySelector('[aria-label="Numéro de téléphone"]').value='0550';
    if(manager)document.querySelector('[aria-label=Commercial]').value='a';
    document.querySelector('form').dispatchEvent(new dom.window.Event('submit',{cancelable:true}));
    await tick();
    assert.equal(requests.at(-1).get('client'),'Client Exemple');
    assert.equal(requests.at(-1).get('number'),'0550');
    assert.equal(requests.at(-1).get('user'),manager?'a':'');
    [...document.querySelectorAll('th button')].find(button=>button.textContent==='Durée parlée').click();
    await tick();
    assert.equal(requests.at(-1).get('sort'),'duration');
    assert.equal(requests.at(-1).get('dir'),'asc');
    assert.match(main.textContent,/Répondu/);
    dom.window.close();
  }
});

test('commercial can open and save the real new-client form manually and after an incoming call', async () => {
  const dom = new JSDOM('<main></main>', {url:'https://cloud.example.test'});
  const {document} = dom.window;
  const root = document.querySelector('main');
  const created = [], opened = [];
  let rows = [];
  const ctx = vm.createContext({
    document, Node:dom.window.Node, root, setTimeout, tab:'nouveaux',
    dirtyChecks:new Map(), me:{can:{create:true,assign:false},fields:[
      {key:'lastname',label:'Nom',builtin:true,required:true},
      {key:'phone',label:'Téléphone',type:'tel',builtin:true}
    ]},
    show:(...children)=>root.replaceChildren(...children),
    detail:id=>opened.push(id),
    api:async (path, options)=>{
      if(path==='/yeastar/runtime')return {enabled:true,extension:'1029'};
      if(path.startsWith('/yeastar/incoming'))return {rows};
      if(path==='/prospects' && options.method==='POST'){
        created.push({...options.body});
        return {id:created.length};
      }
      throw new Error(path);
    }
  });
  vm.runInContext(part('const h =','async function api(')+part('const fval =','// Agenda, client chronicle')+
    part('let yeastarRuntime=','function yeastarHistory(')+part('async function addForm(','const skel'),ctx);
  await vm.runInContext('addForm()',ctx);
  assert.equal(root.querySelector('[data-k="phone"]').value,'');
  root.querySelector('[data-k="lastname"]').value='Création manuelle';
  root.querySelector('.crm-new-client button').click();
  await tick();
  assert.deepEqual(created[0],{lastname:'Création manuelle',phone:''});
  assert.deepEqual(opened,[1]);
  root.replaceChildren();

  await vm.runInContext('refreshYeastarRuntime()',ctx);
  rows=[{id:1,call_id:'new-client',phone:'0550123456',direction:'inbound',state:'RING',known:false}];
  await vm.runInContext('pollYeastar()',ctx);
  assert.equal(root.querySelector('.crm-new-client'),null);
  rows=[{...rows[0],id:2,state:'ANSWERED'}];
  await vm.runInContext('pollYeastar()',ctx);
  assert.equal(root.querySelector('[data-k="phone"]').value,'0550123456');
  root.querySelector('[data-k="lastname"]').value='Client appelant';
  root.querySelector('.crm-new-client button').click();
  await tick();
  assert.deepEqual(created[1],{lastname:'Client appelant',phone:'0550123456',origin:'appel'});
  assert.deepEqual(opened,[1,2]);
  dom.window.close();
});

test('editing an existing client submits only the changed fields and the current revision', async () => {
  const dom = new JSDOM('<main></main>');
  const {document} = dom.window;
  const requests = [];
  const ctx = vm.createContext({
    document, Node:dom.window.Node, CustomEvent:dom.window.CustomEvent, fmt:String, ro:false, id:42,
    me:{can:{assign:false},fields:[
      {key:'lastname',label:'Nom',builtin:true}, {key:'firstname',label:'Prénom',builtin:true}
    ]},
    p:{lastname:'Ancien',firstname:'Jeanne',remarque:'',created_at:1},
    d:{edit_revision:'initial'},
    api:async (path, options)=>{
      requests.push([path,{...options.body}]);
      return {edit_revision:'updated'};
    }
  });
  vm.runInContext(part('const h =','async function api(')+part('const fval =','// Agenda, client chronicle')+
    part("  const imsg = h('div', {class:'err'});",'  // Navigation dans la dernière liste affichée')+
    '\ndocument.querySelector("main").append(info);',ctx);
  document.querySelector('[data-k="lastname"]').value='Nouveau';
  [...document.querySelectorAll('button')].find(b=>b.textContent==='Enregistrer les informations').click();
  await tick();
  assert.deepEqual(requests,[['/prospects/42/update',{lastname:'Nouveau',_base_note:'',_edit_revision:'initial'}]]);
  assert.equal(vm.runInContext('d.edit_revision',ctx),'updated');
  dom.window.close();
});
