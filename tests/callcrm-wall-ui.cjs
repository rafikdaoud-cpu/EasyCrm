const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync('callcrm/js/wall.js','utf8');
const tick=()=>new Promise(r=>setTimeout(r,30));

test('écran du plateau : chiffres du jour, top 3 et aucune donnée client',async()=>{
  const dom=new JSDOM('<main id="crm-wall" data-api="/api/public/ecran/x"></main>',{url:'https://cloud.example.test',runScripts:'outside-only'});
  const w=dom.window;
  const data={now:1795600000,timezone:'Africa/Algiers',open_missed:12,late_callbacks:3,team:{online:6,pause:1,total:9},
    calls:{inbound:284,answered:56,missed:105,lost:16,menu:37,closed:70,unknown:0,outbound:184,outgoing_answered:96,outgoing_no_answer:31,missed_called_back:9,lost_called_back:3,
      series:[{label:'09',answered:5,missed:3,lost:1},{label:'10',answered:8,missed:6,lost:0},{label:'03',answered:0,missed:0,lost:0}]},
    agents:[{name:'Sara Mimoun',status:'online',processed:40,answered:12,outgoing:30,reached:18},{name:'Nawel Belaifa',status:'online',processed:31,answered:9,outgoing:22,reached:11},
      {name:'Rofaida Lezzar',status:'pause',processed:20,answered:7,outgoing:10,reached:5},{name:'Amira Berghout',status:'absent',processed:4,answered:1,outgoing:2,reached:1}]};
  w.fetch=async()=>({ok:true,json:async()=>data});
  w.setInterval=()=>0;
  w.eval(source);await tick();
  const text=w.document.body.textContent;
  assert.match(text,/Appels entrants284/);
  assert.match(text,/Taux de réponse[\s\S]*32 %/);              // 56 / (56+105+16)
  assert.match(text,/12 déjà rappelés/);
  assert.deepEqual([...w.document.querySelectorAll('.crm-wall-name')].map(n=>n.textContent),['Sara Mimoun','Nawel Belaifa','Rofaida Lezzar']);
  assert.match(text,/Amira 4/);                                  // les autres, en une ligne
  assert.equal(w.document.querySelectorAll('.crm-wall-hour').length,2,'heures de nuit masquées');
  assert.match(text,/6 \/ 9/);
});

test('écran du plateau : lien renouvelé ou invalide',async()=>{
  const dom=new JSDOM('<main id="crm-wall" data-api="/api/public/ecran/x"></main>',{url:'https://cloud.example.test',runScripts:'outside-only'});
  const w=dom.window;
  w.fetch=async()=>({ok:false,json:async()=>({error:'Lien invalide ou renouvelé.'})});
  w.setInterval=()=>0;
  w.eval(source);await tick();
  assert.match(w.document.body.textContent,/Lien invalide ou renouvelé/);
});
