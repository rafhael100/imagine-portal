import test from 'node:test';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const script=fileURLToPath(new URL('../scripts/bench-tasks-bi.mjs',import.meta.url));
const run=args=>spawnSync(process.execPath,['--experimental-sqlite',script,...args],{
 encoding:'utf8',timeout:20000,env:{...process.env,NODE_ENV:'test'}
});

test('F286: local synthetic BI benchmark returns measurable JSON without external access',()=>{
 const result=run(['--rows','24','--iterations','2']);
 assert.equal(result.status,0,result.stderr);
 const data=JSON.parse(result.stdout.trim());
 assert.equal(data.benchmark,'legacy-node-tasks-bi');
 assert.equal(data.synthetic,true);
 assert.equal(data.rows,24);
 assert.equal(data.total_tasks,24);
 assert.equal(data.iterations,2);
 assert.equal(data.duration_ms.length,2);
 assert.ok(data.duration_ms.every(x=>typeof x==='number'&&Number.isFinite(x)&&x>=0));
 assert.ok(Number.isFinite(data.median_ms));
 assert.equal(data.months,1);
 assert.match(data.note,/not representative of Laravel\/PostgreSQL production/);
});

test('F286: benchmark rejects unbounded or invalid workload parameters',()=>{
 for(const args of [['--rows','0'],['--rows','20001'],['--iterations','11'],['--rows','NaN']]){
  const result=run(args);
  assert.equal(result.status,1,'invalid parameter should be rejected: '+args.join(' '));
  assert.equal(result.stdout,'');
  assert.match(result.stderr,/Synthetic benchmark failed: Invalid/);
 }
});
