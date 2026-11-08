/** Browser QA on file:// via built-in Node 22 WebSocket + Chrome DevTools, no npm packages. */
import {spawn, spawnSync} from "node:child_process";
import {mkdtemp, mkdir, readFile, rm, writeFile} from "node:fs/promises";
import {tmpdir} from "node:os";
import path from "node:path";
import {pathToFileURL} from "node:url";

const routes = ["overview","services","catalog","requests","contracts","performance","university","notices"];
const sleep = (n) => new Promise((resolve) => setTimeout(resolve,n));
function check(ok,msg) { if(!ok) throw Error(msg); }

async function test() {
  const pageFile=path.resolve("apps/portal/preview/pview-01.html");
  check((await readFile(pageFile,"utf8")).includes("DEMONSTRAÇÃO OFFLINE"),"Missing demo notice");
  const bin=spawnSync("bash",["-lc","command -v google-chrome || command -v chromium || command -v chromium-browser"],{encoding:"utf8"});
  check(bin.status===0 && bin.stdout.trim(),"Chrome binary not available on runner");
  const userDir=await mkdtemp(path.join(tmpdir(),"pview-cdp-"));
  const evidence=path.resolve(process.env.PVIEW_EVIDENCE_DIR || "apps/portal/preview/evidence");
  await mkdir(evidence,{recursive:true});
  const chrome=spawn(bin.stdout.trim().split("\n")[0],[
    "--headless=new","--no-sandbox","--disable-gpu","--disable-dev-shm-usage",
    "--disable-background-networking","--disable-extensions","--no-first-run",
    "--no-default-browser-check","--remote-allow-origins=*","--remote-debugging-port=0",
    "--user-data-dir="+userDir,"about:blank"
  ],{stdio:["ignore","ignore","pipe"]});
  let stderr="";
  chrome.stderr.on("data",(chunk)=>{stderr=(stderr+chunk.toString()).slice(-1000);});
  let ws;
  try {
    let port=0;
    for(let i=0;i<120;i++){
      check(chrome.exitCode===null,"Chrome exited: "+stderr);
      try { port=Number((await readFile(path.join(userDir,"DevToolsActivePort"),"utf8")).split("\n")[0]); } catch {}
      if(port>0) break;
      await sleep(100);
    }
    check(port>0,"Chrome CDP startup timeout: "+stderr);
    const targets=await (await fetch("http://127.0.0.1:"+port+"/json/list")).json();
    const target=targets.find(x=>x.type==="page");
    check(target?.webSocketDebuggerUrl,"No Chrome page target");
    ws=new WebSocket(target.webSocketDebuggerUrl);
    await Promise.race([
      new Promise((resolve,reject)=>{ws.addEventListener("open",resolve,{once:true});ws.addEventListener("error",reject,{once:true});}),
      sleep(10000).then(()=>{throw Error("Chrome websocket timeout");})
    ]);
    let id=0;
    const pending=new Map();
    const network=[];
    ws.addEventListener("message",(event)=>{
      const m=JSON.parse(event.data);
      if(m.method==="Network.requestWillBeSent" && /^https?:\/\//i.test(m.params?.request?.url||"")) network.push(m.params.request.url);
      if(!m.id || !pending.has(m.id))return;
      const p=pending.get(m.id);pending.delete(m.id);clearTimeout(p.timer);
      m.error?p.reject(Error(JSON.stringify(m.error))):p.resolve(m.result);
    });
    const cdp=(method,params={})=>new Promise((resolve,reject)=>{
      const rid=++id;
      const timer=setTimeout(()=>{pending.delete(rid);reject(Error("CDP timeout: "+method));},15000);
      pending.set(rid,{resolve,reject,timer});
      ws.send(JSON.stringify({id:rid,method,params}));
    });
    const evalJs=async(expression)=>{
      const v=await cdp("Runtime.evaluate",{expression,returnByValue:true,awaitPromise:true});
      check(!v.exceptionDetails,"Page JS exception: "+expression.slice(0,100));
      return v.result.value;
    };
    await cdp("Page.enable");await cdp("Runtime.enable");await cdp("Network.enable");
    await cdp("Page.navigate",{url:pathToFileURL(pageFile).href});
    for(let i=0;i<60 && (await evalJs("document.readyState"))!=="complete";i++)await sleep(100);
    check((await evalJs("document.readyState"))==="complete","Document failed to load");
    let total=0;
    for(const v of [{name:"mobile",w:390,h:844},{name:"tablet",w:768,h:1024},{name:"desktop",w:1440,h:900}]){
      await cdp("Emulation.setDeviceMetricsOverride",{width:v.w,height:v.h,deviceScaleFactor:1,mobile:false});
      await sleep(100);
      for(const route of routes){
        await evalJs("location.hash="+JSON.stringify(route));
        await sleep(55);
        const state=JSON.parse(await evalJs("JSON.stringify({width:innerWidth,scroll:document.documentElement.scrollWidth,visible:[...document.querySelectorAll('[data-screen]')].filter(e=>!e.hidden).map(e=>e.dataset.screen),selected:[...document.querySelectorAll('[data-nav]')].filter(e=>e.getAttribute('aria-current')==='page').map(e=>e.dataset.nav),warning:document.body.innerText.includes('DEMONSTRAÇÃO OFFLINE')})"));
        check(state.visible.length===1 && state.visible[0]===route,"Wrong visible screen "+v.name+"/"+route+" "+JSON.stringify(state));
        check(state.selected.length===1 && state.selected[0]===route,"Wrong aria-current "+v.name+"/"+route);
        check(state.scroll<=state.width+1,"Horizontal body overflow "+v.name+"/"+route+": "+state.scroll+" > "+state.width);
        check(state.warning,"Missing demo notice "+v.name+"/"+route);
        total++;
      }
      await evalJs("location.hash='overview'");await sleep(100);
      const png=await cdp("Page.captureScreenshot",{format:"png",captureBeyondViewport:false});
      await writeFile(path.join(evidence,"pview-01-"+v.name+".png"),Buffer.from(png.data,"base64"));
      console.log("[PASS PVIEW] "+v.name+" / eight screens");
    }
    await evalJs("document.querySelector('[data-nav=\"catalog\"]').focus()");
    await cdp("Input.dispatchKeyEvent",{type:"keyDown",key:"Enter",code:"Enter",windowsVirtualKeyCode:13,nativeVirtualKeyCode:13});
    await cdp("Input.dispatchKeyEvent",{type:"keyUp",key:"Enter",code:"Enter",windowsVirtualKeyCode:13,nativeVirtualKeyCode:13});
    await sleep(150);
    check((await evalJs("location.hash"))==="#catalog","Enter keyboard navigation failed");
    console.log("[PASS PVIEW] keyboard Enter opens catalog");
    check(network.length===0,"Unexpected external HTTP requests: "+network.join(","));
    console.log("[PASS PVIEW] no external HTTP requests; "+total+" viewport/route checks; 3 screenshots");
  } finally {
    ws?.close();chrome.kill("SIGTERM");await sleep(150);
    await rm(userDir,{recursive:true,force:true});
  }
}
test().catch(e=>{console.error("[FAIL PVIEW] "+String(e));process.exitCode=1;});
