const Study = (() => {
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const questions = lesson => lesson.questions || [{id:'q0',question:lesson.q,answers:lesson.answers,correct:lesson.correct,explanation:'Revisit the written explanation and worked example.',sources:lesson.sources}];
  function grade(items, answers) {
    const results=items.map((item,index)=>({correct:Number.isInteger(answers[index])&&answers[index]===item.correct,explanation:item.explanation}));
    return {results,score:results.filter(item=>item.correct).length,total:items.length,passed:answers.length===items.length&&results.every(item=>item.correct)};
  }
  function referenceLinks(keys, catalogue, numbered=false, order=keys) {
    return [...new Set(keys || [])].map(key=>{
      const source=catalogue.sources[key];
      if(!source)return '';
      const label=numbered?'['+(order.indexOf(key)+1)+']':source.title;
      return '<a href="'+escape(source.url)+'" target="_blank" rel="noopener noreferrer" title="'+escape(source.title+' — '+(source.author||source.publisher||''))+'">'+escape(label)+'</a>';
    }).join(' · ');
  }
  function citation(source) {
    return [source.author,source.title,source.publisher,source.year].filter(Boolean).filter((value,index,array)=>array.indexOf(value)===index).join(' · ');
  }
  function videoAssignment(lesson,catalogue){return lesson.videoAssignment||catalogue.videos[lesson.video].assignment||'';}
  return Object.freeze({escape,questions,grade,referenceLinks,citation,videoAssignment});
})();

function openStudyLesson(i) {
  const lesson=lessons[i],items=Study.questions(lesson),video=course.videos[lesson.video];
  const path=LearningDashboard.orderedLessons(lessons),position=path.findIndex(item=>item.id===i),e=Study.escape;
  const refs=keys=>Study.referenceLinks(keys,course,true,lesson.sources);
  const paragraphs=text=>text.split(/\n\s*\n/).map(part=>'<p>'+e(part)+'</p>').join('');
  const completed=state.completed.includes(i);
  $('modal').classList.add('lesson-open');
  $('modal').dataset.lesson=String(i);
  $('modalbody').innerHTML=
    '<div class="eyebrow">CLASS '+String(i+1).padStart(2,'0')+' / '+e(lesson.module.toUpperCase())+'</div>'+
    '<h2>'+e(lesson.title)+'</h2><p class="class-intro">'+e(lesson.summary)+'</p>'+
    '<p class="study-duration">'+e(lesson.time)+' · '+items.length+' questions · '+Number(lesson.wordCount||0).toLocaleString('en-KE')+' teaching words</p>'+
    (completed?'<p class="study-credit">You already have completion credit for this class. Explore the expanded reading and questions at your pace; your existing progress is kept.</p>':'')+
    '<details class="study-contents"><summary>Inside this class</summary><div>'+lesson.sections.map((section,index)=>'<button type="button" class="textbutton" data-study-section="'+index+'">'+e(section.heading)+'</button>').join('')+'</div></details>'+
    '<div class="class-goals"><h3>By the end of this class</h3><ul>'+lesson.outcomes.map(outcome=>'<li>'+e(outcome)+'</li>').join('')+'</ul></div>'+
    '<div class="class-copy">'+lesson.sections.map((section,index)=>'<section class="study-section" id="study-section-'+index+'"><h3>'+e(section.heading)+'</h3>'+paragraphs(section.text)+'<p class="study-citations">References '+refs(section.sources||lesson.sources)+'</p></section>').join('')+'</div>'+
    '<div class="class-example"><div class="eyebrow">WORKED EXAMPLE / ORIGINAL FICTIONAL CASE</div><h3>'+e(lesson.example.title)+'</h3>'+paragraphs(lesson.example.text)+'<p class="study-citations">Concept references '+refs(lesson.example.sources||lesson.sources)+'</p></div>'+
    '<div class="class-video"><span class="video-icon" aria-hidden="true">▶</span><div><div class="eyebrow">OPTIONAL VIDEO / ANOTHER WAY TO UNDERSTAND</div><h3>'+e(video.title)+'</h3><p>'+e(video.publisher)+'</p><p>'+e(video.context)+'</p><p class="reading-assignment"><strong>Assigned viewing:</strong> '+e(Study.videoAssignment(lesson,course))+'</p><a class="primary" href="'+e(videoLink(lesson))+'" target="_blank" rel="noopener noreferrer">Watch in the classroom ▶</a><small>'+ (lesson.videoStart?'Starts at a relevant chapter. ':'')+'The reading estimate does not describe the video’s duration. Playback does not complete the class. Video credit: '+e(video.publisher)+' · Link checked '+e(video.checked||course.checked)+'.</small></div></div>'+
    '<div class="class-task"><h3>Try it yourself</h3>'+paragraphs(lesson.task)+'<a href="'+(i===16?'#journal':'#tools')+'" id="lesson-tools-link">'+(i===16?'Open your trading journal ☞':'Explore the learning tools ☞')+'</a><p class="study-citations">Background '+refs(lesson.taskSources||lesson.sources)+'</p></div>'+
    '<h3>Apply what you learned</h3><p>Answer all '+items.length+' questions. Check your explanations, revisit the reading and try again when needed. New completion credit is saved after every answer is correct.</p>'+
    '<form id="quiz">'+items.map((question,index)=>'<fieldset class="study-question" id="study-question-'+index+'"><legend>'+(index+1)+'. '+e(question.question)+'</legend>'+question.answers.map((answer,choice)=>'<label><input type="radio" name="answer-'+index+'" value="'+choice+'" required> <span>'+e(answer)+'</span></label>').join('')+'<p class="question-feedback" id="question-feedback-'+index+'"></p><p class="study-citations">Review '+refs(question.sources||lesson.sources)+'</p></fieldset>').join('')+'<button class="primary" type="submit">Check all answers ☞</button><p id="feedback" role="status" aria-live="polite"></p></form>'+
    '<section class="study-reading"><div class="eyebrow">KEEP READING / AUTHOR-CREDITED RESOURCES</div><h3>Take this class further.</h3><ul>'+ (lesson.readings||[]).map(key=>'<li><a href="'+e(course.sources[key].url)+'" target="_blank" rel="noopener noreferrer">'+e(course.sources[key].title)+' ☞</a><span>'+e(course.sources[key].assignment)+'<br>'+e(course.sources[key].author)+' · '+e(course.sources[key].access)+'</span></li>').join('')+'</ul><a href="#reading" id="lesson-reading-shelf">Explore the full reading shelf ☞</a></section>'+
    '<details class="class-sources" open><summary>References, credits & further reading</summary><p>'+e(lesson.attribution||'Original Hisa Classroom teaching with credited background references.')+'</p><p>Checked '+e(lesson.reviewed||course.checked)+'. Kenya-specific procedures and current charges must be confirmed with the relevant official institution or provider. General overseas sources do not establish Kenyan rules.</p><ol>'+lesson.sources.map(key=>{const source=course.sources[key];return '<li><a href="'+e(source.url)+'" target="_blank" rel="noopener noreferrer">'+e(Study.citation(source))+' ☞</a><small>'+e(source.context)+' · '+e(source.access||'Publisher resource')+' · Checked '+e(source.checked||course.checked)+'. '+e(source.license||'Publisher terms apply.')+'</small></li>';}).join('')+'</ol><p>The reading estimate uses 200 words per minute for teaching and worked examples. Exercises, references, video and quiz review take additional time.</p></details>'+
    '<div class="class-pagination"><button class="smallbtn" id="previous-class" data-boundary="'+(position===0)+'" '+(position===0?'disabled':'')+'>☜ Previous class</button><button class="smallbtn" id="next-class" data-boundary="'+(position===path.length-1)+'" '+(position===path.length-1?'disabled':'')+'>Next class ☞</button></div>';
  $('modalbody').querySelectorAll('[data-study-section]').forEach(button=>button.onclick=()=>$('study-section-'+button.dataset.studySection).scrollIntoView({block:'start'}));
  $('lesson-tools-link').onclick=()=>$('modal').close();
  $('lesson-reading-shelf').onclick=()=>$('modal').close();
  $('previous-class').onclick=()=>openLesson(path[position-1].id);
  $('next-class').onclick=()=>openLesson(path[position+1].id);
  $('quiz').onsubmit=async event=>{
    event.preventDefault();
    const form=new FormData(event.target),answers=items.map((_,index)=>{const raw=form.get('answer-'+index);return raw===null?null:Number(raw);});
    const result=Study.grade(items,answers),feedback=$('feedback');
    result.results.forEach((item,index)=>{
      $('study-question-'+index).dataset.result=item.correct?'correct':'incorrect';
      $('question-feedback-'+index).textContent=(item.correct?'Correct. ':'Revisit this answer. ')+item.explanation;
    });
    if(!result.passed){feedback.textContent=result.score+' of '+result.total+' correct. Read the explanations and try again. No new completion was saved.';return;}
    try {
      await saveAction({action:'lesson',lesson:i,answers,answer:answers[0],quizRevision:lesson.quizRevision||1});
      feedback.textContent='All '+items.length+' answers are correct. '+(mode==='cloud'?'Class completed and saved to your account.':'Class completed in this browser. Sign in to save across devices.');
    } catch(error) {feedback.textContent=readableError(error);}
  };
  if(!$('modal').open)$('modal').showModal();
  $('modal').scrollTop=0;
}

function initReadingShelf() {
  const render=()=>{
    const query=$('reading-search').value.trim().toLowerCase(),level=$('reading-level').value;
    const entries=(course.readings||[]).filter(item=>{
      const source=course.sources[item.source];
      return (level==='All levels'||level===item.level)&&(source.title+' '+source.author+' '+item.summary).toLowerCase().includes(query);
    });
    $('reading-count').textContent=entries.length+' books and courses shown';
    $('reading-list').innerHTML=entries.map(item=>{
      const source=course.sources[item.source],e=Study.escape;
      return '<article class="reading-card"><div class="eyebrow">'+e(item.level.toUpperCase())+' / '+e(source.kind.toUpperCase())+'</div><h3>'+e(source.title)+'</h3><p class="reading-author">'+e(source.author)+'<br>'+e(source.publisher)+' · '+e(source.year)+'</p><p>'+e(item.summary)+'</p><p class="reading-assignment"><strong>Your reading assignment:</strong> '+e(source.assignment)+'</p><p class="reading-access">'+e(source.access)+'<br>'+e(source.license)+'</p>'+(source.format==='pdf'?'<p class="reading-format-note">PDF book · an HTML alternative is available in the viewer.</p>':source.alternative?'<p class="reading-format-note">Publisher link plus an alternative reading route in the viewer.</p>':'')+'<a class="primary" href="'+e(source.url)+'" target="_blank" rel="noopener noreferrer">Read in the learning viewer ☞</a>'+(item.chapters.length?'<details><summary>Suggested chapters</summary><ul>'+item.chapters.map(key=>'<li><a href="'+e(course.sources[key].url)+'" target="_blank" rel="noopener noreferrer">'+e(course.sources[key].chapter)+' ☞</a></li>').join('')+'</ul></details>':'')+'<small>Publisher-hosted original · Reference checked '+e(source.checked)+'. Our summary is original; the book or course remains its author’s material.</small></article>';
    }).join('')||'<p>No matching reading. Try another author, topic or level.</p>';
  };
  $('reading-search').oninput=render;
  $('reading-level').onchange=render;
  render();
  document.querySelectorAll('[data-study-refs]').forEach(element=>{
    element.innerHTML='<strong>Method & references.</strong> Original classroom explanation and calculation. '+Study.referenceLinks(element.dataset.studyRefs.split(','),course);
  });
}
