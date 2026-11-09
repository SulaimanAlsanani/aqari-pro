const test=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm'),fs=require('node:fs');
const context={};vm.runInNewContext(fs.readFileSync('reconciliation.js','utf8'),context);
const {calculate,perProperty}=context.AqariReconciliation;
const base={
 properties:[{id:1,name:'العقار الأول'},{id:2,name:'العقار الثاني'}],
 units:[{id:10,propertyId:1},{id:20,propertyId:2}],
 contracts:[{id:100,unitId:10},{id:200,unitId:20}],
 dues:[{id:1000,contractId:100,amount:100.01},{id:2000,contractId:200,amount:50}],
 payments:[{id:1,dueId:1000,amount:25.01},{id:2,dueId:1000,amount:25},{id:3,dueId:2000,amount:50}],
 expenses:[{id:1,propertyId:1,amount:9.99},{id:2,propertyId:2,amount:10}]
};
test('exact reconciliation handles partial payment and property filter',()=>{
 const first=calculate(base,1);
 assert.equal(first.due,100.01);
 assert.equal(first.received,50.01);
 assert.equal(first.uncollected,50);
 assert.equal(first.expenses,9.99);
 assert.equal(first.net,40.02);
 assert.equal(first.balanced,true);
 const second=calculate(base,2);
 assert.equal(second.uncollected,0);
 assert.equal(second.received,50);
 assert.equal(calculate(base).received,100.01);
 assert.equal(perProperty(base).length,2);
});
test('detects orphan payment without wrongly attributing it to a property',()=>{
 const data={...base,payments:[...base.payments,{id:9,dueId:999,amount:10}]};
 const all=calculate(data);
 assert.equal(all.orphanPayments,1);
 assert.equal(all.balanced,false);
 assert.equal(calculate(data,1).orphanPayments,0);
});
test('flags overpayments and duplicate receipt identifiers',()=>{
 const data={...base,payments:[{id:4,dueId:1000,amount:110},{id:4,dueId:1000,amount:1}]};
 const result=calculate(data,1);
 assert.equal(result.overpaid,10.99);
 assert.equal(result.uncollected,0);
 assert.equal(result.balanced,false);
 assert.equal(result.issues.some(i=>i.type==='duplicate_payment_id'),true);
 assert.equal(result.issues.some(i=>i.type==='overpayment'),true);
});
test('invalid payment precision is not silently accepted',()=>{
 const result=calculate({...base,payments:[{id:1,dueId:1000,amount:12.345}]},1);
 assert.equal(result.invalidPayments,1);
 assert.equal(result.balanced,false);
});
