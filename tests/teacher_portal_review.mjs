/* Teacher entry must describe actual server clearance; stale owner responses
 * must not populate another student's view. No browser/network is used here. */
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync(process.argv[2]||new URL('../public/teacher.js',import.meta.url),'utf8');
function fixture(){const requests=[];const context={window:{},user:{verified:true},fetch:async()=>{let resolve;const promise=new Promise(r=>resolve=r);requests.push(resolve);return promise;}};vm.runInNewContext(source,context,{filename:'teacher.js'});return {api:context.window.TeacherDesk,requests};}
const root=()=>({innerHTML:'',replaceChildren(){this.innerHTML='';}});
const reply=d=>({ok:true,status:200,json:async()=>d});
{
 const {api,requests}=fixture(),r=root(),pending=api.mount(r,'student@example.invalid');requests[0](reply({teacherCleared:false,name:'Student'}));await pending;
 assert.match(r.innerHTML,/Teacher access unavailable/);assert(!r.innerHTML.includes('Open class office'));
}
{
 const {api,requests}=fixture(),r=root(),pending=api.mount(r,'teacher@example.invalid');requests[0](reply({teacherCleared:true,superAdmin:false,name:'Teacher <unsafe>'}));await pending;
 assert.match(r.innerHTML,/Open class office/);assert(!r.innerHTML.includes('School administration'));assert(r.innerHTML.includes('Teacher &lt;unsafe&gt;'));
}
{
 const {api,requests}=fixture(),old=root(),current=root(),oldPending=api.mount(old,'owner@example.invalid');api.clear();const newPending=api.mount(current,'student@example.invalid');requests[1](reply({teacherCleared:false,name:'Student'}));await newPending;requests[0](reply({teacherCleared:true,superAdmin:true,name:'Private Owner Name'}));await oldPending;
 assert.equal(old.innerHTML,'');assert(!current.innerHTML.includes('Private Owner Name'));assert(!current.innerHTML.includes('School administration'));assert.match(current.innerHTML,/Teacher access unavailable/);
}
console.log('PASS: unapproved students receive no teacher actions; approved class teachers receive scoped entry; names are escaped and late owner responses cannot populate a new student session.');
