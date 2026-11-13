/* Fast-clock browser-session regression checks; no live hosting or microphone. */
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
const require=createRequire(new URL('./auth-ui/package.json',import.meta.url));
const {JSDOM}=require('jsdom');
const source=fs.readFileSync(new URL('../public/session-runtime.js',import.meta.url),'utf8');
const BASE=1800000000000;
const drain=async()=>{for(let i=0;i<18;i++)await Promise.resolve();};
class Clock{
 constructor(){this.now=BASE;this.tasks=new Map();this.next=1;}
 set(fn,delay=0,interval=0){const id=this.next++;this.tasks.set(id,{fn,at:this.now+Math.max(0,Number(delay)||0),interval});return id;}
 clear(id){this.tasks.delete(id);}
 async advance(ms){const target=this.now+ms;for(;;){let chosen=null;for(const [id,t] of this.tasks)if(t.at<=target&&(!chosen||t.at<chosen[1].at))chosen=[id,t];if(!chosen)break;const [id,t]=chosen;this.now=t.at;if(t.interval)t.at+=t.interval;else this.tasks.delete(id);t.fn();await drain();}this.now=target;await drain();}
}
class Hub{
 constructor(){this.clients=[];this.messages=[];}
 constructorFor(){const hub=this;return class{
  constructor(name){this.name=name;this.handlers=[];hub.clients.push(this);}
  addEventListener(type,fn){if(type==='message')this.handlers.push(fn);}
  postMessage(data){hub.messages.push(structuredClone(data));for(const peer of hub.clients)if(peer!==this&&peer.name===this.name)queueMicrotask(()=>peer.handlers.forEach(fn=>fn({data:structuredClone(data)})));}
 };}
}
class Server{
 constructor(clock,id='session-A',absolute=28800){this.clock=clock;this.id=id;this.last=clock.now/1000;this.absolute=this.last+absolute;this.csrf='csrf-for-'+id;this.loggedIn=true;this.offline=false;this.defer=null;this.requests=[];}
 payload(){const now=this.clock.now/1000,expires=Math.min(this.last+3600,this.absolute);return this.loggedIn&&now<expires?{csrf:this.csrf,authenticated:true,verified:true,session:{id:this.id,serverNow:now,idleSeconds:3600,idleDeadline:this.last+3600,absoluteDeadline:this.absolute,expiresAt:expires}}:{csrf:this.csrf,authenticated:false,verified:false,session:null};}
 fetch(url,opts={}){
  const method=opts.method||'GET';this.requests.push({url,method,headers:opts.headers,body:opts.body});
  if(this.offline)return Promise.reject(Error('offline'));
  if(this.defer&&(!this.defer.method||this.defer.method===method)){const deferred=this.defer;this.defer=null;return new Promise(resolve=>{deferred.resolve=(data,status=200)=>resolve({status,ok:status>=200&&status<300,json:async()=>data});});}
  let data=this.payload(),status=data.authenticated?200:401;
  if(method==='POST'&&status===200){assert.equal(opts.headers['X-CSRF-Token'],this.csrf);assert.deepEqual(JSON.parse(opts.body),{action:'activity'});this.last=this.clock.now/1000;data=this.payload();}
  return Promise.resolve({status,ok:status===200,json:async()=>data});
 }
 posts(){return this.requests.filter(r=>r.method==='POST');}
}
function harness({clock=new Clock(),server=null,hub=null,path='/',channel=true}={}){
 server??=new Server(clock);const dom=new JSDOM('<!doctype html><html><head></head><body><main><section data-page="home"></section><section data-page="class" data-active="true"><div id="term-class">PRIVATE PAPER</div><div id="assessment-workspace">PRIVATE ANSWERS</div></section></main><div id="speaking-account">PRIVATE EMAIL</div><dialog id="modal" open><div id="modalbody">PRIVATE PASSWORD</div></dialog></body></html>');
 const real=dom.window,document=real.document;let visibility='visible';Object.defineProperty(document,'readyState',{get:()=> 'complete'});Object.defineProperty(document,'visibilityState',{get:()=>visibility});
 const listeners=new WeakMap(),native=real.EventTarget.prototype.addEventListener;
 real.EventTarget.prototype.addEventListener=function(type,fn,options){let map=listeners.get(this);if(!map){map=new Map();listeners.set(this,map);}const fns=map.get(type)||[];fns.push(fn);map.set(type,fns);return native.call(this,type,fn,options);};
 const ended=[],calls=[],redirects=[],storage=[];const location={pathname:path,hash:'#class/example',replace(value){redirects.push(value);this.hash='#home';}};
 const context={document,location,Date:class extends Date{constructor(...args){super(...(args.length?args:[clock.now]));}static now(){return clock.now;}},performance:{now:()=>clock.now-BASE},AbortController,CustomEvent:real.CustomEvent,fetch:(...args)=>server.fetch(...args),setTimeout:(fn,d)=>clock.set(fn,d),clearTimeout:id=>clock.clear(id),setInterval:(fn,d)=>clock.set(fn,d,d),clearInterval:id=>clock.clear(id),console,Promise,localStorage:{setItem(k,v){storage.push({key:k,newValue:v});},removeItem(){}}};
 context.window=context;context.addEventListener=real.addEventListener.bind(real);context.dispatchEvent=real.dispatchEvent.bind(real);if(channel)context.BroadcastChannel=(hub||new Hub()).constructorFor();
 for(const name of ['CourseAccess','StandardCourses','AcademicCourse','TeacherDesk','CampusCommunity'])context[name]={clear(){calls.push(name+'.clear');}};
 for(const name of ['StandardCourses','AcademicCourse','SpeakingAccount']){context[name]??={};context[name].flush=()=>{calls.push(name+'.flush');return Promise.resolve(true);};}
 real.addEventListener('soma-session-ended',event=>ended.push(event.detail.reason));
 vm.createContext(context);vm.runInContext(source,context,{filename:'session-runtime.js'});
 const emit=(target,type,extra={})=>{for(const fn of listeners.get(target)?.get(type)||[])fn({type,isTrusted:true,...extra});};
 return {clock,server,dom,document,context,runtime:context.SessionRuntime,ended,calls,redirects,storage,emit,window:real,setVisibility(value){visibility=value;emit(document,'visibilitychange');}};
}

// Trusted visible interaction renews at most once per minute; synthetic input,
// background events, focus and ordinary GET checks do not extend the lease.
{
 const h=harness();await drain();assert.equal(h.runtime.snapshot().idleSeconds,3600);const first=h.runtime.snapshot().expiresAt;
 h.emit(h.document,'keydown',{isTrusted:false});await h.clock.advance(0);assert.equal(h.server.posts().length,0);
 h.setVisibility('hidden');h.emit(h.document,'pointerdown');await h.clock.advance(1000);assert.equal(h.server.posts().length,0);
 h.setVisibility('visible');await drain();assert.equal(h.runtime.snapshot().expiresAt,first);
 h.emit(h.window,'focus');await drain();assert.equal(h.server.posts().length,0);
 h.emit(h.document,'keydown');await h.clock.advance(0);assert.equal(h.server.posts().length,1);assert.equal(h.runtime.snapshot().expiresAt,first+1);
 await h.clock.advance(10000);h.emit(h.document,'wheel');await h.clock.advance(49999);assert.equal(h.server.posts().length,1);await h.clock.advance(1);assert.equal(h.server.posts().length,2);
 const deadline=h.runtime.snapshot().expiresAt;await h.clock.advance(5000);await h.runtime.check();assert.equal(h.runtime.snapshot().expiresAt,deadline);assert.equal(h.server.posts().length,2);
 const calls=h.server.requests.length;h.emit(h.window,'focus');await drain();assert.equal(h.server.requests.length,calls,'Focus must not keep the session alive');
}

// Warning attempts to save pending work without adding activity. Its explicit,
// trusted action renews an idle lease and then removes the warning.
{
 const h=harness();await drain();await h.clock.advance(3480000);const warning=h.document.getElementById('soma-session-warning');assert(warning);assert.equal(warning.getAttribute('role'),'alert');assert.equal(warning.querySelector('[data-session-countdown]').textContent,'02:00');
 assert.equal(h.server.posts().length,0);for(const name of ['StandardCourses','AcademicCourse','SpeakingAccount'])assert.equal(h.calls.filter(x=>x===name+'.flush').length,1);
 const button=warning.querySelector('button');h.emit(button,'click',{isTrusted:false});await drain();assert.equal(h.server.posts().length,0);
 h.emit(button,'click');await drain();assert.equal(h.server.posts().length,1);assert.equal(h.document.getElementById('soma-session-warning'),null);assert.equal(h.runtime.snapshot().expiresAt,h.clock.now/1000+3600);
}

// Expiry clears protected views even offline and cannot be reversed by a stale
// account payload. No one-hour real-world sleep is used.
{
 const h=harness();await drain();const stale=h.server.payload().session;h.server.offline=true;await h.clock.advance(3600000);assert.equal(h.runtime.snapshot(),null);assert.equal(h.location?.hash??h.context.location.hash,'#home');assert.equal(h.document.getElementById('term-class').textContent,'');assert.equal(h.document.getElementById('assessment-workspace').textContent,'');assert.equal(h.document.getElementById('speaking-account').textContent,'');assert.equal(h.document.getElementById('modal').hasAttribute('open'),false);assert.deepEqual(h.ended,['expired']);assert.equal(h.runtime.adopt(stale),false);assert.equal(h.runtime.snapshot(),null);assert.equal(h.server.posts().length,0);
}

// The absolute deadline remains binding despite genuine activity near its end.
{
 const clock=new Clock(),server=new Server(clock,'absolute-session',3600),h=harness({clock,server});await drain();await clock.advance(3480000);const warning=h.document.getElementById('soma-session-warning');assert(warning.querySelector('button').hidden);h.emit(h.document,'keydown');await clock.advance(0);assert.equal(h.runtime.snapshot().expiresAt,BASE/1000+3600);await clock.advance(120000);assert.equal(h.runtime.snapshot(),null);assert.equal(server.posts().length,1);
}

// Cross-tab messages can update only an already authenticated matching runtime
// ID, never a different browser/device/account or an extended absolute limit.
{
 const clock=new Clock(),hub=new Hub(),server=new Server(clock,'shared-session'),a=harness({clock,hub,server}),b=harness({clock,hub,server}),other=harness({clock,hub,server:new Server(clock,'other-device')});await drain();const otherDeadline=other.runtime.snapshot().expiresAt;await clock.advance(1000);a.emit(a.document,'pointerdown');await clock.advance(0);await drain();assert.equal(a.runtime.snapshot().expiresAt,b.runtime.snapshot().expiresAt);assert.equal(other.runtime.snapshot().expiresAt,otherDeadline);assert.equal(server.posts().length,1);
 const unchanged=b.runtime.snapshot().expiresAt;const invalid={...a.runtime.snapshot(),absoluteDeadline:a.runtime.snapshot().absoluteDeadline+3600,expiresAt:unchanged+3600};const attacker=new (hub.constructorFor())('soma-session-runtime-v1');attacker.postMessage({version:1,type:'renewed',id:'shared-session',session:invalid});await drain();assert.equal(b.runtime.snapshot().expiresAt,unchanged);
 other.runtime.end('logout');await drain();assert(a.runtime.snapshot());assert(b.runtime.snapshot());a.runtime.end('logout');await drain();assert.equal(b.runtime.snapshot(),null);assert.equal(other.runtime.snapshot(),null);assert(!JSON.stringify(hub.messages).includes('csrf'));assert(!JSON.stringify(hub.messages).includes('PRIVATE'));
}

// Explicit logout wins over delayed passive and activity responses, including
// responses arriving after a different authenticated runtime is adopted.
{
 const h=harness();await drain();const deferred={method:'GET'};h.server.defer=deferred;const check=h.runtime.check();await drain();const late=h.server.payload();h.runtime.end('logout');deferred.resolve(late);await check;assert.equal(h.runtime.snapshot(),null);assert.deepEqual(h.ended,['logout']);
}
{
 const h=harness();await drain();const deferred={method:'POST'};h.server.defer=deferred;h.emit(h.document,'keydown');await h.clock.advance(0);const old=h.server.payload();h.runtime.end('logout');h.server.id='fresh-login';h.server.csrf='fresh-csrf';h.server.last=h.clock.now/1000;assert.equal(h.runtime.adopt(h.server.payload()),true);await drain();deferred.resolve(old);await drain();assert.equal(h.runtime.snapshot().id,'fresh-login');assert.equal(h.runtime.adopt(old),false);assert.equal(h.runtime.snapshot().id,'fresh-login');
}

// Visibility and back-cache rechecks are passive and respect server revocation.
{
 const h=harness();await drain();await h.clock.advance(1000);h.setVisibility('hidden');h.emit(h.document,'touchstart');await h.clock.advance(1000);assert.equal(h.server.posts().length,0);h.server.loggedIn=false;h.emit(h.window,'pageshow',{persisted:true});await drain();assert.equal(h.runtime.snapshot(),null);assert.equal(h.server.posts().length,0);
}

// Studio uses the shared session endpoint and returns to the main home page.
{
 const h=harness({path:'/speaking/index.php'});await drain();assert.equal(h.server.requests[0].url,'../session.php');h.runtime.adopt(null);assert.deepEqual(h.redirects,['../#home']);assert.equal(h.runtime.snapshot(),null);
}

// Storage fallback accepts only matching-session nonsecret messages.
{
 const h=harness({channel:false});await drain();const original=h.runtime.snapshot(),renewed={...original,serverNow:original.serverNow+1,idleDeadline:original.idleDeadline+1,expiresAt:original.expiresAt+1};h.emit(h.window,'storage',{key:'soma-session-runtime-v1',newValue:JSON.stringify({version:1,type:'renewed',id:'different-session',session:renewed})});assert.equal(h.runtime.snapshot().expiresAt,original.expiresAt);h.emit(h.window,'storage',{key:'soma-session-runtime-v1',newValue:JSON.stringify({version:1,type:'renewed',id:original.id,session:renewed})});assert.equal(h.runtime.snapshot().expiresAt,original.expiresAt+1);h.runtime.end('logout');assert.equal(h.storage.length,1);const stored=JSON.parse(h.storage[0].newValue);assert.deepEqual(stored,{version:1,type:'ended',id:original.id});
}
// An initial signed-out public outline remains visible; it is not a private cache.
{
 const clock=new Clock(),server=new Server(clock);server.loggedIn=false;const h=harness({clock,server});h.context.location.hash='#program/philosophy';const node=h.document.createElement('section');node.id='term-program';node.textContent='Public outline';h.document.querySelector('main').append(node);await drain();assert.equal(node.textContent,'Public outline');assert.equal(h.context.location.hash,'#program/philosophy');assert.equal(h.ended.length,0);
}
// Anonymous sign-in/recovery and teacher sign-in entry points are public forms,
// not an expired authenticated workspace.
for(const route of ['#account','#teacher']){
 const clock=new Clock(),server=new Server(clock);server.loggedIn=false;const h=harness({clock,server});h.context.location.hash=route;await drain();assert.equal(h.context.location.hash,route);assert.equal(h.ended.length,0);
}
console.log('PASS: trusted visible activity, one-minute heartbeat throttle, passive checks, two-minute warning/flush, idle and absolute expiry, offline private clearing, cross-session isolation, cross-tab logout, late responses, back-cache checks and legacy-studio redirect.');
