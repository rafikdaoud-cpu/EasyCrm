const {chromium}=require('playwright-core'),fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});const out=process.argv[2]||'/tmp/crm-activity-previews';fs.mkdirSync(out,{recursive:true});
for(const [folder,id]of [['callcrm','callcrm'],['nextcloud','easycrm']]){
const page=await browser.newPage({viewport:{width:1440,height:1050}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.setContent(`<html lang="fr"><body style="margin:0;--color-main-background:#fff;--color-main-text:#182237;--color-border:#dfe5ed"><div id="${id}" data-api="/api"></div></body></html>`);await page.addStyleTag({content:fs.readFileSync(folder+'/css/style.css','utf8')});
await page.evaluate(()=>{
const statuses=[['nouveau','Nouveau'],['interesse','Intéressé'],['injoignable','Ne répond pas'],['rappel','À rappeler'],['pas_interesse','Pas intéressé']].map(([key,label])=>({key,label,color:'#2875c7',active:true,result:key!=='nouveau',reason:key==='pas_interesse',callback:key==='rappel',reason_options:key==='pas_interesse'?['Prix trop élevé','Projet reporté','Autre']:['Demande de devis','Autre']}));
const client={id:1,lastname:'Nadia Exemple',firstname:'',phone:'0550000000',status:'interesse',assigned_to:'alice',remarque:'À contacter le matin.',created_at:1793690000,extra:{}};
window.fetch=async(raw,options={})=>{const u=new URL(raw,'https://example.test'),k=u.pathname;let data={};
if(k==='/api/me')data={uid:'admin',role:'admin',can:{call:true,all:true,assign:true,import:true},commerciaux:[{uid:'alice',name:'Leïla Exemple'}],groups:[],teams:{},options:{c2:[]},statuses,fields:[{key:'lastname',label:'Nom',type:'text',builtin:true},{key:'remarque',label:'Note du client',type:'textarea',builtin:true}],prefs:{tz:'Africa/Algiers',reasons:['Autre']},tel:{wa:'link',pbx:'none'}};
else if(k==='/api/prospects')data=u.searchParams.has('paged')?{rows:[],total:0}:[client];
else if(k==='/api/prospects/1')data={prospect:client,calls:[],recordings:[],sims:[]};
else if(k==='/api/agenda')data={counts:{today:7,tomorrow:4,late:2},timezone:'Africa/Algiers',rows:[{id:'callback:1',prospect_id:1,starts_at:1793692800,late:true,client_name:'Nadia Exemple',phone:'0550000000',title:'Rappel client',notes:'Le client souhaite être rappelé après son rendez-vous.',owner:'Leïla Exemple',type:'callback'},{id:'appointment:1',prospect_id:1,starts_at:1793696400,title:'Prise de mesures',notes:'Cuisine équipée · Alger centre',owner:'Leïla Exemple',client_name:'Karim Exemple',phone:'0550000001',type:'appointment',appointment_id:1,revision:1}],has_more:false};
else if(k.endsWith('/timeline'))data={rows:[{id:'3:1',kind:'call',created_at:1793690000,author:'Leïla Exemple',body:{result:'interesse',reason:'Demande de devis',note:'Disponible demain matin.'}},{id:'2:1',kind:'assign',created_at:1793680000,author:'Mourad Exemple',body:{from:'Non assigné',to:'Leïla Exemple'}}],has_more:false};
else if(k.endsWith('/appointments'))data={rows:[],timezone:'Africa/Algiers'};
else if(k==='/api/announcements'||k==='/api/recordings'||k.includes('/wa/prospect/'))data=[];
else if(k==='/api/counters')data={overdue:2};
else if(k.includes('/update')){client.remarque=JSON.parse(options.body).remarque;data={ok:true};}
else if(k.endsWith('/call'))data={ok:true};
return{ok:true,status:200,json:async()=>data};};
});
await page.addScriptTag({content:fs.readFileSync(folder+'/js/app.js','utf8')});await page.locator('.crm-sidebar [data-k=agenda]').click();await page.locator('.crm-agenda-entry').first().waitFor();await page.screenshot({path:path.join(out,id+'-agenda-desktop.png')});
await page.setViewportSize({width:390,height:980});await page.screenshot({path:path.join(out,id+'-agenda-mobile.png')});
await page.getByRole('button',{name:'Ouvrir la fiche'}).first().click();await page.locator('.crm-event').first().waitFor();assert.equal(await page.locator('.crm-client-footer').isVisible(),true);
await page.screenshot({path:path.join(out,id+'-client-mobile.png')});
await page.locator('.crm-client-info summary').click();await page.locator('[data-k=remarque]').fill('Commentaire non enregistré');await page.locator('.crm-sidebar [data-k=agenda]').click();await page.locator('.crm-leave-warning').waitFor();await page.screenshot({path:path.join(out,id+'-alerte-mobile.png')});await page.getByRole('button',{name:'Rester sur la fiche'}).click();assert.equal(await page.locator('[data-k=remarque]').inputValue(),'Commentaire non enregistré');
await page.locator('.res [data-r=pas_interesse]').click();assert.ok(await page.locator('select').filter({has:page.locator('option[value="Prix trop élevé"]')}).count()>0);
await page.locator('.crm-reason-options').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(out,id+'-motifs-mobile.png')});assert.deepEqual(errors,[]);console.log('PASS '+id+' agenda, chronology, mobile actions, per-outcome motifs and leave guard');await page.close();}
await browser.close();})().catch(e=>{console.error(e);process.exit(1)});
