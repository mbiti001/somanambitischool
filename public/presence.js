'use strict';
/* This service never sends session activity or extends an authentication deadline. */
window.SchoolPresence=(()=>{
 const endpoint=/\/speaking(?:\/|$)/.test(location.pathname)?'../presence.php':'presence.php';
 const validId=id=>typeof id==='string'&&/^[a-f0-9]{32}$/.test(id);
 const visible=()=>document.visibilityState==='visible';
 let epoch=0,runtime='',tab='',csrf='',sequence=0,timer=null,queued=null,observer=null,running=null,enabled=false,repulse=false;
 const controllers=new Set();
 const snapshot=()=>window.SessionRuntime?.snapshot?.()?.id||'';
 const fresh=(stamp,id)=>stamp===epoch&&enabled&&id===runtime&&snapshot()===id;
 function badge(id){return validId(id)?'<span class="soma-presence" data-presence-user="'+id+'" data-online="false" role="img" aria-label="Offline" title="Offline"><span class="soma-presence-dot" aria-hidden="true"></span><span class="soma-presence-label" aria-hidden="true">Offline</span></span>':'';}
 function paint(online={}){document.querySelectorAll('[data-presence-user]').forEach(node=>{const on=online[node.dataset.presenceUser]===true,label=on?'Online':'Offline';node.dataset.online=String(on);node.setAttribute('aria-label',label);node.setAttribute('title',label);const text=node.querySelector('.soma-presence-label');if(text)text.textContent=label;});}
 function randomTab(){const bytes=new Uint8Array(32);crypto.getRandomValues(bytes);return Array.from(bytes,x=>x.toString(16).padStart(2,'0')).join('');}
 async function wire(path,body=null,{keepalive=false}={}){
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);controllers.add(controller);
  try{const response=await fetch(path,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal,keepalive,headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:body?JSON.stringify(body):undefined});const data=await response.json();return {response,data};}
  finally{clearTimeout(timeout);controllers.delete(controller);}
 }
 function ids(){return [...new Set([...document.querySelectorAll('[data-presence-user]')].map(node=>node.dataset.presenceUser).filter(validId))].slice(0,50);}
 async function load(stamp,id){
  const result=await wire(endpoint+'?ids='+encodeURIComponent(ids().join(',')));
  if(!fresh(stamp,id)||!visible())return false;
  if(!result.response.ok){if([401,403].includes(result.response.status))clear();else paint();return false;}
  if(result.data.verified!==true||result.data.runtime!==id){clear();return false;}
  csrf=typeof result.data.csrf==='string'?result.data.csrf:'';
  paint(result.data.online&&typeof result.data.online==='object'?result.data.online:{});return true;
 }
 async function sync(pulse){
  if(!enabled||!visible())return false;
  if(snapshot()!==runtime){clear();return false;}
  if(running){if(pulse)repulse=true;return running;}
  const stamp=epoch,id=runtime;
  const job=(async()=>{
   try{
    if(!csrf&&!await load(stamp,id))return false;
    if(!fresh(stamp,id)||!visible())return false;
    if(pulse){const result=await wire(endpoint,{action:'pulse',runtime:id,tab,sequence:++sequence});if(!fresh(stamp,id)||!visible())return false;if(!result.response.ok){paint();return false;}}
    if(!fresh(stamp,id)||!visible())return false;
    return await load(stamp,id);
   }catch{if(fresh(stamp,id))paint();return false;}
   finally{if(running===job){running=null;if(repulse&&fresh(stamp,id)&&visible()){repulse=false;void Promise.resolve().then(()=>{if(fresh(stamp,id)&&visible())return sync(true);});}}}
  })();running=job;return job;
 }
 function leave(){
  if(!runtime||!tab||!csrf)return;
  // Captured old runtime is checked against the server session, so a response or
  // request after an account change cannot affect the new account's lease.
  const body={action:'leave',runtime,tab,sequence:++sequence},token=csrf;
  void fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify(body)}).catch(()=>{});
 }
 function clear(){
  leave();epoch++;enabled=false;runtime='';tab='';csrf='';sequence=0;repulse=false;
  clearInterval(timer);timer=null;clearTimeout(queued);queued=null;
  observer?.disconnect();observer=null;
  for(const controller of controllers)controller.abort();controllers.clear();running=null;paint();
 }
 function schedule(){clearInterval(timer);timer=null;if(enabled&&visible())timer=setInterval(()=>{void sync(true);},60000);}
 async function start(){
  const id=snapshot();if(!validId(id)){clear();return false;}
  if(enabled&&runtime===id){schedule();return sync(true);}
  clear();runtime=id;tab=randomTab();enabled=true;sequence=0;
  observer=new MutationObserver(records=>{if(!records.some(r=>[...r.addedNodes,...r.removedNodes].some(n=>n.nodeType===1)))return;clearTimeout(queued);queued=setTimeout(()=>{if(enabled&&visible())void sync(false);},250);});
  observer.observe(document.body,{childList:true,subtree:true});schedule();return sync(true);
 }
 function refresh(){return sync(false);}
 document.addEventListener('visibilitychange',()=>{if(!enabled)return;if(!visible()){leave();paint();}schedule();if(visible())void sync(true);});
 window.addEventListener('pagehide',()=>{if(enabled)leave();});
 window.addEventListener('pageshow',event=>{if(event.persisted&&enabled&&visible())void sync(true);});
 window.addEventListener('soma-session-ended',clear);
 return {badge,start,refresh,clear};
})();
