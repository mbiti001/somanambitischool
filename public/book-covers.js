'use strict';
// Original publisher/print-partner thumbnails identify the assigned books. The
// chapter assignments remain in the reading list; covers never award study credit.
window.BookCovers=(()=>{
 const escape=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
 const printSource='https://he.kendallhunt.com/openstax';
 const books={
  'writing-guide':{title:'Writing Guide with Handbook',image:'writing-guide.jpg',publisher:'OpenStax, Rice University',credit:'Original cover · OpenStax / Kendall Hunt',source:printSource},
  'introduction-philosophy':{title:'Introduction to Philosophy',image:'introduction-philosophy.jpg',publisher:'OpenStax, Rice University',credit:'Original cover · OpenStax / Kendall Hunt',source:printSource},
  'college-success':{title:'College Success',image:'college-success.jpg',publisher:'OpenStax, Rice University',credit:'Original cover · OpenStax / Kendall Hunt',source:printSource},
  entrepreneurship:{title:'Entrepreneurship',image:'entrepreneurship.jpg',publisher:'OpenStax, Rice University',credit:'Original cover · OpenStax / Kendall Hunt',source:printSource},
  'principles-finance':{title:'Principles of Finance',image:'principles-finance.jpg',publisher:'OpenStax, Rice University',credit:'Original cover · OpenStax / Kendall Hunt',source:printSource},
  speaking:{title:'Exploring Public Speaking, edition 4.2 (2023)',image:'exploring-public-speaking.jpg',publisher:'Barbara G. Tucker and contributors · OpenALG / Dalton State',credit:'Original PDF cover · OpenALG',source:'https://alg.manifoldapp.org/projects/exploring-public-speaking'}
 };
 function identify(url){
  if(['openstax.org','www.openstax.org'].includes(url.hostname))return Object.keys(books).find(key=>key!=='speaking'&&(url.pathname==='/books/'+key||url.pathname.startsWith('/books/'+key+'/')))||null;
  if(url.hostname==='alg.manifoldapp.org'&&/^\/(?:read|projects)\/exploring-public-speaking(?:[\/-]|$)/.test(url.pathname))return 'speaking';
  return null;
 }
 function shelfHTML(sources,ids){
  if(!sources||typeof sources!=='object'||!Array.isArray(ids))return '';
  const selected=[],seen=new Set();
  for(const id of ids){
   if(typeof id!=='string'||!Object.hasOwn(sources,id)||typeof sources[id]?.url!=='string')continue;
   let url;try{url=new URL(sources[id].url);}catch{continue;}if(url.protocol!=='https:')continue;
   const key=identify(url);if(!key||seen.has(key))continue;seen.add(key);selected.push({id,url:url.href,book:books[key]});
  }
  if(!selected.length)return '';
  return '<section class="book-covers" aria-label="Assigned textbook covers"><div class="book-covers__heading"><h3>Your assigned textbooks</h3><p>Open a book at its assigned reading. The exact sections, pages and study guidance remain in the reading list below.</p></div><ul class="book-covers__shelf">'+selected.map(({id,url,book})=>'<li class="book-covers__book"><a class="book-covers__image" href="'+escape(url)+'" data-term-resource="'+escape(id)+'" aria-label="Read assigned sections of '+escape(book.title)+'"><img src="/book-covers/'+escape(book.image)+'" width="96" height="144" alt="Cover of '+escape(book.title)+'" loading="lazy" decoding="async"></a><div class="book-covers__details"><a class="book-covers__title" href="'+escape(url)+'" data-term-resource="'+escape(id)+'">'+escape(book.title)+'</a><p>'+escape(book.publisher)+'</p><a class="book-covers__credit" href="'+escape(book.source)+'" target="_blank" rel="noopener noreferrer">'+escape(book.credit)+' ☞</a></div></li>').join('')+'</ul><p class="book-covers__notice">Publisher cover thumbnails identify the linked books; no publisher endorsement is implied. Consult each publisher’s licensing information before reusing their material.</p></section>';
 }
 return Object.freeze({shelfHTML});
})();
