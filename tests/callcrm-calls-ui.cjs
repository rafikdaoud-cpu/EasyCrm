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
    const requests = [], downloads = [];
    const xlsx = require('../callcrm/js/xlsx.js');
    const manager = role==='superviseur';
    const ctx = vm.createContext({
      document, Node:dom.window.Node, URLSearchParams,
      window:{XLSX:{...xlsx,writeFile:(book,name)=>downloads.push([book,name])}},
      me:{role,can:{export:true}, commerciaux:[{uid:'a', name:'Jeanne'}]}, detail:()=>{},
      show:box=>main.replaceChildren(box),
      api:async path=>{
        const query=new URLSearchParams(path.split('?')[1]);requests.push(query);
        if(query.get('export')==='1')return {export_phone:manager,rows:Array.from({length:55},()=>({
          started_at:'2026-11-23 14:00:00',user_id:'a',lastname:'Client',phone:'0550123456',
          direction:'inbound',history_status:'ANSWERED',talk_duration:32
        }))};
        return {rows:[{started_at:'2026-11-23 14:00:00',user_id:'a',lastname:'Client',
          prospect_id:12,phone:'0550123456',direction:'inbound',call_status:'ANSWERED',talk_duration:32},
          {started_at:'2026-11-23 14:05:00',user_id:'',phone:'0550000010',direction:'inbound',
            call_status:'ANSWERED',history_status:'SYSTEM_ANSWER',talk_duration:3}],has_more:true};
      }
    });
    vm.runInContext(part('const h =','async function api(')+part('function callHistoryPage()','function recordingsPage()'),ctx);
    vm.runInContext('callHistoryPage()',ctx);
    await tick();
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
    const standard=[...main.querySelectorAll('tbody tr')].find(row=>row.textContent.includes('0550000010'));
    assert.match(standard.textContent,/Standard \/ décroché commercial non confirmé/);
    assert.doesNotMatch(standard.textContent,/Répondu/);
    [...main.querySelectorAll('button')].find(button=>button.textContent==='Exporter Excel').click();
    await tick();
    assert.equal(requests.at(-1).get('export'),'1');
    assert.equal(requests.at(-1).get('client'),'Client Exemple');
    assert.equal(requests.at(-1).get('sort'),'duration');
    const [[file,name]]=downloads;
    assert.match(name,/\.xlsx$/);
    const sheet=file.Sheets['Historique des appels'];
    const records=xlsx.utils.sheet_to_json(sheet,{header:1});
    assert.equal(records.length,56);
    assert.equal(records[0].includes('Téléphone'),manager);
    if(manager)assert.equal(records[1][records[0].indexOf('Téléphone')],'0550123456');
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

test('pilotage renders calls, progress and a sortable workload with team and period filters', async () => {
  const dom=new JSDOM('<main></main>');
  const {document,Node,Event}=dom.window, root=document.querySelector('main');
  const queries=[];
  const rows=[{uid:'a',name:'Alice',status:'online',incoming:2,outgoing:5,pending:3,processed:4,late:2},
    {uid:'b',name:'Brahim',status:'pause',incoming:5,outgoing:2,pending:7,processed:6,late:1}];
  const ctx=vm.createContext({document,Node,URLSearchParams,setInterval:()=>0,clearInterval:()=>{},
    me:{uid:'manager'},show:page=>root.replaceChildren(page),fmt:String,ST:{interesse:'Intéressé'},detail:()=>root.replaceChildren(),
    api:async path=>{
      const query=new URLSearchParams(path.split('?')[1]);queries.push(query);
      if(query.has('detail')) {
        const rows=query.get('detail')==='processed' ? [{at:200,client:'Dossier de test',agent:'Brahim',result:'interesse',prospect_id:7}]
          : [{at:100,phone:'0550000000',ring_seconds:query.get('detail')==='missed'?4:2,total_seconds:12,
            callback_state:'attempted',agent:'Brahim',client:'Dossier à rappeler',prospect_id:12,caller:'Appelant de test',reason:'short_ring',
            answered_by:query.get('detail')==='inbound'?'Brahim':'',
            outcome:{inbound:'answered',outbound:'outgoing_other',outgoing_answered:'outgoing_answered',
              outgoing_no_answer:'outgoing_no_answer'}[query.get('detail')]}];
        const range=query.get('detail')==='closure_window' ? {label:'19/11/2026 16:30 → 22/11/2026 08:30'}
          : {from:'2026-11-01',to:'2026-11-03'};
        return {rows,total:1,range};
      }
      return {answered:7,missed:3,inbound:11,outbound:8,outgoing_answered:4,outgoing_no_answer:2,series:[{slot:1763899200,label:'09',answered:7,missed:3,lost:1}],timezone:'Africa/Algiers',
        closure_window:{count:1,range:{label:'19/11/2026 16:30 → 22/11/2026 08:30'}},
        objective:{current:12,target:20,previous:9},range:{from:'2026-11-23',to:'2026-11-23'},
        teams:[{id:'e1',label:'Équipe 1'}],agents:rows,rows};
    }
  });
  vm.runInContext(part('const h =','async function api(')+part('function pilotagePage()','function dashboardPeriodFilter('),ctx);
  vm.runInContext('pilotagePage()',ctx);
  await tick();
  assert.match(root.textContent,/70 %/);
  assert.match(root.querySelector('.pilotage-kpis').textContent,/Sortants aboutis4.*Sortants sans réponse2/);
  assert.match(root.textContent,/12 \/ 20 appels consignés/);
  [...root.querySelectorAll('.pilotage-kpis button')].find(button=>button.textContent.startsWith('Hors horaires')).click();
  await tick();
  assert.equal(queries.at(-1).get('detail'),'closure_window');
  assert.match(root.querySelector('.crm-pilotage-details').textContent,/19\/11\/2026 16:30 → 22\/11\/2026 08:30/);
  const sort=[...root.querySelectorAll('thead button')].find(button=>button.textContent==='Rappels en retard');
  sort.click();
  assert.equal(root.querySelector('tbody th').textContent,'Brahim');
  const team=root.querySelector('[aria-label="Équipe"]');
  team.value='e1';team.dispatchEvent(new Event('change'));
  await tick();
  assert.equal(queries.at(-1).get('team'),'e1');
  const agent=root.querySelector('[aria-label="Commercial"]');
  agent.value='b';agent.dispatchEvent(new Event('change'));
  await tick();
  assert.equal(queries.at(-1).get('agent'),'b');
  const period=root.querySelector('[aria-label="Période"]');
  period.value='custom';period.dispatchEvent(new Event('change'));
  const dates=root.querySelectorAll('input[type="date"]');
  dates[0].value='2026-11-01';dates[1].value='2026-11-03';
  root.querySelector('form').dispatchEvent(new Event('submit',{cancelable:true}));
  await tick();
  assert.equal(queries.at(-1).get('from'),'2026-11-01');
  assert.equal(queries.at(-1).get('to'),'2026-11-03');
  for(const [label,kind,expected] of [
    ['Appels manqués','missed','4 s'],['Appels perdus','lost','2 s'],['Dossiers traités','processed','Dossier de test'],
    ['Appels entrants','inbound','Décroché'],['Appels sortants','outbound','Autre résultat'],
    ['Sortants aboutis','outgoing_answered','Abouti'],['Sortants sans réponse','outgoing_no_answer','Sans réponse']
  ]) {
    [...root.querySelectorAll('.pilotage-kpis button')].find(button=>button.textContent.startsWith(label)).click();
    await tick();
    const query=queries.at(-1);
    assert.equal(query.get('detail'),kind);
    assert.equal(query.get('period'),'custom');
    assert.equal(query.get('team'),'e1');
    assert.equal(query.get('agent'),'b');
    assert.equal(query.get('from'),'2026-11-01');
    assert.equal(query.get('to'),'2026-11-03');
    const panel=root.querySelector('.crm-pilotage-details');
    assert.equal(panel.hidden,false);
    assert.equal(panel.querySelector('h3').textContent,label);
    assert.ok(panel.querySelector('tbody').textContent.includes(expected));
    if(kind==='missed')assert.match(panel.textContent,/Dossier à rappeler.*Brahim.*Rappel tenté/);
    if(kind==='lost')assert.match(panel.textContent,/Sonnerie de moins de 4 s/);
    if(['inbound','outbound'].includes(kind)){
      assert.match(panel.querySelector('thead').textContent,/DossierAppelant/);
      assert.match(panel.querySelector('tbody').textContent,/Appelant de test/);
      assert.ok([...panel.querySelectorAll('tbody button')].some(button=>button.textContent==='0550000000'));
    }
    if(kind==='inbound'){
      const responder=[...panel.querySelectorAll('thead button')].find(button=>button.textContent==='Répondu par');
      assert.match(panel.querySelector('tbody').textContent,/Brahim/);
      responder.click();
      await tick();
      assert.equal(queries.at(-1).get('sort'),'answered_by');
    }
    panel.querySelector('button').click();
    assert.equal(panel.hidden,true);
  }
  root.querySelector('.pilotage-bars button.missed').click();
  await tick();
  assert.equal(queries.at(-1).get('slot'),'1763899200');
  assert.equal(queries.at(-1).get('detail'),'missed');
  assert.match(root.querySelector('.crm-pilotage-details h3').textContent,/09 h – 10 h/);
  [...root.querySelectorAll('.crm-pilotage-details thead button')].find(button=>button.textContent==='Durée totale').click();
  await tick();
  assert.equal(queries.at(-1).get('sort'),'total_seconds');
  assert.equal(queries.at(-1).get('dir'),'asc');
  assert.equal(queries.at(-1).get('slot'),'1763899200');
  assert.equal(queries.at(-1).get('offset'),'0');
  root.querySelector('.crm-pilotage-details th[aria-sort="ascending"] button').click();
  await tick();
  assert.equal(queries.at(-1).get('dir'),'desc');
  const beforeRefresh=queries.length;
  dates[0].value='';
  [...root.querySelectorAll('button')].find(button=>button.textContent==='Actualiser').click();
  await tick();
  assert.equal(queries.length,beforeRefresh+2);
  assert.equal(queries[beforeRefresh].get('from'),'2026-11-01');
  assert.equal(queries[beforeRefresh].get('team'),'e1');
  assert.equal(queries[beforeRefresh].get('agent'),'b');
  assert.equal(queries.at(-1).get('slot'),'1763899200');
  assert.equal(queries.at(-1).get('sort'),'total_seconds');
  assert.equal(root.querySelector('.crm-pilotage-details').hidden,false);
  [...root.querySelectorAll('.crm-pilotage-details button')].find(button=>button.textContent==='Dossier à rappeler').click();
  vm.runInContext('pilotagePage()',ctx);
  await tick();
  assert.equal(queries.at(-1).get('slot'),'1763899200');
  assert.equal(queries.at(-1).get('sort'),'total_seconds');
  assert.equal(queries.at(-1).get('dir'),'desc');
  assert.equal(queries.at(-1).get('team'),'e1');
  assert.equal(queries.at(-1).get('agent'),'b');
  assert.equal(root.querySelector('[aria-label="Période"]').value,'custom');
  assert.equal(root.querySelector('[aria-label="Équipe"]').value,'e1');
  assert.equal(root.querySelector('[aria-label="Commercial"]').value,'b');
  assert.equal(root.querySelector('.crm-pilotage-details').hidden,false);
  dom.window.close();
});

test('server-paged journal keeps one column toolbar across repeated sorts', async () => {
  const dom=new JSDOM('<main><div style="overflow-x:auto"><div id="journal"></div></div></main>');
  const {document,Node,MutationObserver}=dom.window,root=document.querySelector('main');
  const ctx=vm.createContext({document,Node,MutationObserver,root,
    tblStore:{get:(_key,defaults)=>defaults,set:()=>{}}});
  vm.runInContext(part('const h =','async function api(')+source.slice(source.indexOf('const tableCleanup ='),source.lastIndexOf('})();')),ctx);
  vm.runInContext(`
    const journal=document.querySelector('#journal');
    let desc=false;
    function render() {
      journal.replaceChildren(h('table',{'data-server-paged':'1'},
        h('thead',{},h('tr',{},h('th',{onclick:()=>{desc=!desc;render();}},'Date '+(desc?'▼':'▲')),h('th',{},'Client'))),
        h('tbody',{},h('tr',{},h('td',{},desc?'20':'10'),h('td',{},'Test')))));
    }
    render();
  `,ctx);
  await tick();
  for(let i=0;i<4;i++){
    root.querySelector('th').click();
    await tick();
    assert.equal(root.querySelectorAll('.crm-tbl-tools').length,1);
    assert.equal(root.querySelectorAll('.crm-tbl-pager').length,1);
    assert.equal(root.querySelector('tbody td').textContent,i%2===0?'20':'10');
  }
  root.querySelector('#journal').replaceChildren(document.createElement('p'));
  await tick();
  assert.equal(root.querySelectorAll('.crm-tbl-tools,.crm-tbl-pager').length,0);
  dom.window.close();
});

test('journal displays verified call direction and opens the client once', async () => {
  const dom=new JSDOM('<main></main>',{url:'https://cloud.example.test'});
  const {document,Node,localStorage}=dom.window;
  const box=document.querySelector('main'), opened=[];
  const ctx=vm.createContext({document,Node,localStorage,URLSearchParams,box,fmt:String,nm:r=>r.lastname,
    td:(...children)=>{const cell=document.createElement('td');cell.append(...children);return cell;},
    badge:String,detail:id=>opened.push(id),api:async()=>({total:3,rows:[
      {prospect_id:1,lastname:'Client entrant',call_direction:'inbound',created_at:1},
      {prospect_id:2,lastname:'Client sortant',call_direction:'outbound',created_at:2},
      {prospect_id:3,lastname:'Client ancien',call_direction:null,created_at:3}
    ].map(r=>({...r,user_name:'Agent',phone:'0550123456',result:'interesse'}))})});
  vm.runInContext(part('const h =','async function api(')+part('  let jp = null','  let jt;'),ctx);
  await vm.runInContext('jp=new URLSearchParams();jload()',ctx);
  assert.ok([...box.querySelectorAll('th')].some(th=>th.textContent.startsWith('Sens')));
  assert.deepEqual([...box.querySelectorAll('tbody tr')].map(r=>r.cells[1].textContent),['Entrant','Sortant','Indéterminé']);
  [...box.querySelectorAll('tbody button')].find(button=>button.textContent==='Client entrant').click();
  assert.deepEqual(opened,[1]);
  dom.window.close();
});
