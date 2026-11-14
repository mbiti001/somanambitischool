'use strict';
/* Session deadlines come from the server. This module never authenticates a user,
 * stores student work, or treats polling/media playback as human activity. */
window.SessionRuntime=(()=>{
 const HEARTBEAT_MS=60000,WARNING_SECONDS=120,CHANNEL='soma-session-runtime-v1';
 const studio=/\/speaking(?:\/|$)/.test(location.pathname);
 const endpoint=studio?'../session.php':'session.php',home=studio?'../#home':'#home';
 let current=null,epoch=0,csrfToken='',checking=null,heartbeat=null,pendingActivity=false,lastSent=0,activityTimer=null,tickTimer=null,warning=null,warningLease='',warningFocus=null,ending=false,channel=null;
 const controllers=new Set(),retired=new Set();
 const wall=()=>Date.now(),mono=()=>typeof performance!=='undefined'&&typeof performance.now==='function'?performance.now():wall();
 const visible=()=>document.visibilityState==='visible';
 function serverTime(){if(!current)return null;const elapsed=Math.max(0,mono()-current.anchorMono,wall()-current.anchorWall);return current.serverNow+elapsed/1000;}
 function parseMeta(raw){
  if(!raw||typeof raw!=='object'||typeof raw.id!=='string'||!raw.id.length||raw.id.length>200||/[\x00-\x20\x7f]/.test(raw.id))return null;
  for(const key of ['serverNow','idleSeconds','idleDeadline','absoluteDeadline','expiresAt'])if(typeof raw[key]!=='number'||!Number.isFinite(raw[key]))return null;
  if(raw.serverNow<=0||raw.idleSeconds!==3600||raw.absoluteDeadline>raw.serverNow+28830)return null;
  return {id:raw.id,serverNow:raw.serverNow,idleSeconds:3600,idleDeadline:raw.idleDeadline,absoluteDeadline:raw.absoluteDeadline,expiresAt:Math.min(raw.expiresAt,raw.idleDeadline,raw.absoluteDeadline)};
 }
 function lease(){return current?{id:current.id,serverNow:current.serverNow,idleSeconds:current.idleSeconds,idleDeadline:current.idleDeadline,absoluteDeadline:current.absoluteDeadline,expiresAt:current.expiresAt}:null;}
 function removeWarning(){if(warning){const focus=warning.contains(document.activeElement);warning.remove();warning=null;if(focus&&warningFocus?.isConnected)try{warningFocus.focus({preventScroll:true});}catch{}}warningFocus=null;}
 function cancelActivity(){clearTimeout(activityTimer);activityTimer=null;pendingActivity=false;}
 function abortRequests(){for(const c of controllers)c.abort();controllers.clear();checking=null;heartbeat=null;}
 function retire(id){if(!id)return;retired.add(id);if(retired.size>24)retired.delete(retired.values().next().value);}
 function send(type,metadata){if(!metadata?.id)return;const message=type==='ended'?{version:1,type,id:metadata.id}:{version:1,type,id:metadata.id,session:{id:metadata.id,serverNow:metadata.serverNow,idleSeconds:3600,idleDeadline:metadata.idleDeadline,absoluteDeadline:metadata.absoluteDeadline,expiresAt:metadata.expiresAt}};try{if(channel)channel.postMessage(message);else{localStorage.setItem(CHANNEL,JSON.stringify(message));localStorage.removeItem(CHANNEL);}}catch{/* Storage restrictions do not change the server deadline. */}}
 function clearPrivate(){
  for(const name of ['CurriculumReview','CourseLibrary','CourseAccess','StandardCourses','AcademicCourse','TeacherDesk','CampusCommunity'])try{window[name]?.clear?.();}catch{}
  try{if(typeof deactivateAssessment==='function')deactivateAssessment();}catch{}
  try{if(typeof assessmentDrafts!=='undefined')assessmentDrafts.clear();}catch{}
  try{if(typeof assessmentAttempt!=='undefined')assessmentAttempt=null;if(typeof assessmentOverview!=='undefined')assessmentOverview=null;}catch{}
  try{if(typeof clearLearnerPrivateViews==='function')clearLearnerPrivateViews();}catch{}
  for(const id of ['term-dashboard','term-courses','term-program','term-classroom','term-class','term-peer','term-term-office','term-term-paper','term-term-grades','term-preview','academic-college','academic-writing','academic-academic-office','campus-chat','campus-gradebook','campus-office','learner-profile','student-administration','student-account-card','assessment-workspace','course-workspace','course-outline-content','module-detail','viewer-body','help-messages','modalbody','workspace','speaking-account','speaking-results'])document.getElementById(id)?.replaceChildren();
  for(const dialog of document.querySelectorAll('dialog')){try{if(dialog.open&&typeof dialog.close==='function')dialog.close();}catch{}dialog.removeAttribute('open');dialog.querySelectorAll('iframe,audio,video').forEach(media=>{try{media.pause?.();}catch{}media.remove();});}
  document.querySelectorAll('input[type=password],input[autocomplete=one-time-code]').forEach(input=>{input.value='';});
  const publicPages=new Set(['home','courses','program','integrity','privacy','teaching-promise','faq']);
  document.querySelectorAll('main>[data-page]').forEach(section=>{if(!publicPages.has(section.dataset.page)){section.hidden=true;section.dataset.active='false';}});
  delete document.body.dataset.assessmentActive;delete document.body.dataset.courseActive;
 }
 function publicLocation(){return !studio&&/^#(?:home|account|teacher|courses|program\/[a-z0-9-]+|integrity|privacy|teaching-promise|faq)?$/.test(location.hash||'#home');}
 function end(reason='logout',{broadcast=true,redirect=true,force=false}={}){
  const old=lease(),notify=!!old||force;if(!old&&!force&&!redirect)return false;if(!notify&&ending)return false;
  if(old)retire(old.id);epoch++;current=null;csrfToken='';ending=true;cancelActivity();clearInterval(tickTimer);tickTimer=null;abortRequests();removeWarning();warningLease='';clearPrivate();
  if(broadcast&&old)send('ended',old);
  if(notify)window.dispatchEvent(new CustomEvent('soma-session-ended',{detail:{reason}}));
  if(redirect&&(studio||location.hash!=='#home')){if(studio)location.replace(home);else location.hash='#home';}
  return false;
 }
 function setMeta(meta,source='external'){
  const parsed=parseMeta(meta);if(!parsed)return false;
  if(retired.has(parsed.id))return false;
  if(current?.id===parsed.id){parsed.absoluteDeadline=Math.min(parsed.absoluteDeadline,current.absoluteDeadline);parsed.expiresAt=Math.min(parsed.expiresAt,parsed.absoluteDeadline);}
  if(parsed.expiresAt<=parsed.serverNow){if(current&&current.id!==parsed.id)return false;retire(parsed.id);return end('expired',{broadcast:!!current,force:true});}
  if(current&&current.id!==parsed.id){
   if(source==='server')return end('session-changed',{broadcast:false,force:true});
   retire(current.id);epoch++;csrfToken='';abortRequests();cancelActivity();removeWarning();clearPrivate();
  }else if(!current){epoch++;abortRequests();cancelActivity();}
  if(current&&current.id===parsed.id&&parsed.serverNow<current.serverNow)return true;
  // A stale same-second passive response cannot shorten a newer activity lease.
  if(current&&current.id===parsed.id&&parsed.serverNow===current.serverNow&&parsed.expiresAt<current.expiresAt)return true;
  current={...parsed,anchorMono:mono(),anchorWall:wall()};ending=false;
  if(!tickTimer)tickTimer=setInterval(tick,1000);tick();
  if(source==='external'&&!csrfToken)void check('adopt');
  return !!current;
 }
 function adopt(metadata){
  if(metadata&&typeof metadata==='object'&&Object.prototype.hasOwnProperty.call(metadata,'session')){
   if(metadata.authenticated===false||!metadata.session)return current?end('logout'):false;
   const candidate=parseMeta(metadata.session);if(!candidate||retired.has(candidate.id))return false;
   if(typeof metadata.csrf==='string')csrfToken=metadata.csrf;
   return setMeta(candidate);
  }
  if(metadata===null||metadata===undefined)return current?end('logout'):false;
  return setMeta(metadata);
 }
 async function wire(body=null){
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000);controllers.add(controller);
  try{const response=await fetch(endpoint,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrfToken}:{},body:body?JSON.stringify(body):undefined});let data;try{data=await response.json();}catch{throw Error('The session check could not be read.');}return {response,data};}
  finally{clearTimeout(timer);controllers.delete(controller);}
 }
 async function check(reason='check'){
  if(checking)return checking;const stamp=epoch,id=current?.id||null;
  const job=(async()=>{try{const {response,data}=await wire();if(stamp!==epoch||(id&&current?.id!==id))return false;
   if(response.status===401||response.status===403||data.authenticated===false){return end(reason==='boot'?'signed-out':'expired',{broadcast:!!current,redirect:!!current||!publicLocation(),force:!!current||!publicLocation()});}
   if(!response.ok||data.authenticated!==true||!parseMeta(data.session))throw Error('The sign-in deadline could not be checked.');
   if(typeof data.csrf==='string')csrfToken=data.csrf;return setMeta(data.session,'server');
  }catch(error){if(stamp!==epoch)return false;if(current&&serverTime()>=current.expiresAt)return end('expired-offline');if(warning)warning.querySelector('[data-session-status]').textContent='Unable to check the connection. Your known sign-in deadline has not been extended.';return false;}
  finally{if(checking===job)checking=null;}})();checking=job;return job;
 }
 async function savePending(){
  const stamp=epoch,id=current?.id;const jobs=[];for(const name of ['CurriculumReview','StandardCourses','AcademicCourse','SpeakingAccount'])try{if(typeof window[name]?.flush==='function')jobs.push(Promise.resolve(window[name].flush()));}catch{jobs.push(Promise.reject(Error('Save unavailable')));}
  const results=await Promise.allSettled(jobs);if(stamp!==epoch||current?.id!==id||!warning)return;
  if(results.some(r=>r.status==='rejected'||r.value===false))warning.querySelector('[data-session-status]').textContent='Some pending work could not be saved. Check your connection or stay signed in before the deadline.';
 }
 function makeWarning(){
  if(warning)return;
  if(!document.getElementById('soma-session-runtime-style')){const style=document.createElement('style');style.id='soma-session-runtime-style';style.textContent='#soma-session-warning{position:fixed;right:18px;bottom:18px;z-index:10000;box-sizing:border-box;width:min(470px,calc(100vw - 28px));padding:20px 23px;border:1px solid #a88549;border-top:4px solid #a88549;background:#fff9ed;color:#142c40;box-shadow:0 8px 32px #142c4033;font:17px/1.55 "Times New Roman",Times,Georgia,serif}#soma-session-warning h2{font:400 24px/1.2 "Times New Roman",Times,Georgia,serif;margin:0 0 12px}#soma-session-warning p{margin:8px 0;font:17px/1.55 "Times New Roman",Times,Georgia,serif}#soma-session-warning [role=timer]{font-variant-numeric:tabular-nums;font-weight:700}#soma-session-warning button{font:17px/1.4 "Times New Roman",Times,Georgia,serif;min-height:46px;padding:11px 19px;color:#fff9ed;background:#142c40;border:1px solid #142c40;border-radius:2px;margin-top:9px;cursor:pointer}#soma-session-warning button:focus-visible{outline:3px solid #a88549;outline-offset:4px}#soma-session-warning button:disabled{opacity:.65;cursor:wait}#soma-session-warning [data-session-status]{font-size:16px;color:#5c4b30}#soma-session-warning [hidden]{display:none!important}@media(max-width:520px){#soma-session-warning{right:14px;bottom:14px;padding:17px 19px}#soma-session-warning h2{font-size:23px}}';document.head.append(style);}
  warningFocus=document.activeElement;warning=document.createElement('aside');warning.id='soma-session-warning';warning.setAttribute('role','alert');warning.setAttribute('aria-labelledby','soma-session-warning-title');warning.innerHTML='<h2 id="soma-session-warning-title">Your sign-in ends soon.</h2><p data-session-copy></p><p>Time remaining: <span role="timer" aria-live="off" data-session-countdown></span></p><button type="button" data-session-stay>Stay signed in ☞</button><p data-session-status role="status" aria-live="polite"></p>';document.body.append(warning);
  warning.querySelector('[data-session-stay]').addEventListener('click',event=>{if(event.isTrusted!==true||!visible())return;pendingActivity=true;void renew(true);});
 }
 function tick(){
  if(!current)return;const remaining=current.expiresAt-serverTime();if(remaining<=0){end('expired');return;}
  if(remaining>WARNING_SECONDS){removeWarning();return;}
  makeWarning();const absolute=current.absoluteDeadline<=current.idleDeadline;
  warning.querySelector('[data-session-copy]').textContent=absolute?'This sign-in is reaching its fixed security limit. After it ends, sign in again to continue. Previously saved work remains in your account.':'Without recent activity, you will need to sign in again. Previously saved work remains in your account.';
  const seconds=Math.max(0,Math.ceil(remaining));warning.querySelector('[data-session-countdown]').textContent=String(Math.floor(seconds/60)).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0');warning.querySelector('[data-session-stay]').hidden=absolute;
  const key=current.id+':'+current.expiresAt;if(warningLease!==key){warningLease=key;void savePending();}
 }
 function scheduleActivity(){if(activityTimer||!pendingActivity||!current||!visible())return;const delay=Math.max(0,HEARTBEAT_MS-(wall()-lastSent));activityTimer=setTimeout(()=>{activityTimer=null;if(pendingActivity&&current&&visible())void renew();},delay);}
 async function renew(explicit=false){
  if(!current||!visible()||!pendingActivity||heartbeat)return false;
  if(serverTime()>=current.expiresAt)return end('expired');
  if(!explicit&&wall()-lastSent<HEARTBEAT_MS){scheduleActivity();return false;}
  if(!csrfToken){await check('activity-check');if(!current||!visible()||!pendingActivity||!csrfToken)return false;}
  const stamp=epoch,id=current.id;pendingActivity=false;lastSent=wall();const oldDeadline=current.expiresAt;
  if(warning){warning.querySelector('[data-session-stay]').disabled=true;warning.querySelector('[data-session-status]').textContent='Checking your sign-in…';}
  const job=(async()=>{try{const {response,data}=await wire({action:'activity'});if(stamp!==epoch||current?.id!==id)return false;
   if(response.status===401||response.status===403||data.authenticated===false)return end('expired');
   if(!response.ok||data.authenticated!==true||!parseMeta(data.session)||data.session.id!==id)throw Error('The sign-in could not be renewed.');
   if(typeof data.csrf==='string')csrfToken=data.csrf;if(!setMeta(data.session,'server'))return false;send('renewed',lease());
   if(warning)warning.querySelector('[data-session-status]').textContent=current.expiresAt>oldDeadline?'Your sign-in was renewed.':'The fixed security deadline still applies.';
   return true;
  }catch(error){if(stamp!==epoch)return false;if(current&&serverTime()>=current.expiresAt)return end('expired-offline');if(warning)warning.querySelector('[data-session-status]').textContent='Unable to renew your sign-in. The existing deadline still applies. Check your connection and try again.';return false;}
  finally{if(heartbeat===job)heartbeat=null;if(stamp===epoch){if(warning)warning.querySelector('[data-session-stay]').disabled=false;if(pendingActivity)scheduleActivity();}}})();heartbeat=job;return job;
 }
 function trustedInput(event){if(event.isTrusted!==true||!visible()||!current||ending)return;if(serverTime()>=current.expiresAt){end('expired');return;}pendingActivity=true;scheduleActivity();}
 function receive(message){
  if(!message||message.version!==1||!current||message.id!==current.id)return;
  if(message.type==='ended'){end('another-tab',{broadcast:false});return;}
  if(message.type!=='renewed')return;const meta=parseMeta(message.session);if(!meta||meta.id!==current.id||meta.absoluteDeadline!==current.absoluteDeadline||meta.expiresAt<=current.expiresAt||meta.serverNow<current.serverNow)return;
  // Same-session broadcasts only carry a server-confirmed deadline; they never
  // create an authenticated state or cause another heartbeat.
  if(meta.serverNow>serverTime()+30)return;
  if(setMeta(meta,'broadcast')){lastSent=wall();cancelActivity();}
 }
 try{if(typeof BroadcastChannel==='function'){channel=new BroadcastChannel(CHANNEL);channel.addEventListener('message',event=>receive(event.data));}}catch{channel=null;}
 window.addEventListener('storage',event=>{if(event.key!==CHANNEL||typeof event.newValue!=='string'||event.newValue.length>1800)return;try{receive(JSON.parse(event.newValue));}catch{}});
 for(const type of ['pointerdown','keydown','wheel','touchstart'])document.addEventListener(type,trustedInput,{passive:true,capture:true});
 document.addEventListener('visibilitychange',()=>{if(!visible()){cancelActivity();return;}if(current&&serverTime()>=current.expiresAt){end('expired');return;}void check('visible');});
 window.addEventListener('pageshow',()=>{if(current&&serverTime()>=current.expiresAt){end('expired');return;}void check('pageshow');});
 // A true expiry cannot leave private information visible behind an unsaved-work
 // navigation prompt. The warning already attempted to save two minutes earlier.
 window.addEventListener('beforeunload',event=>{if(ending)event.stopImmediatePropagation();},{capture:true});
 const api=Object.freeze({adopt,check,end,snapshot:()=>lease()});
 const boot=()=>{void check('boot');};if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
 return api;
})();
