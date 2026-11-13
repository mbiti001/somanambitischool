const LearningViewer = (() => {
  const framedSources = new Set(['cma','products','complaints']);
  function sourceKey(href, sources, base) {
    let url;
    try { url = new URL(href, base); } catch { return null; }
    if (url.protocol !== 'https:') return null;
    const clean = value => {
      const parsed = new URL(value);
      return parsed.origin + parsed.pathname.replace(/\/+$/, '') + parsed.search;
    };
    return Object.keys(sources).find(key => clean(sources[key].url) === clean(url.href)) || null;
  }
  function video(href, videos, base) {
    let url;
    try { url = new URL(href, base); } catch { return null; }
    if (url.protocol !== 'https:' || url.hostname !== 'www.youtube.com' || url.pathname !== '/watch') return null;
    const id = url.searchParams.get('v'), item = Object.values(videos).find(item => item.id === id);
    if (!item) return null;
    const rawStart = url.searchParams.get('t') || '';
    const start = /^\d+s?$/.test(rawStart) ? Number(rawStart.replace(/s$/, '')) : 0;
    const embed = new URL('https://www.youtube-nocookie.com/embed/' + id);
    embed.searchParams.set('playsinline', '1');
    embed.searchParams.set('rel', '0');
    if (Number.isSafeInteger(start) && start > 0 && start < 86400) embed.searchParams.set('start', String(start));
    const rawEnd=Number(url.searchParams.get('end')||item.end),end=Number.isSafeInteger(rawEnd)&&rawEnd>start&&rawEnd<86400?rawEnd:item.end;if(end>start)embed.searchParams.set('end',String(end));const clock=n=>String(Math.floor(n/60)).padStart(2,'0')+':'+String(n%60).padStart(2,'0');return {...item,assignment:'Study '+clock(start)+'–'+clock(end)+'. Pause to connect the explanation to your lesson.',start,end,embed:embed.href};
  }
  return Object.freeze({sourceKey,video,canFrame:(key,sources={}) => sources[key]?.format!=='pdf'&&(framedSources.has(key)||sources[key]?.embed===true)});
})();

const resourcePrompts = {
  cma:['Separate what an investment offers from what it guarantees.','List the risks, costs and questions you still need to resolve.','Use current official guidance alongside the class, rather than relying on a sales pitch.'],
  cdsc:['Clarify how your shares would be recorded and what account structure is being described.','Write down the documents, statements and questions you need to discuss with the provider.','Confirm current procedures and charges directly before opening a real account.'],
  licences:['Search the exact legal name of the intermediary.','Compare its licence category with the service being offered.','Check published contact details independently; a familiar logo or social-media following is not a licence.'],
  nse:['Look for an issuer’s official announcements and financial reports.','Check the date, units and source of any figure.','Bring the evidence back to your research notes before drawing a conclusion.'],
  stocks:['Describe a share as ownership in a business.','Distinguish dividends from a change in the share price.','Explain why neither a dividend nor a profitable sale is guaranteed.'],
  orders:['Compare an instruction to seek the available price with an instruction that sets a price boundary.','Think about incomplete fills, a changing quote and the possibility of no execution.','Ask the Kenyan intermediary how its own order service works.'],
  risk:['Look for risks that several holdings may share.','Separate willingness to accept loss from the ability to afford it.','Explain why diversification can reduce some exposures without eliminating every loss.'],
  fees:['Record both the buying and selling sides of a transaction.','Separate fixed charges, percentage charges, minimum fees and other costs.','Use an actual provider tariff before applying the lesson to real money.'],
  compound:['Change one assumption at a time and compare the result.','Separate your contributions from a hypothetical gain or loss.','Treat smooth growth as a mathematical scenario, not a forecast for shares.'],
  statements:['Connect the income statement, balance sheet and cash-flow statement.','Check the period, units and definitions of each number.','Use the latest issuer reports and write down questions that remain unanswered.'],
  products:['Identify what you would own or lend to and how the product is structured.','Compare charges, liquidity, risks and how returns may arise.','Check current Kenyan product availability and terms with the relevant provider.'],
  fraud:['Look for pressure, unverifiable claims and promises of easy returns.','Verify the provider independently before sharing details or money.','Use Kenyan official channels for licence checks and complaints.'],
  complaints:['Keep statements, transaction records and the correspondence relevant to a concern.','Look up the current complaint process and the appropriate institution.','Record the dates, questions and responses without including passwords in your notes.'],
  stops:['Explain the difference between a trigger price and the eventual execution price.','Consider gaps, a fast market and limited liquidity.','Confirm what order types the intermediary supports and how it handles them.'],
  'trading-risk':['Look beyond a profitable example to costs, losses and execution uncertainty.','Ask whether the evidence covers enough decisions to support the claim.','Keep overseas market rules separate from Kenyan account requirements.']
};

function initLearningViewer() {
  const viewer = $('learning-viewer'), body = $('viewer-body'), close = $('viewer-close');
  let returnTarget = null;
  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
  };
  close.onclick = () => viewer.close();
  viewer.addEventListener('close', () => {
    // Removing the player also stops audio when the learner returns to a class.
    body.replaceChildren();
    if (returnTarget?.isConnected) returnTarget.focus({preventScroll:true});
  });
  function frame(src, title, video=false) {
    const iframe = document.createElement('iframe');
    iframe.src = src;
    iframe.title = title;
    iframe.className = video ? 'viewer-video-frame' : 'viewer-source-frame';
    iframe.referrerPolicy = 'strict-origin-when-cross-origin';
    if (video) {
      iframe.allow = 'accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen';
      iframe.allowFullscreen = true;
    } else iframe.sandbox = 'allow-scripts allow-same-origin allow-forms';
    return iframe;
  }
  function open(anchor, media, key) {
    const source = key ? course.sources[key] : null;
    const originLesson = document.body.dataset.courseActive && activeGuide ? lessons[activeGuide.lesson] : $('modal').open && $('modal').classList.contains('lesson-open') ?
      lessons.find(lesson => lesson.id === Number($('modal').dataset.lesson)) : null;
    const related = originLesson || lessons.find(lesson => media ?
      course.videos[lesson.video].id === media.id : lesson.sources.includes(key));
    returnTarget = anchor;
    body.replaceChildren();
    $('viewer-title').textContent = media ? media.title : source.title;
    $('viewer-context').textContent = media ? media.publisher : source.context;
    viewer.classList.toggle('is-video', !!media);
    body.append(node('p',media ? 'Video credit: '+media.publisher+' · Link checked '+(media.checked||course.checked)+'. Publisher terms apply.' : Study.citation(source)+' · '+(source.access||'Publisher resource')+' · '+(source.license||'Publisher terms apply.')+' · Checked '+(source.checked||course.checked)+'.','viewer-credit'));
    if (media) {
      body.append(node('p',originLesson?.videoAssignment||media.assignment,'reading-assignment'));
      body.append(frame(media.embed, media.title + ' — YouTube video', true));
      body.append(node('p',media.context,'viewer-context-note'));
      const prompt = node('section',undefined,'viewer-study');
      prompt.append(node('div','WATCH WITH A QUESTION','eyebrow'),
        node('h3','Bring the explanation back to your own thinking.'));
      const list = node('ul');
      (related?.outcomes || ['Write down one useful idea and one question you still have.']).forEach(text=>list.append(node('li',text)));
      prompt.append(list);
      body.append(prompt);
    } else {
      body.append(node('p',source.assignment||'Read the main text of this linked page.','reading-assignment'));
      const study = node('section',undefined,'viewer-study');
      study.append(node('div','CLASSROOM READING GUIDE','eyebrow'),node('h3','What to look for in this resource.'));
      const list = node('ul');
      (resourcePrompts[key] || related?.outcomes || ['Check the author, publication date and scope of the resource.','Explain one useful idea in your own words and note where you found it.','Record a question to bring back to the class; distinguish general concepts from Kenyan requirements.']).forEach(text=>list.append(node('li',text)));
      study.append(list,node('p','These study prompts are from Hisa Classroom. The publisher’s original material remains its own.','viewer-attribution'));
      body.append(study);
      if (LearningViewer.canFrame(key,course.sources)) {
        body.append(node('h3','Publisher’s original resource'),frame(source.url,source.title));
      } else body.append(node('p',source.format==='pdf' ? 'This book is a publisher PDF. Some phone and in-app browsers cannot display large PDFs. Use the HTML alternative below for reading here, or open the publisher PDF in your preferred PDF reader.' : 'This publisher’s full resource is available on its own website. You can keep using this reading guide here, or consult the original when you need it.','viewer-publisher-note'));
      if(source.alternative&&course.sources[source.alternative]){
        const alternative=course.sources[source.alternative],panel=node('section',undefined,'viewer-study');
        panel.append(node('h3',source.format==='pdf'?'Read an HTML alternative':'If the publisher page is unavailable'),node('p','A separate, credited resource covering related concepts: '+Study.citation(alternative)),node('p',alternative.access+' · '+alternative.license,'viewer-attribution'),node('p',alternative.assignment||'Read the linked page.','reading-assignment'));
        const link=node('a','Read '+alternative.title+' here →','primary');link.href=alternative.url;link.target='_blank';link.rel='noopener noreferrer';panel.append(link);body.append(panel);
      }
    }
    const actions = node('div',undefined,'viewer-actions');
    const back = node('button',originLesson ? 'Back to your class →' : related ? 'Read the related class →' : 'Keep learning →','primary');
    back.type = 'button';
    back.onclick = () => {
      viewer.close();
      if (!originLesson && related) openLesson(related.id);
    };
    const original = node('a',media ? 'Open on YouTube ↗' : source.format==='pdf' ? 'Open publisher PDF ↗' : 'Open official source ↗','viewer-original');
    original.href = anchor.href;
    original.target = '_blank';
    original.rel = 'noopener noreferrer';
    original.dataset.viewerOriginal = 'true';
    actions.append(back,original);
    body.append(actions,node('p',media ?
      'Press Play when you are ready. Playback does not mark a class complete. YouTube controls availability, ads and related videos. If the player is unavailable, use the original link.' :
      'Publisher pages can change or be unavailable. Close this panel to return to your class without losing your place.','viewer-footnote'));
    viewer.showModal();
    viewer.scrollTop = 0;
    close.focus();
  }
  document.addEventListener('click',event => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const anchor = event.target.closest('a[href]');
    if (!anchor || anchor.dataset.viewerOriginal) return;
    const media = LearningViewer.video(anchor.href,course.videos,location.href);
    const key = LearningViewer.sourceKey(anchor.href,course.sources,location.href);
    if (!media && !key) return;
    event.preventDefault();
    open(anchor,media,key);
  });
}
