import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';
const context=vm.createContext({URL});
vm.runInContext(readFileSync('public/viewer.js','utf8')+';globalThis.viewer=LearningViewer;',context);
const viewer=context.viewer, course=JSON.parse(readFileSync('private/legacy-investing-course.json','utf8'));
for(const item of Object.values(course.videos)){
  const video=viewer.video('https://www.youtube.com/watch?v='+item.id+'&t=90s',course.videos,'https://somanambiti.com/');
  const embed=new URL(video.embed);
  assert.equal(embed.hostname,'www.youtube-nocookie.com');
  assert.equal(embed.searchParams.get('start'),'90');
  assert.equal(embed.searchParams.get('playsinline'),'1');
  assert.equal(embed.searchParams.get('autoplay'),null);
}
assert.equal(viewer.video('https://www.youtube.com.evil.test/watch?v=p7HKvqRI_Bo',course.videos,'https://somanambiti.com/'),null);
assert.equal(viewer.video('https://www.youtube.com/watch?v=unknown1234',course.videos,'https://somanambiti.com/'),null);
assert.equal(viewer.video('javascript:alert(1)',course.videos,'https://somanambiti.com/'),null);
for(const [key,item]of Object.entries(course.sources))assert.equal(viewer.sourceKey(item.url,course.sources,'https://somanambiti.com/'),key);
assert.equal(viewer.sourceKey('https://licensees.cma.or.ke.evil.test/',course.sources,'https://somanambiti.com/'),null);
assert.equal(viewer.sourceKey('javascript:alert(1)',course.sources,'https://somanambiti.com/'),null);
assert.equal(viewer.canFrame('licences'),false);
assert.equal(viewer.canFrame('cdsc'),false);
assert.equal(viewer.canFrame('cma'),true);
console.log('PASS: all seven videos, chapter timing, no autoplay, inline playback, known-publisher routing and external-source restrictions.');

assert.equal(viewer.canFrame('book-accounting',course.sources),false);
assert.equal(viewer.canFrame('accounting-web',course.sources),true);
assert.equal(viewer.canFrame('book-management',course.sources),false);
for(const source of Object.values(course.sources)){if(source.alternative){assert.ok(course.sources[source.alternative]);assert.notEqual(source.url,course.sources[source.alternative].url);}}
console.log('PASS: PDF avoids blank embedded preview, HTML alternative is supported, and alternative references exist with distinct publisher URLs.');
