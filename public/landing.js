'use strict';
/* The public front door contains course outlines and portal links, never private learner records. */
window.AcademyLanding=(()=>{
 const courses=[
  {code:'INV 201',id:'investing12',title:'Investing in Kenyan Shares',description:'Understand markets, read business evidence and practise decisions with virtual money.',detail:'Markets · research · risk · reflection'},
  {code:'COM 201',id:'speaking12',title:'Public Speaking',description:'Find your message, rehearse with purpose and speak so others can understand.',detail:'Audience · structure · delivery · practice'},
  {code:'ENT 201',id:'ai-business',title:'Entrepreneurship in the Era of AI',description:'Explore a real problem, test a useful idea and examine AI with sound business judgement.',detail:'Customer evidence · economics · responsible AI'},
  {code:'PHI 201',id:'philosophy',title:'Philosophy and Stoicism',description:'Question assumptions, develop an argument and reflect on choices worth making.',detail:'Reasoning · ethics · ancient texts · application'}
 ];
 function mount(root){
  if(!root)return;
  root.classList.add('academy-landing');
  root.innerHTML=`
   <section class="academy-hero" aria-labelledby="academy-hero-title">
    <div class="academy-wrap academy-hero-grid">
     <div class="academy-hero-copy">
      <p class="academy-kicker"><span aria-hidden="true"></span> THE SCHOOL FOR CURIOUS MINDS</p>
      <h1 id="academy-hero-title">A curious mind.<br>A stronger <em>future.</em></h1>
      <p class="academy-lead">Knowledge is a beginning.<br>Make it a skill you can use.</p>
      <p class="academy-intro">Learn through guided reading, video, thoughtful writing and deliberate practice. Build confidence one clear idea—and one considered action—at a time.</p>
      <div class="academy-actions"><a class="academy-button academy-button-navy" href="#courses">Explore our courses <span aria-hidden="true">↗</span></a><a class="academy-text-link" href="#classroom">Student entrance <span aria-hidden="true">→</span></a></div>
      <div class="academy-hero-note"><span class="academy-note-line" aria-hidden="true"></span><p>Study with purpose. Question with confidence.<br>Practise until the learning becomes your own.</p></div>
     </div>
     <figure class="academy-hero-image">
      <div class="academy-image-frame"><img src="academy-study.webp" width="1400" height="1050" alt="Open books, a notebook and spectacles arranged on a quiet study desk." fetchpriority="high" decoding="async"></div>
      <figcaption><span>THE WORK OF A CURIOUS MIND</span><small>Illustrative study scene</small></figcaption>
     </figure>
    </div>
   </section>
   <section class="academy-principles" aria-label="Our approach to learning"><div class="academy-wrap academy-principles-grid">
    <div><span class="academy-small-number">01</span><p><strong>Read with understanding.</strong><span>Credited sources and clear reading assignments.</span></p></div>
    <div><span class="academy-small-number">02</span><p><strong>Practise with purpose.</strong><span>Cases, rehearsal and decisions you can review.</span></p></div>
    <div><span class="academy-small-number">03</span><p><strong>Make steady progress.</strong><span>Mastery checks, feedback and saved work.</span></p></div>
   </div></section>
   <section class="academy-course-section academy-wrap" aria-labelledby="academy-courses-title">
    <div class="academy-section-heading"><div><p class="academy-kicker">YOUR NEXT FIELD OF STUDY</p><h2 id="academy-courses-title">Ideas worth understanding.<br>Skills worth developing.</h2></div><p>Explore the outline before you enroll. Our standard subject courses follow twelve weeks, with a new topic each teaching week, time to study and a final examination.</p></div>
    <div class="academy-course-grid">${courses.map((c,i)=>`<article class="academy-course-card"><div class="academy-card-top"><span>${c.code}</span><span aria-hidden="true">${String(i+1).padStart(2,'0')}</span></div><h3>${c.title}</h3><p>${c.description}</p><small>${c.detail}</small><div class="academy-card-bottom"><span>12 weeks · up to 6 learners</span><a href="#program/${c.id}" aria-label="Explore ${c.title}">Explore course <span aria-hidden="true">→</span></a></div></article>`).join('')}</div>
    <aside class="academy-foundation"><div class="academy-foundation-label"><span>THE FIRST STEP</span><strong>COL 100</strong></div><div><h3>College Success: Think, Learn &amp; Write</h3><p>A short required foundation in critical thinking, learning habits, original writing and APA source use. Begin here before your subject course.</p></div><a class="academy-button academy-button-outline" href="#college">Start the foundation <span aria-hidden="true">→</span></a></aside>
   </section>
   <section class="academy-journey" aria-labelledby="academy-journey-title"><div class="academy-wrap academy-journey-grid"><div><p class="academy-kicker">A STRUCTURE YOU CAN FOLLOW</p><h2 id="academy-journey-title">Learn at your pace.<br>Keep a clear direction.</h2><p>Good learning needs more than a page to scroll. Each week gives you a goal, material to study, work to attempt and a reason to reflect.</p><a class="academy-text-link" href="#teaching-promise">Our learning promise <span aria-hidden="true">→</span></a></div><ol>
    <li><span>01</span><div><h3>Build your foundation</h3><p>Complete College Success and prepare to read, reason and write independently.</p></div></li>
    <li><span>02</span><div><h3>Choose your classroom</h3><p>Enroll in a Monday class. Open one enrolled course at a time while keeping each course’s work separate.</p></div></li>
    <li><span>03</span><div><h3>Study, discuss and practise</h3><p>Use assigned readings and videos, apply an idea, write your response and take part in structured peer review.</p></div></li>
    <li><span>04</span><div><h3>Demonstrate understanding</h3><p>Earn 100% on module tests before progressing. Use the study week to prepare for the final examination.</p></div></li>
   </ol></div></section>
   <section class="academy-portals academy-wrap" aria-labelledby="academy-portals-title"><div class="academy-portal-intro"><p class="academy-kicker">WELCOME TO SOMA NA MBITI</p><h2 id="academy-portals-title">Your place in<br>the academy.</h2><p>Different responsibilities. One commitment to careful, honest learning.</p></div><div class="academy-portal-card"><span class="academy-portal-label">FOR STUDENTS</span><h3>Continue your learning.</h3><p>Open your enrolled classroom, return to saved work and review your progress.</p><a class="academy-button academy-button-ivory" href="#classroom">Student entrance <span aria-hidden="true">→</span></a><a class="academy-portal-small" href="#account">New here? Create or sign in to your account →</a></div><div class="academy-portal-card academy-portal-teacher"><span class="academy-portal-label">FOR APPROVED TEACHERS</span><h3>Guide the learning.</h3><p>Review student work, support discussions and give clear, constructive feedback.</p><a class="academy-button academy-button-gold" href="#teacher">Teacher entrance <span aria-hidden="true">→</span></a><small>Teacher access is granted by the super administrator. A student account does not provide teaching permissions.</small></div></section>
   <div class="academy-closing academy-wrap"><span aria-hidden="true">✦</span><p>Read carefully. Think independently. Put what you learn to work.</p><a href="#integrity">Our academic integrity policy →</a></div>`;
 }
 return Object.freeze({mount});
})();
