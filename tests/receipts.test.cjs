const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const context={};vm.runInNewContext(fs.readFileSync('receipts.js','utf8'),context);
const {validatePayment,receipt}=context.AqariReceipts;
const due={id:4,amount:100};
const base={amount:'25.50',date:'2026-11-09',method:'تحويل بنكي',reference:'TR-9'};
test('refuses duplicate references for the same due and method',()=>{
 const prior=[{id:1,dueId:4,amount:25.50,method:'تحويل بنكي',reference:'TR-9'}];
 assert.match(validatePayment(base,due,prior),/مستخدم مسبقًا/);
 assert.match(validatePayment({...base,reference:'tr-9'},due,prior),/مستخدم مسبقًا/);
 assert.equal(validatePayment({...base,reference:'TR-10'},due,prior),null);
 assert.equal(validatePayment({...base,method:'نقدي'},due,prior),null);
});
test('rejects overpayment, invalid dates and imprecise amounts',()=>{
 const prior=[{id:1,dueId:4,amount:90,method:'نقدي',reference:'A'}];
 assert.match(validatePayment({...base,amount:'10.01'},due,prior),/يتجاوز/);
 assert.match(validatePayment({...base,amount:'0'},due,[]),/غير صالح/);
 assert.match(validatePayment({...base,amount:'1.999'},due,[]),/غير صالح/);
 assert.match(validatePayment({...base,date:'2026-02-30'},due,[]),/غير صالح/);
 assert.equal(validatePayment({...base,amount:'10.00'},due,prior),null);
});
test('receipt joins tenant, unit, property and owner without changing payments',()=>{
 const payment={id:19,dueId:4,amount:25.50,date:'2026-11-09',method:'نقدي',reference:'A'};
 const data={dues:[{id:4,contractId:3}],contracts:[{id:3,tenantId:2,unitId:5}],tenants:[{id:2,name:'مستأجر تجريبي'}],units:[{id:5,propertyId:8,code:'101'}],properties:[{id:8,ownerId:1,name:'العقار التجريبي'}],owners:[{id:1,name:'مالك تجريبي'}]};
 const original=JSON.stringify(payment),r=receipt(payment,data);
 assert.equal(r.tenant,'مستأجر تجريبي');
 assert.equal(r.property,'العقار التجريبي');
 assert.equal(r.owner,'مالك تجريبي');
 assert.equal(r.amount,25.50);
 assert.equal(JSON.stringify(payment),original);
});
