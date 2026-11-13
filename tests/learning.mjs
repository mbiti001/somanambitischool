import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';
const context=vm.createContext({});vm.runInContext(readFileSync('public/math.js','utf8')+';globalThis.math=LearningMath;',context);const m=context.math;
const close=(a,b)=>assert.ok(Math.abs(a-b)<1e-7,`${a} != ${b}`);
let r=m.trade({quantity:100,buyPrice:20,sellPrice:21,buyFee:2,sellFee:2});close(r.cost,2040);close(r.proceeds,2058);close(r.profit,18);close(r.breakEven,20.816326530612244);
assert.throws(()=>m.trade({quantity:1.5,buyPrice:20,sellPrice:21,buyFee:2,sellFee:2}));assert.throws(()=>m.trade({quantity:1,buyPrice:20,sellPrice:21,buyFee:2,sellFee:100}));
r=m.yield({price:50,dividend:2,quantity:100});close(r.yield,4);close(r.income,200);assert.throws(()=>m.yield({price:0,dividend:2,quantity:100}));
r=m.growth({initial:10000,monthly:0,rate:5,years:2});close(r.value,11025);close(r.contributions,10000);close(r.change,1025);
r=m.growth({initial:1000,monthly:100,rate:0,years:1});close(r.value,2200);close(r.change,0);
r=m.growth({initial:10000,monthly:0,rate:-100,years:1});close(r.value,0);close(r.change,-10000);assert.throws(()=>m.growth({initial:1000,monthly:0,rate:5,years:1.5}));
const c=JSON.parse(readFileSync('private/legacy-investing-course.json','utf8'));assert.equal(c.lessons.length,18);assert.equal(Object.keys(c.videos).length,7);assert.equal(c.glossary.length,24);assert.deepEqual(c.lessons.slice(0,4).map(l=>l.correct),[0,1,2,2]);
for(const [i,l]of c.lessons.entries()){assert.equal(l.id,i);assert.ok(l.sections.length>=8);assert.equal(l.outcomes.length,3);assert.equal(l.answers.length,3);assert.ok(l.answers[l.correct]);assert.ok(c.videos[l.video]);assert.ok(l.sources.every(k=>c.sources[k]));assert.ok(l.example.text.length>100);}
for(const v of Object.values(c.videos))assert.match(v.id,/^[\w-]{11}$/);
const ctx=vm.createContext({});vm.runInContext(readFileSync('public/course.js','utf8')+';globalThis.result=learningCourse;',ctx);assert.equal(JSON.stringify(ctx.result),JSON.stringify(c));
console.log('PASS: fee costs, break-even price, dividend yield, monthly contributions, positive/zero/negative growth, invalid inputs, 18-class structure, stable original IDs, video references, and matching browser/server catalogues.');

// Regression: finite inputs must never produce Infinity or NaN in the UI.
assert.throws(()=>m.trade({quantity:100,buyPrice:1e308,sellPrice:1e308,buyFee:2,sellFee:2}));
assert.throws(()=>m.yield({price:1e-308,dividend:1e308,quantity:100}));
assert.throws(()=>m.yield({price:Number.MIN_VALUE,dividend:1,quantity:1}));
assert.throws(()=>m.trade({quantity:1000001,buyPrice:20,sellPrice:21,buyFee:0,sellFee:0}));
assert.throws(()=>m.yield({price:50,dividend:2,quantity:1000001}));
assert.throws(()=>m.trade({quantity:1,buyPrice:NaN,sellPrice:20,buyFee:0,sellFee:0}));
assert.throws(()=>m.yield({price:50,dividend:-1,quantity:100}));
r=m.trade({quantity:100,buyPrice:20,sellPrice:0,buyFee:0,sellFee:0});close(r.profit,-2000);
r=m.trade({quantity:100,buyPrice:20,sellPrice:20,buyFee:0,sellFee:0});close(r.profit,0);
r=m.yield({price:50,dividend:0,quantity:100});close(r.yield,0);close(r.income,0);
r=m.growth({initial:10000,monthly:0,rate:-5,years:2});close(r.value,9025);
assert.throws(()=>m.growth({initial:1000,monthly:NaN,rate:5,years:2}));
assert.throws(()=>m.growth({initial:1000,monthly:0,rate:5,years:51}));
console.log('PASS: very large and tiny values, overflow protection, share limits, blank/invalid amounts, negative dividends, total-loss and break-even trades, zero dividends, negative growth, and duration limits.');

const studyCtx=vm.createContext({});vm.runInContext(readFileSync('public/study.js','utf8')+';this.study=Study;',studyCtx);
for(const lesson of c.lessons){
 const words=(lesson.sections.map(s=>s.text).join(' ')+' '+lesson.example.text).trim().split(/\s+/u).length;
 assert.ok(words>=1000);assert.equal(lesson.wordCount,words);assert.equal(lesson.readingMinutes,Math.ceil(words/200));assert.equal(lesson.questions.length,5);
 const answers=lesson.questions.map(q=>q.correct);assert.equal(studyCtx.study.grade(lesson.questions,answers).passed,true);
 assert.equal(studyCtx.study.grade(lesson.questions,answers.slice(0,4)).passed,false);
 answers[4]=(answers[4]+1)%3;assert.equal(studyCtx.study.grade(lesson.questions,answers).passed,false);
 for(const item of [...lesson.sections,...lesson.questions,lesson.example]){assert.ok(item.sources.length);assert.ok(item.sources.every(k=>c.sources[k]));}
 for(const q of lesson.questions){assert.ok(q.explanation.length>30);assert.ok(q.answers[q.correct]);}
}
assert.equal(c.readings.length,7);for(const source of Object.values(c.sources))for(const key of ['author','publisher','access','license','checked'])assert.ok(source[key]);
console.log('PASS: 18 substantial readings, 90 explained questions, strict full-quiz grading, individual references, seven credited shelf resources.');

assert.equal(c.lessons.reduce((n,l)=>n+l.units.reduce((sum,u)=>sum+u.questions.length,0),0),162);
for(const lesson of c.lessons){assert.equal(lesson.units.length,3);for(const unit of lesson.units){assert.ok(unit.readings.length);assert.ok(unit.readings.every(k=>c.sources[k]));}}
console.log('PASS: 54 learning sections, 162 checkpoint questions and per-section assigned readings.');
