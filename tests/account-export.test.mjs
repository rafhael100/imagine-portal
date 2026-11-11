import test from 'node:test';
import assert from 'node:assert/strict';
import {projectsCsv,safeCsvCell} from '../server/account-export.mjs';

test('F177: valores de planilhas potencialmente executáveis viram texto, inclusive com espaços',()=>{
 for(const value of ['=1+1','+SUM(1,2)','-1+2','@SUM(1)','  =HYPERLINK("https://example.test")','\t=2+2']){
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
