const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const context={};vm.runInNewContext(fs.readFileSync('contracts.js','utf8'),context);
const rules=context.AqariContracts;
test('valid dates and leap years',()=>{
 assert.equal(rules.validDate('2026-02-29'),false);
 assert.equal(rules.validDate('2024-02-29'),true);
 assert.equal(rules.nextDay('2026-12-31'),'2027-01-01');
});
test('conflict detection includes endpoints and respects actual move-out',()=>{
 const base={id:1,unitId:10,start:'2026-01-01',end:'2026-12-31'};
 assert.equal(rules.conflicts([base],{unitId:10,start:'2026-12-31',end:'2027-02-01'}),true);
 assert.equal(rules.conflicts([base],{unitId:10,start:'2027-01-01',end:'2027-12-31'}),false);
 assert.equal(rules.conflicts([{...base,vacatedAt:'2026-06-30'}],{unitId:10,start:'2026-07-01',end:'2026-12-31'}),false);
 assert.equal(rules.conflicts([base],{unitId:11,start:'2026-06-01',end:'2026-12-31'}),false);
});
test('vacating does not change official agreement and is only allowed once',()=>{
 const base={id:1,unitId:10,start:'2026-01-01',end:'2026-12-31'};
 assert.equal(rules.canVacate(base,'2026-04-01'),true);
 assert.equal(rules.canVacate(base,'2025-12-31'),false);
 assert.equal(rules.canVacate({...base,vacatedAt:'2026-04-01'},'2026-05-01'),false);
 assert.equal(rules.state({...base,vacatedAt:'2026-04-01'},'2026-06-01'),'تم الإخلاء');
});
test('expiry reminders exclude vacated contracts',()=>{
 const base={start:'2026-01-01',end:'2026-11-20'};
 const expiring=rules.expiring([{id:1,...base},{id:2,...base,vacatedAt:'2026-10-01'},{id:3,start:'2026-01-01',end:'2027-01-01'}],'2026-11-09',30);
 assert.equal(expiring.length,1);
 assert.equal(expiring[0].id,1);
});
