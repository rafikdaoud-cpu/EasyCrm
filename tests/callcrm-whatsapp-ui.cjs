const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync('callcrm/js/app.js','utf8');
const code=source.slice(source.indexOf('const h ='),source.indexOf('async function api('))+source.slice(source.indexOf('const waCanSend'),source.indexOf('function callDone()'));
const tick=()=>new Promise(r=>setTimeout(r,20));
const templates=[
 {name:'relance',language:'fr',category:'MARKETING',named:false,header:'',header_params:[],body:'Bonjour {{1}}, dossier {{2}}.',body_params:['1','2'],footer:'CSM Taksit',buttons:['Rappelez-moi'],unsupported:''},
 {name:'photo',language:'fr',category:'MARKETING',named:false,header:'',header_params:[],body:'x',body_params:[],footer:'',buttons:[],unsupported:'en-tête image'}];
const setup=(extra={})=>{
 const dom=new JSDOM('<main></main>',{url:'https://cloud.example.test'}),document=dom.window.document,root=document.querySelector('main');
 const calls=[],opened=[];
 const ctx=vm.createContext({document,window:dom.window,Node:dom.window.Node,root,setTimeout,fmt:String,detail:()=>{},me:{role:'commercial',uid:'alice'},
  waWin:()=>'',waConv:(id,back)=>opened.push(id),
  api:async(path,opt)=>{calls.push([path,opt&&JSON.parse(JSON.stringify(opt.body||null))]);if(path==='/wa/templates')return{templates};if(path.startsWith('/wa/prospect/')&&!opt)return{conv:extra.conv||null};if(extra.fail)throw new Error('Envoi refusé : quota');return{ok:true,conv:77};},...extra.ctx});
 vm.runInContext(code,ctx);return {dom,document,root,ctx,calls,opened};
};

test('template composer lists approved templates, fills variables, previews and sends a valid payload',async()=>{
 const {document,ctx,calls}=setup();const done=[];
 const box=vm.runInContext('waComposer',ctx)({windowOpen:false,clientName:'Ali <b>B</b>',send:body=>ctx.api('/wa/1/send',{method:'POST',body}),done:r=>done.push(r)});
 document.querySelector('main').append(box);await tick();
 const sel=box.querySelector('select');const opts=[...sel.options];
 assert.equal(opts.length,3);assert.equal(opts[2].disabled,true);assert.match(opts[2].textContent,/non pris en charge/);
 const send=[...box.querySelectorAll('button')].find(b=>b.textContent==='Envoyer le modèle');
 send.click();await tick();assert.match(box.textContent,/Choisissez un modèle/);
 sel.value='0';sel.dispatchEvent(new document.defaultView.Event('change'));
 const inputs=box.querySelectorAll('input[data-part]');assert.equal(inputs.length,2);assert.equal(inputs[0].value,'Ali <b>B</b>');assert.equal(inputs[1].value,'');
 assert.equal(box.querySelector('.wa-tpl-preview b'),null,'preview is text only');assert.match(box.querySelector('.wa-tpl-preview').textContent,/Bonjour Ali <b>B<\/b>, dossier \{\{2\}\}/);
 send.click();await tick();assert.match(box.textContent,/variable \{\{2\}\}/);assert.equal(calls.filter(([p])=>p==='/wa/1/send').length,0);
 inputs[1].value='A12';inputs[1].dispatchEvent(new document.defaultView.Event('input'));assert.match(box.querySelector('.wa-tpl-preview').textContent,/dossier A12\.CSM Taksit/);
 send.click();await tick();
 const [,body]=calls.find(([p])=>p==='/wa/1/send');
 assert.deepEqual(body,{template:'relance',language:'fr',values:{header:{},body:{'1':'Ali <b>B</b>','2':'A12'}}});assert.equal(done.length,1);
});

test('free text is offered only inside the 24-hour window and errors stay visible',async()=>{
 const {document,ctx,calls}=setup({fail:true});
 const box=vm.runInContext('waComposer',ctx)({windowOpen:true,clientName:'',send:body=>ctx.api('/wa/1/send',{method:'POST',body}),done:()=>{throw new Error('should not finish');}});
 document.querySelector('main').append(box);
 assert.equal(box.querySelector('select'),null,'templates load only on demand');
 box.querySelector('textarea').value='  Bonjour  ';box.querySelector('button').click();await tick();
 assert.deepEqual(calls.at(-1)[1],{text:'Bonjour'});assert.match(box.textContent,/quota/);assert.equal(box.querySelector('button').disabled,false);
 const more=box.querySelector('details');more.open=true;more.dispatchEvent(new document.defaultView.Event('toggle'));await tick();assert.ok(box.querySelector('select'));
});

test('client file offers a first WhatsApp message to its own commercial and opens the new conversation',async()=>{
 const own=setup();
 own.root.append(vm.runInContext('waOnProspect',own.ctx)(5,{lastname:'Benali',firstname:'Ali',phone:'0770000001',assigned_to:'alice'}));await tick();
 const button=[...own.root.querySelectorAll('button')].find(b=>/Envoyer un message WhatsApp/.test(b.textContent));assert.ok(button);
 button.click();await tick();const dialog=own.root.querySelector('.crm-dialog-bg');assert.ok(dialog);
 const sel=dialog.querySelector('select');sel.value='0';sel.dispatchEvent(new own.document.defaultView.Event('change'));
 assert.equal(dialog.querySelector('input[data-k="1"]').value,'Ali Benali');dialog.querySelector('input[data-k="2"]').value='X';
 [...dialog.querySelectorAll('button')].find(b=>b.textContent==='Envoyer le modèle').click();await tick();
 assert.equal(own.calls.at(-1)[0],'/wa/prospect/5/send');assert.equal(own.root.querySelector('.crm-dialog-bg'),null);assert.deepEqual(own.opened,[77]);
 const other=setup();
 other.root.append(vm.runInContext('waOnProspect',other.ctx)(6,{lastname:'X',phone:'0770000002',assigned_to:'bob'}));await tick();
 assert.equal([...other.root.querySelectorAll('button')].some(b=>/Envoyer/.test(b.textContent)),false,'not for another commercial');
 const sup=setup({ctx:{me:{role:'superviseur',uid:'sam'}},conv:{id:9,window_open:true,unread:0,preview:'ok',last_msg_at:1}});
 sup.root.append(vm.runInContext('waOnProspect',sup.ctx)(7,{lastname:'Y',phone:'0770000003',assigned_to:'bob'}));await tick();
 [...sup.root.querySelectorAll('button')].find(b=>/Envoyer un message/.test(b.textContent)).click();await tick();
 assert.ok(sup.root.querySelector('.crm-dialog textarea'),'open window allows free text');
 const ro=setup({ctx:{me:{role:'lecture',uid:'r'}}});ro.root.append(vm.runInContext('waOnProspect',ro.ctx)(8,{lastname:'Z',phone:'0770000004'}));await tick();
 assert.equal(ro.root.textContent,'','read-only users see nothing new');
});
