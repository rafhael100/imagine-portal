import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source=readFileSync('apps/portal/public/portal-auth/ui.js','utf8');
const flush=async()=>{for(let n=0;n<12;n++)await new Promise(setImmediate);};
function setup(organization='22222222-2222-4222-8222-222222222222'){
  const elements=new Map();
  const element=id=>({id,hidden:['mfa-form','authenticated'].includes(id),value:'',textContent:'',dataset:{},listeners:{},
    focus(){this.focused=true;},reportValidity(){return true;},addEventListener(event,handler){this.listeners[event]=handler;},
    setAttribute(){},removeAttribute(){},querySelectorAll(){return [];}});
  for(const id of ['auth','password-form','mfa-form','authenticated','status','email','password','code','back','logout','success-title'])elements.set('#'+id,element(id));
  elements.get('#auth').dataset.organization=organization;
  const calls=[];const queue=[{status:401}];
  const context={document:{querySelector:id=>elements.get(id)},AbortSignal,fetch:async(path,options)=>{
    calls.push({path,options});
    const response=queue.shift();
    if(response instanceof Error)throw response;
    assert.ok(response,'unexpected request '+path);
    return {status:response.status,ok:response.status>=200&&response.status<300,json:async()=>response.body||{}};
  }};
  vm.runInNewContext(source,context);
  const fire=async(id,event='submit')=>{elements.get('#'+id).listeners[event]({preventDefault(){}});await flush();};
  return {elements,calls,queue,fire};
}
test('unconfigured company never starts an authentication request',async()=>{
  const s=setup('');await flush();assert.equal(s.calls.length,0);
});
test('password, MFA and session validation precede authenticated state; logout clears it',async()=>{
  const s=setup();await flush();
  s.elements.get('#email').value=' operator@example.invalid ';
  s.elements.get('#password').value='synthetic-only';
  s.queue.push({status:200,body:{csrf_token:'synthetic-token'}},{status:202});
  await s.fire('password-form');
  assert.equal(s.elements.get('#mfa-form').hidden,false);
  assert.equal(s.elements.get('#password').value,'');
  const sent=JSON.parse(s.calls.at(-1).options.body);
  assert.equal(sent.email,'operator@example.invalid');assert.equal(sent.organization,s.elements.get('#auth').dataset.organization);
  assert.equal(s.calls.at(-1).options.credentials,'same-origin');
  s.elements.get('#code').value='123456';
  s.queue.push({status:200,body:{csrf_token:'synthetic-token'}},{status:200,body:{authenticated:true}},{status:200,body:{authenticated:true}});
  await s.fire('mfa-form');
  assert.equal(s.elements.get('#authenticated').hidden,false);assert.equal(s.elements.get('#code').value,'');
  s.queue.push({status:200,body:{csrf_token:'synthetic-token'}},{status:204});
  await s.fire('logout','click');
  assert.equal(s.elements.get('#authenticated').hidden,true);assert.equal(s.elements.get('#password-form').hidden,false);
});
test('invalid password uses generic feedback and clears the password',async()=>{
  const s=setup();await flush();s.elements.get('#password').value='synthetic-only';
  s.queue.push({status:200,body:{csrf_token:'synthetic-token'}},{status:401});
  await s.fire('password-form');
  assert.equal(s.elements.get('#password').value,'');assert.equal(s.elements.get('#mfa-form').hidden,true);
  assert.match(s.elements.get('#status').textContent,/dados informados/);
});
test('MFA network failure clears the code and never displays authenticated state',async()=>{
  const s=setup();await flush();s.elements.get('#code').value='123456';
  s.queue.push(new Error('network'));
  await s.fire('mfa-form');
  assert.equal(s.elements.get('#code').value,'');assert.equal(s.elements.get('#authenticated').hidden,true);
});
test('MFA success without a valid current session cannot authorize the view',async()=>{
  const s=setup();await flush();
  s.queue.push({status:200,body:{csrf_token:'synthetic-token'}},{status:200,body:{authenticated:true}},{status:401});
  await s.fire('mfa-form');
  assert.equal(s.elements.get('#authenticated').hidden,true);
});
test('logout failure hides all account actions and does not claim successful logout',async()=>{
  const s=setup();await flush();
  s.queue.push({status:200,body:{csrf_token:'synthetic-token'}},{status:503});
  await s.fire('logout','click');
  assert.equal(s.elements.get('#authenticated').hidden,true);
  assert.equal(s.elements.get('#password-form').hidden,true);
  assert.match(s.elements.get('#status').textContent,/Não foi possível confirmar a saída/);
});
