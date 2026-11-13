/* Public bootstrap and protected hydration checks use synthetic responses only. */
import {JSDOM} from './auth-ui/node_modules/jsdom/lib/api.js';
import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const html=fs.readFileSync(new URL('../public/index.html',import.meta.url),'utf8');
const modules=['Start here','Trade confidently','Build your judgement','Trading with discipline'];
const fixtureCourse={checked:'test',modules,sources:Object.fromEntries(['cma','cdsc','licences','nse','complaints','products'].map(k=>[k,{title:'Fixture source '+k,url:'https://example.invalid/'+k,context:'Test source',assignment:'Synthetic reference'}])),videos:{market:{id:'abcdefghijk',title:'Fixture video',publisher:'Fixture',context:'Synthetic media',end:120}},lessons:modules.map((module,id)=>({id,module,title:'Private fixture chapter '+id,time:'5 min',readingMinutes:5,summary:'Authorized fixture',outcomes:['Explain a fixture'],video:'market',sections:[{heading:'Private fixture teaching',text:'Protected fixture teaching text',sources:['cma']}],units:['understand','apply','review'].map(unit=>({id:unit,title:'Fixture '+unit,sections:[0],questions:[{id:unit+'q',question:'Fixture question',answers:['A','B'],correct:0,explanation:'Fixture explanation',sources:['cma']}]}))})),readings:[],glossary:[],faq:[]};
const fresh=()=>({cash:100000,holdings:{},history:[],completed:[],journal:[],unitPasses:{},modulePasses:[]});
const wait=()=>new Promise(r=>setImmediate(r));
async function settle(){for(let i=0;i<35;i++)await wait();}
async function boot({verified=false,investing=false,hash='#home'}={}){
 const dom=new JSDOM(html,{url:'https://example.test/'+hash,runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window,c=dom.getInternalVMContext(),requests=[];let enrolled=false,signed=verified;
 w.scrollTo=()=>{};w.HTMLElement.prototype.scrollIntoView=()=>{};w.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){}});w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};w.structuredClone=structuredClone;
 w.localStorage.setItem('hisa-classroom-v1',JSON.stringify({...fresh(),cash:777}));
 w.fetch=async(path,options={})=>{const body=options.body?JSON.parse(options.body):null;requests.push({path:String(path),body});let data={},status=200;
  if(String(path)==='api.php'){if(body?.action==='logout')signed=false;data={csrf:'synthetic-csrf',user:signed?{email:'learner@example.invalid',name:'Fixture Learner',verified:true}:null,account:signed?{state:fresh(),version:1,updatedAt:'2026-11-14T00:00:00Z'}:null};}
  else if(String(path)==='materials.php?view=access')data={csrf:'synthetic-csrf',signedIn:signed,verified:signed,access:{investing:signed&&investing,speaking:false,college:signed&&enrolled},teacherCleared:false};
  else if(String(path)==='materials.php?course=investing'){assert(signed&&investing,'Unenrolled visitor requested private catalogue');data={course:fixtureCourse};}
  else if(String(path).startsWith('academic.php')){if(!signed){status=401;data={csrf:'synthetic-csrf',error:'Sign in with a verified account.'};}else if(body?.action==='enroll_foundation'){assert.equal(body.consent,true);enrolled=true;data={csrf:'synthetic-csrf',notice:'Foundation enrolled.'};}else if(!enrolled){status=403;data={csrf:'synthetic-csrf',foundationEnrollmentRequired:true,error:'Foundation enrollment required.'};}else data={csrf:'synthetic-csrf',signedIn:true,installed:true,superAdmin:false,course:{title:'College Success fixture',modules:[],sources:{}},passes:[]};}
  else if(String(path).startsWith('terms.php'))data={csrf:'synthetic-csrf',signedIn:signed,installed:true,superAdmin:false,courses:[],enrollments:[],collegeComplete:false};
  else if(String(path).startsWith('students.php')){status=401;data={error:'Synthetic student fixture unavailable.'};}
  else if(String(path).startsWith('tutor.php'))data={enabled:false,eligible:false,csrf:'synthetic-csrf'};
  return {ok:status<400,status,json:async()=>data};};
 const scripts=[...html.matchAll(/<script src="([^"?]+)/g)].map(m=>m[1]).map(name=>name==='course.js'?'catalogue-shell.js':name);
 if(!scripts.includes('catalogue-shell.js'))scripts.unshift('catalogue-shell.js');
 for(const script of scripts)vm.runInContext(fs.readFileSync(new URL('../public/'+script,import.meta.url),'utf8'),c,{filename:script});await settle();
 return {dom,w,c,requests};
}
{
 const h=await boot({hash:'#market'});assert.equal(h.w.document.body.dataset.view,'access');assert.equal(vm.runInContext('lessons.length',h.c),0);assert(!h.requests.some(r=>r.path==='materials.php?course=investing'));assert(h.w.document.querySelector('#account-controls').textContent.includes('Sign in'));assert(!h.w.document.querySelector('#account-controls').textContent.includes('Import browser'));
 await assert.rejects(vm.runInContext("saveAction({action:'trade',symbol:'SCOM',q:1,side:'buy'})",h.c),/verify|verified|enroll/i);assert.equal(vm.runInContext('state.cash',h.c),100000);assert.equal(JSON.parse(h.w.localStorage.getItem('hisa-classroom-v1')).cash,777);h.dom.window.close();
}
{
 const h=await boot({verified:true,hash:'#reading'});assert.equal(h.w.document.body.dataset.view,'access');assert(!h.requests.some(r=>r.path==='materials.php?course=investing'));h.w.location.hash='#college';await settle();const f=h.w.document.querySelector('[data-foundation-enroll]');assert(f,'Verified non-enrolled learner needs an explicit foundation enrollment form');f.elements.consent.checked=true;f.dispatchEvent(new h.w.Event('submit',{bubbles:true,cancelable:true}));await settle();assert(h.requests.some(r=>r.path==='academic.php'&&r.body?.action==='enroll_foundation'&&r.body.consent===true));assert(h.w.document.querySelector('#academic-college').textContent.includes('College Success fixture'));h.dom.window.close();
}
{
 const h=await boot({verified:true,investing:true,hash:'#tools'});assert(h.w.CourseAccess.investingReady());assert.equal(vm.runInContext('lessons.length',h.c),4);assert.equal(h.w.document.body.dataset.view,'tools');assert(h.w.document.getElementById('fee-result').textContent.includes('Purchase outlay'));vm.runInContext("assessmentAttempt={private:'old attempt'};assessmentOverview={private:'old overview'};assessmentDrafts.set('old','choice');guideDrafts.set('old','choice');",h.c);h.w.document.getElementById('modalbody').textContent='Private old journal draft';h.w.document.getElementById('modal').showModal();await vm.runInContext("saveAction({action:'reset'})",h.c);assert.equal(h.requests.find(r=>r.body?.action==='reset').body.expectedEmail,'learner@example.invalid');await vm.runInContext('signOut()',h.c);await settle();assert.equal(h.w.location.hash,'#home');assert.equal(vm.runInContext('lessons.length',h.c),0);assert.equal(h.w.document.getElementById('resource-links').textContent,'');assert.equal(h.w.document.getElementById('fee-result').textContent,'');assert(!h.w.CourseAccess.investingReady());assert.equal(vm.runInContext('assessmentAttempt',h.c),null);assert.equal(vm.runInContext('assessmentOverview',h.c),null);assert.equal(vm.runInContext('assessmentDrafts.size+guideDrafts.size',h.c),0);assert.equal(h.w.document.getElementById('modalbody').textContent,'');assert.equal(h.w.document.getElementById('modal').open,false);h.dom.window.close();
}
{
 const h=await boot({verified:true,investing:true,hash:'#journal'}),original=h.w.fetch;let release,posted;
 h.w.fetch=async(path,options={})=>{const body=options.body?JSON.parse(options.body):null;if(path==='api.php'&&body?.action==='reset'){posted=body;return {ok:true,status:200,json:()=>new Promise(r=>release=r)};}return original(path,options);};
 const pending=vm.runInContext("saveAction({action:'reset'})",h.c);for(let i=0;i<30&&!release;i++)await wait();assert(release);assert.equal(posted.expectedEmail,'learner@example.invalid');
 vm.runInContext("acceptAccount({user:{email:'second@example.invalid',name:'Second learner',verified:true},account:{state:{cash:500,holdings:{},history:[],completed:[],journal:[],unitPasses:{},modulePasses:[]},version:7,updatedAt:'2026-11-14T00:00:00Z'}})",h.c);await settle();release({account:{state:{...fresh(),cash:123},version:2,updatedAt:'old'}});await pending;assert.equal(vm.runInContext('state.cash',h.c),500,'An old account acknowledgement must not overwrite the new account');h.dom.window.close();
}
console.log('PASS: public landing/login boot with empty materials; guest and unenrolled practice blocked; only authorized catalogue hydration; explicit foundation enrollment; logout clears teaching and returns home.');
