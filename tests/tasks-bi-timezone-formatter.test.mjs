import test from 'node:test';
import assert from 'node:assert/strict';
import {saoPauloDay} from '../server/tasks-bi.mjs';

const previousDay=value=>value?new Intl.DateTimeFormat('en-CA',{
 timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'
}).format(new Date(value.length===10?value+'T12:00:00Z':value)):'';

test('F285: reused São Paulo formatter preserves exact old behavior at calendar boundaries',()=>{
 const cases=[
  null,undefined,'',
  '2026-11-12','2026-11-12T02:30:00Z','2026-11-12T03:30:00Z',
  '2026-10-01T01:30:00Z','2026-11-01T01:30:00Z',
  '2026-01-01T01:00:00Z','2024-02-29','2020-02-29',
  '2018-11-04T03:00:00Z','2018-02-18T02:30:00Z',
  '2026-12-31T23:50:00Z','2027-01-01T01:30:00Z'
 ];
 for(const value of cases)
  assert.equal(saoPauloDay(value),previousDay(value),String(value));
 for(let i=0;i<450;i++){
  const value=new Date(Date.UTC(2017+i%12,i%12,1+i%28,i%24,(i*7)%60)).toISOString();
  assert.equal(saoPauloDay(value),previousDay(value),value);
 }
 assert.throws(()=>saoPauloDay('not-a-date'),RangeError);
 assert.equal(saoPauloDay('2026-11-12T02:30:00Z'), '2026-11-11',
  'a late UTC evening still belongs to the prior São Paulo calendar day');
});
