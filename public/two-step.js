'use strict';
window.SigninStep=(()=>{
 const active=new WeakMap();
 function render(root,initial,{send,onComplete,onBack,recoveryHref='?signin=forgot#account',notice='Check your inbox and spam folder for your sign-in code.'}){
  active.get(root)?.();let step=initial,busy=false,expires=0,resendAt=0,deadline=0;
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  root.innerHTML='<div class="eyebrow">ONE MORE SECURITY CHECK</div><h2>Check your email.</h2><p>Enter the six-digit code sent to <strong>'+esc(step.emailHint)+'</strong> to finish signing in on this browser.</p><form id="signin-code-form"><label for="signin-code">Six-digit email code</label><input id="signin-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required spellcheck="false"><label class="remember-browser"><input type="checkbox" name="remember"> Remember this browser for 30 days</label><p class="auth-security-note">Leave this unticked on a shared or public computer. Clearing browser cookies, using another browser or resetting your password will require a new code.</p><button class="primary" type="submit">Verify code &amp; sign in ☞</button><p id="signin-code-status" role="status" aria-live="polite"></p></form><p id="signin-code-expiry" class="auth-security-note"></p><div class="auth-links step-actions"><button type="button" id="signin-code-resend">Resend code</button><button type="button" id="signin-code-back">Back to sign in</button><a href="'+esc(recoveryHref)+'">Use password recovery</a></div>';
  const form=root.querySelector('form'),code=form.elements.namedItem('code'),remember=form.elements.namedItem('remember'),submit=form.querySelector('[type=submit]'),status=root.querySelector('#signin-code-status'),expiry=root.querySelector('#signin-code-expiry'),resend=root.querySelector('#signin-code-resend'),back=root.querySelector('#signin-code-back');
  status.textContent=notice;const connected=()=>form.isConnected&&root.contains(form);
  function receive(next){step=next;const now=Date.now();expires=now+Math.max(0,Number(step.expiresIn)||0)*1000;resendAt=now+Math.max(0,Number(step.resendAfter)||0)*1000;deadline=now+Math.max(0,Number(step.deadlineIn)||0)*1000;}
  function lock(button,value){button.disabled=value;button.dataset.locked=String(value);}
  function sync(){if(!connected())return;const now=Date.now(),left=Math.max(0,Math.ceil((expires-now)/1000)),wait=Math.max(0,Math.ceil((resendAt-now)/1000)),ended=now>=deadline;
   lock(submit,busy||!step.hasCode||left===0||ended);lock(resend,busy||wait>0||ended);lock(back,busy);code.disabled=busy;remember.disabled=busy;
   resend.textContent=wait?'Resend in '+Math.floor(wait/60)+':'+String(wait%60).padStart(2,'0'):'Resend code';
   expiry.textContent=ended?'This sign-in has expired. Return to sign in and enter your password again.':!step.hasCode||!left?'Request a new code to continue.': 'Code expires in '+Math.floor(left/60)+':'+String(left%60).padStart(2,'0')+'. Only the latest code for this browser works.';
  }
  async function failure(error){if(!connected())return;if(error.data?.restartLogin){await onBack(error);return;}if(error.data?.twoStep)receive(error.data.twoStep);else if(error.status===429&&Number(error.data?.retryAfter)>0)resendAt=Date.now()+Number(error.data.retryAfter)*1000;status.textContent=error.message||'Unable to complete this check. Please try again.';}
  form.onsubmit=async e=>{e.preventDefault();sync();if(submit.disabled||!form.checkValidity())return;busy=true;sync();status.textContent='Checking your code…';try{const data=await send({action:'verify_login_code',challengeId:step.challengeId,code:code.value,rememberBrowser:remember.checked});if(connected())await onComplete(data);}catch(error){await failure(error);}finally{busy=false;sync();}};
  resend.onclick=async()=>{sync();if(resend.disabled)return;busy=true;sync();try{const data=await send({action:'resend_login_code',challengeId:step.challengeId});if(!connected())return;receive(data.twoStep);code.value='';status.textContent=data.notice||'A new code has been sent. Use the newest message.';}catch(error){await failure(error);}finally{busy=false;sync();}};
  back.onclick=async()=>{if(busy)return;busy=true;sync();try{await send({action:'cancel_login',challengeId:step.challengeId});if(connected())await onBack();}catch(error){if(error.data?.restartLogin&&connected())await onBack();else await failure(error);}finally{busy=false;sync();}};
  receive(initial);sync();const timer=setInterval(()=>{if(!connected()){clearInterval(timer);return;}sync();},1000);active.set(root,()=>clearInterval(timer));return {sync};
 }
 return {render};
})();
