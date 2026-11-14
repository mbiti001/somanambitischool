'use strict';
/* Presentation only. Supplied HTML fragments must come from the school's trusted renderers. */
window.ClassroomReader=(()=>{
 const escape=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const https=value=>{try{const u=new URL(value);return u.protocol==='https:'&&!u.username&&!u.password?u.href:null;}catch{return null;}};
 const paragraphs=text=>String(text??'').split(/\n\s*\n/).filter(x=>x.trim()).map(x=>'<p class="reader-prose">'+escape(x)+'</p>').join('');
 function mount(pane,options={},onPage){
  if(!pane||typeof pane.replaceChildren!=='function')throw new TypeError('A reader container is required.');
  const content=options.content||{},sources=options.sources||{};
  const pages=[];
  const add=(key,title,html)=>pages.push({key,title,html});
  add('overview','Module overview','<p class="reader-kicker">Study guide</p>'+(content.title&&options.showModuleTitle!==false?'<p class="reader-module-title">'+escape(content.title)+'</p>':'')+(content.goal?'<h3>Your study goal</h3>'+paragraphs(content.goal):'')+(Array.isArray(content.outcomes)&&content.outcomes.length?'<h3>By the end of this module</h3><ul>'+content.outcomes.map(x=>'<li>'+escape(x)+'</li>').join('')+'</ul>':'')+(content.studyHours?'<p><strong>Suggested study time:</strong> '+escape(content.studyHours)+'</p>':'')+(content.originalReadingMinutes?'<p>Original guide: approximately '+escape(content.originalReadingMinutes)+' minutes, alongside assigned sources and practice.</p>':'')+'<p>Use the contents to move between readings, guide pages and activities. Turning a page does not submit work or complete an assessment.</p>'+(options.preview?'<p class="reader-preview">Course preview</p>':''));
  (Array.isArray(content.sections)?content.sections:[]).forEach((section,i)=>{
   const refs=(Array.isArray(section.sources)?section.sources:[]).filter(k=>Object.prototype.hasOwnProperty.call(sources,k)&&https(sources[k]?.url)).map(k=>'<a data-term-resource="'+escape(k)+'" href="'+escape(https(sources[k].url))+'">'+escape(sources[k].title||k)+' ☞</a>');
   add('guide-'+i,section.title||'Guide page '+(i+1),paragraphs(section.text)+(refs.length?'<aside class="reader-references" aria-label="Page references"><h3>Read the sources</h3><ul>'+refs.map(x=>'<li>'+x+'</li>').join('')+'</ul></aside>':''));
  });
  add('readings','Assigned readings',options.readingsHTML||'<p>No separate readings are assigned for this module. Follow the source references on the guide pages.</p>');
  if(content.video){
   const v=content.video,start=Number(v.start||0),end=Number(v.end),id=String(v.id||'');
   const valid=/^[a-zA-Z0-9_-]{11}$/.test(id)&&Number.isFinite(start)&&Number.isFinite(end)&&start>=0&&end>start;
   const url=https(v.url);
   add('video',v.title||'Watch and take notes',(v.publisher?'<p>'+escape(v.publisher)+'</p>':'')+paragraphs(v.assignment)+(valid?'<p><strong>Assigned segment:</strong> '+Math.floor(start/60)+':'+String(Math.floor(start%60)).padStart(2,'0')+'–'+Math.floor(end/60)+':'+String(Math.floor(end%60)).padStart(2,'0')+'. Play when you are ready.</p><iframe class="reader-video" title="'+escape(v.title||'Assigned video')+'" src="https://www.youtube-nocookie.com/embed/'+id+'?start='+Math.floor(start)+'&amp;end='+Math.floor(end)+'&amp;rel=0" loading="lazy" allow="encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>':'<p>Use the publisher’s link and follow the assigned viewing instructions.</p>')+(url?'<p><a href="'+escape(url)+'" target="_blank" rel="noopener noreferrer">Open the publisher’s video ☞</a></p>':''));
  }
  add('practice','Practice and reflection',options.practiceHTML||paragraphs(content.case)+(Array.isArray(content.practice)?'<ol>'+content.practice.map(x=>'<li>'+escape(x)+'</li>').join('')+'</ol>':paragraphs(content.practice))||'<p>Review your source notes and practise explaining the main idea in your own words.</p>');
  (Array.isArray(options.extraPages)?options.extraPages:[]).forEach((page,i)=>add(String(page.key||'extra-'+i),String(page.title||'Additional study'),String(page.html||'')));
  add('assignments','Assignments and assessment',options.completionHTML||'<p>Use the module’s assignments and assessment links to check the work required.</p>');
  if(Array.isArray(options.pagesOverride)&&options.pagesOverride.length){pages.length=0;options.pagesOverride.forEach((page,i)=>add(String(page.key||'page-'+i),String(page.title||'Study page '+(i+1)),String(page.html||'')));}
  pane.innerHTML='<div class="classroom-reader"><nav class="reader-contents" aria-label="Module page contents"><h3>In this module</h3><ol>'+pages.map((p,i)=>'<li><button type="button" data-reader-page="'+i+'">'+escape(p.title)+'</button></li>').join('')+'</ol></nav><div class="reader-book">'+pages.map(p=>'<section class="reader-page" data-reader-key="'+escape(p.key)+'" hidden><h2 tabindex="-1" class="reader-page-title">'+escape(p.title)+'</h2>'+p.html+'</section>').join('')+'<nav class="reader-pagination" aria-label="Guide pages"><button type="button" data-reader-prev>☜ Previous</button><span data-reader-count role="status" aria-live="polite" aria-atomic="true"></span><button type="button" data-reader-next>Next ☞</button></nav></div></div>';
  const panels=[...pane.querySelectorAll('.reader-page')],buttons=[...pane.querySelectorAll('[data-reader-page]')];
  const prev=pane.querySelector('[data-reader-prev]'),next=pane.querySelector('[data-reader-next]'),count=pane.querySelector('[data-reader-count]');
  let index=-1;
  function show(value,focus=false){
   let target=typeof value==='string'?pages.findIndex(p=>p.key===value):value;
   if(!Number.isInteger(target)||target<0||target>=pages.length)target=0;
   if(index===target)return;
   if(index>=0)panels[index].querySelectorAll('iframe').forEach(frame=>{const src=frame.getAttribute('src');frame.removeAttribute('src');frame.setAttribute('src',src);});
   index=target;panels.forEach((p,i)=>{p.hidden=i!==index;});buttons.forEach((b,i)=>{if(i===index)b.setAttribute('aria-current','page');else b.removeAttribute('aria-current');});
   prev.disabled=index===0;next.disabled=index===pages.length-1;count.textContent='Page '+(index+1)+' of '+pages.length;
   if(focus)panels[index].querySelector('h2').focus({preventScroll:true});
   if(typeof onPage==='function')onPage({key:pages[index].key,index,title:pages[index].title});
  }
  buttons.forEach((b,i)=>b.addEventListener('click',()=>show(i,true)));prev.addEventListener('click',()=>show(index-1,true));next.addEventListener('click',()=>show(index+1,true));
  show(options.initialPage??0);
  return Object.freeze({show,getPage:()=>({key:pages[index].key,index,title:pages[index].title})});
 }
 return Object.freeze({mount});
})();
