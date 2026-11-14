/* Regression checks use deferred in-memory responses only: no browser, account,
 * filesystem configuration or database is read or changed. The implementation is
 * instrumented in memory solely to drive its real form binding and save functions. */
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const sourcePath=new URL('../public/terms.js',import.meta.url);
function harness({remoteAfterFirst=false}={}){
 const requests=[];
 const status={textContent:''};
 function form(){const fields=Object.fromEntries(['title','introduction','body','conclusion','references','assistance'].map(k=>[k,{value:k==='body'?'First draft':''}]));const listeners={},words={textContent:''},save={};return {dataset:{termWriting:'opening',key:'alice:enrollment:0:opening'},elements:fields,listeners,querySelector(s){return s==='[data-words]'?words:save;},querySelectorAll(){return [];},addEventListener(name,fn){listeners[name]=fn;}};}
 function root(f=null){const buttons=new Map();return {html:'',querySelectorAll(s){return s==='[data-term-writing]'&&f?[f]:[];},querySelector(s){if(s==='[data-term-status]')return status;if(s==='[data-term-conflict]')return null;if(!buttons.has(s))buttons.set(s,{});return buttons.get(s);},insertAdjacentHTML(_where,html){this.html+=html;},replaceChildren(){this.html='';}};}
 const f=form(),a=root(f),b=root();const context={window:{addEventListener(){}},document:{querySelectorAll(){return [];}},location:{hash:'#class/11111111/week/0/opening'},AcademicCourse:{},AbortController,setTimeout,clearTimeout,crypto:{randomUUID:()=> 'test'},console};
 context.fetch=async(url,options)=>{
  const index=requests.length;let resolve;const json=new Promise(r=>resolve=r);const req={url,options,body:options.body?JSON.parse(options.body):null,resolve,ok:true,status:200};requests.push(req);
  if(index>0&&remoteAfterFirst)resolve({csrf:'bob-token',papers:{opening:{version:2,document:{body:'Bob’s own private paper'}}}});
  return {get ok(){return req.ok;},get status(){return req.status;},json:()=>json};
 };
 const hook=`window.__review={setState(who,el){identity=who;root=el;},bindWriting};`;
 const marker="window.addEventListener('beforeunload'";
 assert(sourceText().includes(marker),'The review harness marker needs updating.');
 vm.runInNewContext(sourceText().replace(marker,hook+marker),context,{filename:'terms.js'});
 const api=context.window.StandardCourses,hooks=context.window.__review;
 hooks.setState('alice',a);hooks.bindWriting({papers:null,enrollment:{id:'enrollment'},offering:{id:'11111111'},week:0});f.listeners.input();
 return {api,hooks,requests,f,a,b,status};
}
function sourceText(){return fs.readFileSync(sourcePath,'utf8');}
const tick=()=>new Promise(r=>setImmediate(r));
async function until(fn,message){for(let i=0;i<30&&!fn();i++)await tick();assert(fn(),message);}
async function lateTyping(){
 const h=harness(),pending=h.api.flush();await until(()=>h.requests.length===1,'The first save did not start.');
 h.f.elements.body.value='A later edit typed while the first save is pending';h.f.listeners.input();h.requests[0].resolve({record:{version:1},csrf:'alice-token'});
 await until(()=>h.requests.length===2,'Navigation flush must save again when typing continues during the first save.');
 assert.equal(h.requests[1].body.document.body,h.f.elements.body.value,'The second save must contain the latest text.');
 h.requests[1].resolve({record:{version:2},csrf:'alice-token'});assert.equal(await pending,true);h.api.clear();
}
async function staleConflict(){
 const h=harness({remoteAfterFirst:true});
 // Delay response JSON rather than response headers, so a result can arrive even
 // after cancellation. Generation checks must protect the response callbacks.
 const pending=h.api.flush();await until(()=>h.requests.length===1,'The save did not start.');
 h.api.clear();h.hooks.setState('bob',h.b);
 h.requests[0].ok=false;h.requests[0].status=409;h.requests[0].resolve({error:'The paper changed on another device',conflict:true});
 await pending;await tick();
 assert.equal(h.requests.length,1,'An old account’s conflict must not start a fresh request under the new account.');
 assert.equal(h.b.html,'','An old account’s conflict UI must not appear in the new account.');
 assert.equal(h.requests[0].options.signal.aborted,true,'Clearing the account must cancel active course requests.');h.api.clear();
}
let failures=0;for(const [name,test] of [['late typing during navigation',lateTyping],['stale-account conflict response',staleConflict]]){try{await test();console.log('PASS:',name);}catch(error){failures++;console.error('FAIL:',name,'—',error.message);}}
if(failures)process.exitCode=1;
