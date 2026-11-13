import {readFileSync} from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const c=JSON.parse(readFileSync('public/course.json','utf8')),sandbox=vm.createContext({});vm.runInContext(readFileSync('public/mastery.js','utf8')+readFileSync('public/campus.js','utf8')+';this.C=CourseCampus;this.M=LearningMastery;',sandbox);const {C,M}=sandbox;
const fresh=()=>({completed:[],unitPasses:{}});let state=fresh();const snapshot=JSON.stringify(state),modules=C.modules(c.lessons,state);
assert.deepEqual(Array.from(modules,m=>m.items.length),[4,4,4,6]);assert.deepEqual(Array.from(modules,m=>m.total),[12,12,12,18]);assert.deepEqual(Array.from(modules,m=>m.available),[true,false,false,false]);assert.equal(JSON.stringify(state),snapshot);
assert.deepEqual(Array.from(modules.flatMap(m=>m.items),l=>l.id),[0,1,3,4,2,5,6,7,8,9,10,11,12,13,14,15,16,17]);
for(const lesson of M.order(c.lessons)){for(const [i,u] of lesson.units.entries()){
 const route=C.route(lesson.id,u.id,'quiz'),parsed=C.parse(route);assert.equal(parsed.lesson,lesson.id);assert.equal(parsed.unit,u.id);assert.equal(parsed.pane,'quiz');
 assert(M.available(lesson.id,state,c.lessons));assert(M.sectionAvailable(lesson,i,state));const bad=u.questions.map(q=>(q.correct+1)%3);assert.throws(()=>M.apply(state,{lesson:lesson.id,unit:u.id,answers:bad},c.lessons));M.apply(state,{lesson:lesson.id,unit:u.id,answers:u.questions.map(q=>q.correct)},c.lessons);
 if(c.lessons.filter(x=>x.module===lesson.module).every(x=>state.completed.includes(x.id))){state.modulePasses??=[];const mi=M.moduleIndex(lesson,c.lessons);if(!state.modulePasses.includes(mi))state.modulePasses.push(mi);}
 const total=C.modules(c.lessons,state).reduce((n,m)=>n+m.done,0);assert.equal(total,Object.values(state.unitPasses).reduce((n,p)=>n+p.length,0));
 }}
assert.equal(C.modules(c.lessons,state).reduce((n,m)=>n+m.done,0),54);assert.equal(C.modules(c.lessons,{completed:[0],unitPasses:{}})[0].done,3);assert.equal(C.parse('#module/3').module,3);assert.equal(C.parse('').page,'home');assert.equal(C.parse('#course/0/understand/<script>').page,'course/0/understand/<script>');
console.log('PASS: four modules, stable chapter order, all 54 deep-link paths and saved-section counts, partial/legacy progress, no state mutation, existing gates.');
