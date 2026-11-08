const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const context={};vm.runInNewContext(fs.readFileSync('followups.js','utf8'),context);
const x=context.AqariFollowUps;
const dues=[{id:1,amount:100},{id:2,amount:50}];
test('validates collection follow-up input and rejects invalid dates',()=>{
 const data={dueId:1,date:'2026-11-09',nextDate:'2026-11-12',channel:'اتصال',outcome:'وعد بالسداد',notes:'تم الاتفاق'};
 assert.equal(x.validate(data,dues),null);
 assert.match(x.validate({...data,dueId:9},dues),/غير موجود/);
 assert.match(x.validate({...data,nextDate:'2026-11-08'},dues),/يسبق/);
 assert.match(x.validate({...data,date:'2026-02-30'},dues),/غير صحيح/);
 assert.match(x.validate({...data,channel:'SMS API'},dues),/غير مدعومة/);
});
test('follow-up reminders omit settled dues and future follow-ups',()=>{
 const entries=[
  {dueId:1,nextDate:'2026-11-08'},
  {dueId:2,nextDate:'2026-11-07'},
  {dueId:1,nextDate:'2026-11-15'}
 ];
 const result=x.pending(entries,dues,[{dueId:1,amount:25},{dueId:2,amount:50}],'2026-11-09');
 assert.equal(result.length,1);
 assert.equal(result[0].dueId,1);
});
