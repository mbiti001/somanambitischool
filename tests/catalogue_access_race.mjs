/* Access hydration is tested with synthetic, deferred responses; no network or
 * protected course file is read. */
import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../public/catalogue-shell.js',import.meta.url),'utf8');
const tick=()=>new Promise(r=>setImmediate(r));async function until(fn){for(let i=0;i<30&&!fn();i++)await tick();assert(fn(),'Expected request did not arrive');}
const lesson={id:0,title:'Synthetic private teaching'},privateCourse={lessons:[lesson],sources:{},videos:{},modules:[],glossary:[],faq:[],readings:[]};
function fixture(){const calls=[],listeners=new Map(),c=vm.createContext({window:{},document:{getElementById(){return null;},querySelectorAll(){return [];},addEventListener(name,fn){listeners.set(name,fn);}},location:{hash:'#home'},AbortController,setTimeout,clearTimeout,console,fetch:async(path,options)=>{let resolve;const json=new Promise(r=>resolve=r),row={path,options,resolve};calls.push(row);return {ok:true,status:200,json:()=>json};}});vm.runInContext(source,c);return {c,api:c.window.CourseAccess,calls,listeners};}
{
 const {c,api,calls}=fixture(),stable=vm.runInContext('learningCourse.lessons',c),alice={email:'alice@example.invalid',verified:true};const old=api.refresh(alice);await until(()=>calls.length===1);calls[0].resolve({verified:true,access:{investing:true}});await until(()=>calls.length===2);
 api.clear();const newer=api.refresh({email:'bob@example.invalid',verified:true});await until(()=>calls.length===3);calls[2].resolve({verified:true,access:{investing:false,college:true}});await newer;calls[1].resolve({course:privateCourse});await old;
 assert.equal(calls[1].options.signal.aborted,true);assert.equal(stable.length,0);assert.equal(vm.runInContext('learningCourse.lessons',c),stable);assert(!api.investingReady());assert.equal(api.allowed('college'),true);
}
{
 const {c,api,calls,listeners}=fixture(),u={email:'enrolled@example.invalid',verified:true};let p=api.refresh(u);await until(()=>calls.length===1);calls[0].resolve({verified:true,access:{investing:false}});await p;
 let prevented=false,stopped=false;listeners.get('submit')({target:{closest:()=>({dataset:{page:'tools'}})},preventDefault(){prevented=true;},stopImmediatePropagation(){stopped=true;}});assert(prevented&&stopped,'Unenrolled stale practice forms must be blocked');
 p=api.refresh(u,true);await until(()=>calls.length===2);calls[1].resolve({verified:true,access:{investing:true}});await until(()=>calls.length===3);calls[2].resolve({course:privateCourse});await p;assert(api.investingReady());assert.equal(vm.runInContext('learningCourse.lessons.length',c),1);
 p=api.refresh(u,true);await until(()=>calls.length===4);calls[3].resolve({verified:true,access:{investing:false}});await p;assert(!api.investingReady());assert.equal(vm.runInContext('learningCourse.lessons.length',c),0);
 p=api.refresh({email:'unverified@example.invalid',verified:false},true);await until(()=>calls.length===5);calls[4].resolve({verified:false,access:{investing:true},teacherCleared:true});await p;assert(!api.investingReady());assert(!api.learningAllowed());assert.equal(calls.length,5,'An unverified reply must not load teaching data');api.clear();
}
console.log('PASS: stable empty catalogue references, stale-account hydration rejection, cancellation, enrollment refresh, revocation clearing, unverified denial and stale-form guards.');
