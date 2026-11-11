import test from 'node:test';
import assert from 'node:assert/strict';
import {openStore} from '../server/store.mjs';

test('F203: SQLite Portuguese fold handles uppercase accents and literal wildcard characters',()=>{
 const db=openStore(':memory:');
 for(const [input,expected] of [
  ['CAFÉ SÃO JOÃO','cafe sao joao'],
  ['ÀGUA, ÊXITO, ÇEDILHA','agua, exito, cedilha'],
  ['%_ TESTE','%_ teste'],
  [null,''],
 ])assert.equal(db.prepare('SELECT imagine_fold(?) AS value').get(input).value,expected);
 assert.equal(db.prepare("SELECT instr(imagine_fold('Café São João'),imagine_fold(?)) AS found").get('CAFE SAO').found,1);
 db.close();
});
