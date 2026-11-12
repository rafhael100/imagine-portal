import test from 'node:test';
import assert from 'node:assert/strict';
import {safeCsvCell,projectsCsvChunks} from '../server/account-export.mjs';

test('F247: all Unicode default-ignorable prefixes before spreadsheet formulas are neutralized',()=>{
 const prefixes=['\u00AD','\u034F','\u061C','\u115F','\u180E','\uFE0F','\u{E0001}'];
 const operators=['=','+','-','@'];
 for(const prefix of prefixes)for(const operator of operators){
  const input=prefix+operator+'SUM(1,2)';
  const csv=safeCsvCell(input);
  assert.ok(csv.startsWith('"\''),JSON.stringify({prefix,operator,csv}));
  assert.ok(csv.includes(operator+'SUM(1,2)'),'original text is not deleted');
 }
});

test('F247: benign cells remain unchanged and values without formulas are not prefixed',()=>{
 for(const value of ['Empresa Alfa','12345','- reservado','\u034FProjeto aprovado']){
  const csv=safeCsvCell(value);
  if(value==='- reservado')assert.ok(csv.startsWith('"\'')); // '-' may start a formula
  else assert.equal(csv,'"'+value+'"');
 }
});

test('F247: streamed CSV protects client-controlled project title and domain',()=>{
 const payload='\u00AD=HYPERLINK("https://example.test","x")';
 const csv=[...projectsCsvChunks([{id:'p-1',title:payload,created_at:'2026-11-11',
  payment_status:'RECEIVED',data:{company_name:'\u034F+1',domain:'\u061C@cmd'}}])].join('');
 assert.ok(csv.includes('"\'\u00AD=HYPERLINK(""https://example.test"",""x"")"'));
 assert.ok(csv.includes('"\'\u034F+1"'));
 assert.ok(csv.includes('"\'\u061C@cmd"'));
});
