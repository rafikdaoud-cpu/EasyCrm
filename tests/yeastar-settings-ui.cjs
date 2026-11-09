const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {JSDOM}=require('jsdom');
test('Yeastar settings preserve credentials, associations and unsaved-edit protection',async()=>{
 const source=fs.readFileSync('callcrm/js/app.js','utf8'),dom=new JSDOM('<main></main>'),document=dom.window.document;
 const saved={url:'https://erp.csmtaksit.dz:8088',client_id:'client-test',has_secret:true,linkus_url:'',extensions:[{uid:'alice',extension:'1000'}]};
 let posted,guard;
 const ctx=vm.createContext({document,Node:dom.window.Node,setTimeout,me:{commerciaux:[{uid:'alice',name:'Alice'},{uid:'bob',name:'Bob'}]},watchEdits:(key,label,check)=>guard=check,api:async(path,opt)=>{assert.equal(path,'/yeastar/settings');if(opt){posted=opt.body;return {...saved,has_secret:!opt.body.clear_secret};}return saved;}});
 vm.runInContext(source.slice(source.indexOf('const h ='),source.indexOf('async function api('))+source.slice(source.indexOf('function yeastarSettingsCard()'),source.indexOf('function documentSettingsCard()')),ctx);
 const box=vm.runInContext('yeastarSettingsCard()',ctx);document.querySelector('main').append(box);
 await new Promise(r=>setTimeout(r,20));
 const secret=box.querySelector('input[type=password]');assert.equal(secret.value,'');assert.match(secret.placeholder,/enregistrée/);assert.equal(guard(),false);
 const button=text=>[...box.querySelectorAll('button')].find(b=>b.textContent===text);
 button('+ Associer un commercial').click();assert.equal(guard(),true);
 const row=box.querySelector('.crm-yeastar-extensions').lastElementChild;row.querySelector('select').value='bob';row.querySelector('input').value='1001';
 secret.value='fresh-key';button('Enregistrer les réglages Yeastar').click();await new Promise(r=>setTimeout(r,20));
 assert.equal(posted.secret,'fresh-key');assert.equal(posted.extensions.length,2);assert.equal(posted.extensions[1].extension,'1001');assert.equal(secret.value,'');assert.equal(guard(),false);
 row.querySelector('button').click();assert.equal(guard(),true);
 assert.match(box.textContent,/ne déclenchent pas encore les appels/);
});
