import test from 'node:test';
import assert from 'node:assert/strict';
import {projectsCsv,projectsCsvChunks,safeCsvCell,accountDataExport} from '../server/account-export.mjs';

test('F177: valores de planilhas potencialmente executáveis viram texto, inclusive com espaços',()=>{
 for(const value of ['=1+1','+SUM(1,2)','-1+2','@SUM(1)','  =HYPERLINK("https://example.test")','\t=2+2','\u200B=1+1','\u2060@SUM(1)','\u202E=HYPERLINK(1)','\u0000+1']){
  assert.ok(safeCsvCell(value).startsWith('"\''),value);
 }
 assert.equal(safeCsvCell('Normal "entre aspas"; ok'),'"Normal ""entre aspas""; ok"');
 assert.equal(safeCsvCell('Primeira\r\nSegunda'),'"Primeira\nSegunda"');
});

test('F177: exportação CSV é compatível com Excel pt-BR e não inventa dados indisponíveis',()=>{
 const text=projectsCsv([{id:'p1',title:'=HYPERLINK("https://example.test","click")',
  created_at:'2026-11-11T10:00:00Z',payment_status:'PENDING',data:{}},
  {id:'p2',title:'Projeto; "Empresa"',created_at:'2026-11-10',payment_status:'RECEIVED',
   data:{company_name:'Empresa B',domain:'example.test'}}]);
 assert.ok(text.startsWith('\uFEFF"ID";"Projeto";"Criado em";"Situação do pagamento";"Empresa";"Domínio"\r\n'));
 assert.ok(text.includes('"\'=HYPERLINK(""https://example.test"",""click"")"'));
 assert.ok(text.includes('"Projeto; ""Empresa"""'));
 assert.ok(text.includes('"p1";"\'=HYPERLINK'));
 assert.ok(text.includes(';"PENDING";"";""\r\n'));
 assert.ok(text.endsWith('\r\n'));
 assert.throws(()=>projectsCsv({}),TypeError);
});

test('F216: extended project CSV preserves original columns and appends optional phase',()=>{
 const rows=[{id:'safe-001',title:'Site',created_at:'2026-11-11',phase:'desenvolvimento',payment_status:'RECEIVED',
  data:{company_name:'Empresa',domain:'empresa.test'}}];
 const legacy=projectsCsv(rows),enriched=projectsCsv(rows,{withStage:true});
 assert.equal(legacy.includes('Etapa do projeto'),false);
 assert.ok(enriched.startsWith(legacy.split('\r\n')[0]+';"Etapa do projeto"\r\n'));
 assert.ok(enriched.includes('"Empresa";"empresa.test";"desenvolvimento"'));
 assert.equal((enriched.match(/\r\n/g)||[]).length,2);
});
test('F181: JSON export has an explicit allowlist even with unexpected sensitive columns',()=>{
 const source={
  user:{id:'private-user-id',name:'Cliente',email:'cliente@example.test',password_hash:'hash-private',csrf:'csrf-private',permissions:['all']},
  created_at:'2026-11-10T00:00:00Z',
  profile:{company:'Empresa',phone:'11999999999',version:2,api_secret:'profile-secret',internal_flag:true},
  fiscal:{legal_name:'Empresa Ltda',document:'52998224725',email:'financeiro@example.test',version:1,bank_token:'financial-secret'},
  projects:[{id:'p-1',title:'Meu site',created_at:'2026-11-01',payment_status:'RECEIVED',status:'approved',internal_owner_id:'staff-secret',data:{
   company_name:'Empresa',domain:'empresa.example',company_intro:'Apresentação autorizada',notes:'Observações do cliente',
   private_api_key:'project-secret',password_hash:'unsafe-hash',internal_moderation:'internal',
  }}],
 };
 const data=accountDataExport(source,'2026-11-11T14:00:00Z');
 assert.deepEqual(data.user,{name:'Cliente',email:'cliente@example.test'});
 assert.equal(data.exported_at,'2026-11-11T14:00:00Z');
 assert.equal(data.profile.company,'Empresa');
 assert.equal(data.profile.api_secret,undefined);
 const nested=accountDataExport({...source,profile:{company:{nested_token:'NOT_FOR_EXPORT'},version:2},
  fiscal:{document:['DO_NOT_EXPORT'],version:1},
  projects:[{...source.projects[0],data:{company_name:['NOT_FOR_EXPORT'],domain:'example.test'}}]});
 assert.deepEqual(nested.profile,{version:2});
 assert.deepEqual(nested.fiscal,{version:1});
 assert.deepEqual(nested.projects[0].data,{domain:'example.test'});
 assert.equal(data.fiscal.bank_token,undefined);
 assert.equal(data.projects[0].internal_owner_id,undefined);
 assert.deepEqual(Object.keys(data.projects[0].data).sort(),['company_intro','company_name','domain','notes']);
 const text=JSON.stringify(data);
 for(const secret of ['private-user-id','hash-private','csrf-private','profile-secret','financial-secret','project-secret','staff-secret','unsafe-hash','internal']){
  assert.equal(text.includes(secret),false,secret);
 }
 assert.throws(()=>accountDataExport({projects:null}),TypeError);
});

test('F229: chunked CSV matches legacy output exactly and iterates without materializing rows',()=>{
 const rows=Array.from({length:117},(_,i)=>({id:'p'+i,title:i%2?'=SUM(1,2)':'Projeto '+i,created_at:'2026-11-11',payment_status:'RECEIVED',phase:'revisao',data:{company_name:'Empresa '+i,domain:'empresa.test'}}));
 for(const withStage of [false,true]){
  let visited=0;
  function *source(){for(const row of rows){visited++;yield row;}}
  const parts=[...projectsCsvChunks(source(),{withStage})];
  assert.equal(visited,117);
  assert.equal(parts.length,118);
  assert.equal(parts.join(''),projectsCsv(rows,{withStage}));
  assert.ok(parts[0].startsWith('\\uFEFF'));
  assert.ok(parts[2].includes("'=SUM(1,2)"));
 }
 assert.throws(()=>[...projectsCsvChunks(null)],TypeError);
});
