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

test('callback reminder waits while a call or a client file is being handled and appears once the file is left',async()=>{
 const dom=new JSDOM('<main><div class="cols" data-pid="5"></div></main>',{url:'https://cloud.example.test'}),document=dom.window.document,root=document.querySelector('main');
 const due={id:9,assigned_to:'alice',callback_at:1,phone:'0770000009',lastname:'Client'};
 const ctx=vm.createContext({document,window:dom.window,Node:dom.window.Node,root,setTimeout:()=>{},fmt:String,nm:p=>p.lastname,waNum:String,saveSnooze:()=>{},snooze:{},popup:false,myPresence:null,
  dirtyChecks:new Map(),yeastarPopups:new Map(),pbxState:s=>s==='BYE'?'Terminé':s,detail:()=>{},me:{uid:'alice',can:{call:true}},callBusy:{pid:null,until:0},api:async()=>[due]});
 vm.runInContext(part('const h =','async function api(')+source.slice(source.indexOf('function callDone()'),source.lastIndexOf('(async () => {')),ctx);
 const check=async()=>{await vm.runInContext('checkReminders()',ctx);await tick();return document.querySelectorAll('.ccp-bg').length;};
 ctx.callBusy={pid:'5',until:Date.now()+60000};
 assert.equal(await check(),0,'call started from the open file');
 ctx.callBusy={pid:'5',until:Date.now()-1};
 ctx.dirtyChecks.set('call',{label:'r',check:()=>true});assert.equal(await check(),0,'result being typed');ctx.dirtyChecks.clear();
 const popup={box:document.createElement('aside'),state:document.createElement('p')};popup.state.textContent='Décroché';document.body.append(popup.box);ctx.yeastarPopups.set('c',popup);
 assert.equal(await check(),0,'line still active');popup.state.textContent='Terminé';
 const form=document.createElement('div');form.className='card crm-new-client';root.append(form);assert.equal(await check(),0,'new file being created');form.remove();
 ctx.callBusy={pid:'5',until:Date.now()+60000};vm.runInContext('callDone()',ctx);
 assert.equal(await check(),0,'call finished but the file is still open: no reminder while the commercial handles it');
 root.querySelector('.cols').remove();
 assert.equal(await check(),1,'shown once the commercial leaves the file');
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
