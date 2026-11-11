import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';

const source=await readFile(fileURLToPath(new URL('../account-ui.js',import.meta.url)),'utf8');
function harness(reply){
 const state={requests:[],links:[],clicks:0,removed:0,revoked:[],renderedLogin:false};
 const viewState={user:{id:'synthetic-owner'}};
 const context={
  state:viewState,
  renderAuth:()=>{state.renderedLogin=true;},
  fetch:async(path,options)=>{state.requests.push({path,options});return reply;},
  document:{
   body:{appendChild(link){state.links.push(link);}},
   createElement(tag){assert.equal(tag,'a');return {
    click(){state.clicks++;},remove(){state.removed++;},
   };},
  },
  URL:{createObjectURL:()=> 'blob:imagine-test',revokeObjectURL:url=>state.revoked.push(url)},
  setTimeout:callback=>callback(),
 };
 runInNewContext(source,context);
 return {state,viewState,download:kind=>runInNewContext('downloadAccountFile('+JSON.stringify(kind)+')',context)};
}

test('F182: successful CSV uses same-origin credentials, expected MIME type and revokes blob URL',async()=>{
 const h=harness({
  ok:true,
  headers:{get:()=> 'text/csv; charset=utf-8'},
  blob:async()=>new Blob(['ID;Projeto']),
 });
 await h.download('csv');
 assert.equal(h.state.requests[0].path,'/api/account/projects.csv?columns=stage');
 assert.equal(h.state.requests[0].options.credentials,'same-origin');
 assert.equal(h.state.requests[0].options.headers.Accept,'text/csv');
 assert.equal(h.state.links[0].download,'imagine-projetos.csv');
 assert.equal(h.state.links[0].href,'blob:imagine-test');
 assert.equal(h.state.clicks,1);
 assert.equal(h.state.removed,1);
 assert.deepEqual(h.state.revoked,['blob:imagine-test']);
});

test('F182: HTTP 429 displays reason without generating an error download',async()=>{
 const h=harness({ok:false,status:429,json:async()=>({error:'Muitas exportações. Aguarde alguns minutos.'})});
 await assert.rejects(h.download('json'),/Muitas exportações/);
 assert.equal(h.state.requests[0].path,'/api/account/export');
 assert.equal(h.state.clicks,0);
 assert.deepEqual(h.state.links,[]);
});

test('F182: expired sessions, unsupported formats and invalid content type fail closed',async()=>{
 const expired=harness({ok:false,status:401,json:async()=>({error:'Entre na sua conta'})});
 await assert.rejects(expired.download('json'),/Sua sessão expirou/);
 assert.equal(expired.state.clicks,0);
 assert.equal(expired.viewState.user,null);
 assert.equal(expired.state.renderedLogin,true);
 await assert.rejects(expired.download('other'),/Formato de exportação inválido/);
 assert.equal(expired.state.requests.length,1);
 const invalid=harness({ok:true,headers:{get:()=> 'text/html'},blob:async()=>new Blob(['html'])});
 await assert.rejects(invalid.download('csv'),/formato inesperado/);
 assert.equal(invalid.state.clicks,0);
});
