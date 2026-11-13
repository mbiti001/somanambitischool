'use strict';
window.AuthUI=(()=>{
 const mounted=new WeakMap(),waits=new Map();let connectionUntil=0;
 const identity=form=>(form.elements.namedItem('email')?.value||'').trim().toLowerCase();
 function mount(form){
  if(!form||mounted.has(form))return mounted.get(form);
  const submit=form.querySelector('button[type="submit"],button.primary'),caption=submit?.textContent;
  const status=document.createElement('p');status.className='auth-wait';status.hidden=true;form.append(status);
  const passwords=[],feedback=form.querySelector('[role="status"]');let pauseFeedback=null;
  form.querySelectorAll('input[type="password"]').forEach((input,index)=>{
   if(!input.id)input.id=form.id+'-password-'+index;
   input.autocapitalize='off';input.spellcheck=false;
   const labelParent=input.parentElement.tagName==='LABEL'?input.parentElement:null;let field;if(labelParent){field=document.createElement('div');field.className='auth-field';labelParent.before(field);field.append(labelParent);labelParent.htmlFor=input.id;}
   const row=document.createElement('div');row.className='auth-password-row';input.before(row);row.append(input);if(field)field.append(row);
   const toggle=document.createElement('button');toggle.type='button';toggle.className='auth-password-toggle';toggle.setAttribute('aria-controls',input.id);row.append(toggle);
   const label=input.name==='confirm'?'confirmation password':'password';
   const mask=()=>{input.type='password';toggle.textContent='Show';toggle.setAttribute('aria-label','Show '+label);toggle.setAttribute('aria-pressed','false');};mask();passwords.push(mask);
   toggle.onclick=()=>{if(input.type==='password'){input.type='text';toggle.textContent='Hide';toggle.setAttribute('aria-label','Hide '+label);toggle.setAttribute('aria-pressed','true');}else mask();};
   const hint=document.createElement('p');hint.id=input.id+'-caps';hint.className='auth-caps';hint.textContent='Caps Lock is on.';hint.hidden=true;row.after(hint);input.setAttribute('aria-describedby',((input.getAttribute('aria-describedby')||'')+' '+hint.id).trim());
   const caps=e=>{hint.hidden=!e.getModifierState?.('CapsLock');};input.addEventListener('keydown',caps);input.addEventListener('keyup',caps);input.addEventListener('blur',()=>{hint.hidden=true;});
  });
  const isLogin=form.dataset.authAction==='login';
  function update(){
   const seconds=isLogin?Math.max(0,Math.ceil((Math.max(connectionUntil,waits.get(identity(form))||0)-Date.now())/1000)):0;
   if(!submit)return;submit.dataset.locked=String(seconds>0);if(seconds){submit.disabled=true;submit.textContent='Try again in '+Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0');status.hidden=false;status.textContent='Sign-in is temporarily paused. You can still use Forgot password. Other open study sessions are not signed out.';}
   else{if(submit.textContent.startsWith('Try again in '))submit.textContent=caption;if(submit.dataset.authWaiting==='true')submit.disabled=false;status.hidden=true;}
   if(!seconds&&pauseFeedback!==null){if(feedback?.textContent===pauseFeedback)feedback.textContent='';pauseFeedback=null;}
   submit.dataset.authWaiting=String(seconds>0);
  }
  const report=(error,submittedEmail)=>{const seconds=Number(error?.data?.retryAfter);if(isLogin&&error.status===429&&Number.isFinite(seconds)&&seconds>0){pauseFeedback=feedback?.textContent??null;const until=Date.now()+Math.min(seconds,3600)*1000;if(error.data.lockScope==='email')waits.set(typeof submittedEmail==='string'?submittedEmail.trim().toLowerCase():identity(form),until);else connectionUntil=until;update();}};
  form.addEventListener('submit',e=>{update();if(isLogin&&submit?.dataset.locked==='true'){e.preventDefault();e.stopImmediatePropagation();return;}passwords.forEach(mask=>mask());},true);
  form.querySelectorAll('input').forEach(input=>input.addEventListener('input',()=>{update();if(submit?.dataset.locked!=='true'&&feedback)feedback.textContent='';}));
  const controller={report,refresh:update,hide:()=>passwords.forEach(mask=>mask())};mounted.set(form,controller);update();
  const timer=setInterval(()=>{if(!form.isConnected){clearInterval(timer);return;}update();},1000);
  return controller;
 }
 function hideAll(){document.querySelectorAll('form').forEach(form=>mounted.get(form)?.hide());}
 document.addEventListener('close',hideAll,true);document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden')hideAll();});window.addEventListener('blur',hideAll);
 return {mount};
})();
