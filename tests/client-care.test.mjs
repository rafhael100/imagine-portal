import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../client-care-ui.js',import.meta.url),'utf8');
test('Manutenção separa publicação, plano ativo e cancelamento solicitado sem presumir monitoramento',()=>{const c=vm.createContext({});vm.runInContext(source,c);const run=s=>vm.runInContext(s,c);assert.equal(run("careModel({production:{stage:'published'},maintenance:null}).published"),true);assert.equal(run("careModel({production:{stage:'production'},maintenance:{status:'active'}}).active"),true);assert.equal(run("careModel({maintenance:{status:'active',cancel_requested_at:'2026-10-31'}}).active"),false);assert.equal(run("careModel({maintenance:{status:'active',cancel_requested_at:'2026-10-31'}}).status"),'cancel_requested');assert.equal(run("careModel({}).status"),'none');assert.equal(run("careRequestBody('change','  Trocar a imagem principal  ')"),'[Solicitação de alteração]\nTrocar a imagem principal');});
