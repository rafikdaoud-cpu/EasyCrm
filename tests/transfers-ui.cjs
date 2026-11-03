const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {JSDOM}=require('jsdom');
for(const edition of ['callcrm','nextcloud']) test(edition+': transfer to unassigned keeps filters and requires confirmation',async()=>{
 const source=fs.readFileSync(`${edition}/js/app.js`,'utf8'),dom=new JSDOM('<body></body>'),calls=[],confirmations=[];
 const context=vm.createContext({document:dom.window.document,window:dom.window,Node:dom.window.Node,T:s=>s,me:{commerciaux:[{uid:'alice',name:'Alice'}]},ST:{nouveau:'Nouveau'},api:async(path,options)=>{calls.push({path,...options.body});return options.body.dry?{count:3}:{transferred:3};},confirm:s=>{confirmations.push(s);return true;}});
 vm.runInContext(source.slice(source.indexOf('const h ='),source.indexOf('async function api('))+source.slice(source.indexOf('function transferCard()'),source.indexOf('function bulkStatusCard()'))+';document.body.append(transferCard());',context);
 const doc=dom.window.document,[from,to,status]=doc.querySelectorAll('select'),buttons=[...doc.querySelectorAll('button')];
 buttons.find(b=>b.textContent==='Compter').click();await new Promise(r=>setImmediate(r));assert.equal(calls.length,0,'placeholder is not an unassign request');
 from.value='alice';to.value='__none';status.value='';doc.querySelector('input').value='3';
 buttons.find(b=>b.textContent==='Compter').click();await new Promise(r=>setImmediate(r));assert.equal(calls[0].to,'__none');assert.equal(calls[0].status,'');assert.equal(calls[0].limit,3);
 buttons.find(b=>b.textContent==='Transférer').click();await new Promise(r=>setImmediate(r));assert.equal(calls.at(-1).dry,false);assert.equal(calls.at(-1).from,'alice');assert.equal(calls.at(-1).to,'__none');assert.match(confirmations[0],/Non assign/);assert.match(doc.body.textContent,/3 prospect\(s\) transféré/);
});
