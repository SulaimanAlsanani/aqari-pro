const test=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const context={};vm.runInNewContext(fs.readFileSync('statements.js','utf8'),context);
const {build}=context.AqariStatements;
const data={
 contracts:[{id:11,tenantId:7,unitId:31},{id:12,tenantId:7,unitId:32},{id:13,tenantId:8,unitId:31}],
 units:[{id:31,propertyId:1},{id:32,propertyId:2}],
 dues:[{id:1,contractId:11,date:'2026-01-01',amount:100},{id:2,contractId:12,date:'2026-02-01',amount:200},{id:3,contractId:13,date:'2026-02-01',amount:300}],
 payments:[{id:10,dueId:1,date:'2026-01-02',amount:20.01},{id:11,dueId:1,date:'2026-01-03',amount:79.99},{id:12,dueId:2,date:'2026-02-02',amount:50},{id:13,dueId:3,date:'2026-02-03',amount:300}]
};
test('statement isolates tenants, including partial and fully settled dues',()=>{
 const a=build(7,data,'all');
 assert.equal(a.rows.length,2);
 assert.equal(a.totalDue,300);
 assert.equal(a.totalPaid,150);
 assert.equal(a.outstanding,150);
 assert.equal(a.rows[0].outstanding,0);
 assert.equal(a.rows[1].outstanding,150);
});
test('property selection constrains both dues and receipts',()=>{
 const a=build(7,data,'1');
 assert.equal(a.rows.length,1);
 assert.equal(a.totalDue,100);
 assert.equal(a.totalPaid,100);
 assert.equal(a.outstanding,0);
 assert.equal(a.payments.length,2);
});
test('unknown tenant returns an empty statement',()=>{
 const a=build(999,data,'all');
 assert.equal(a.totalDue,0);assert.equal(a.totalPaid,0);assert.equal(a.rows.length,0);
});
test('invalid precision is not silently rounded',()=>{
 assert.throws(()=>build(7,{...data,dues:[{id:1,contractId:11,date:'2026-01-01',amount:1.234}]},'all'));
});
