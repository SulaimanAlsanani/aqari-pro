'use strict';
const assert=require('node:assert/strict');
const {collections,validate}=require('../demo-backup.js');
const blank=()=>Object.fromEntries(collections.map(key=>[key,[]]));
const good=blank();good.owners=[{id:1,name:'Demo'}];
good.properties=[{id:1,ownerId:1,name:'Building'}];
good.units=[{id:1,propertyId:1,code:'101'}];
good.tenants=[{id:1,name:'Demo tenant'}];
good.contracts=[{id:1,unitId:1,tenantId:1,start:'2027-01-01',end:'2027-12-31',total:1000,installments:1}];
good.dues=[{id:1,contractId:1,date:'2027-01-01',amount:1000}];
good.payments=[{id:1,dueId:1,amount:250},{id:2,dueId:1,amount:750}];
good.expenses=[{id:1,propertyId:1,amount:50.01}];
good.tickets=[{id:1,propertyId:1}];
good.documents=[{id:1,propertyId:1}];
good.inspections=[{id:1,propertyId:1,unitId:1}];
assert.equal(validate(good).contracts.length,1);
assert.equal(validate(good).payments.length,2);
const corrupted=edit=>{const x=JSON.parse(JSON.stringify(good));edit(x);return x};
assert.throws(()=>validate(corrupted(x=>x.payments[1].amount=750.01)),/يتجاوز القسط/);
assert.throws(()=>validate(corrupted(x=>x.payments[1].dueId=999)),/القسط/);
assert.throws(()=>validate(corrupted(x=>x.units[0].propertyId=999)),/العقار/);
assert.throws(()=>validate(corrupted(x=>x.contracts[0].tenantId=999)),/المستأجر/);
assert.throws(()=>validate(corrupted(x=>x.properties[0].ownerId=2)),/مالك العقار/);
assert.throws(()=>validate(corrupted(x=>x.payments.push({id:1,dueId:1,amount:1}))),/معرف مكرر/);
assert.throws(()=>validate(corrupted(x=>x.expenses[0].amount=5.001)),/مبلغ مالي/);
assert.throws(()=>validate(corrupted(x=>x.owners.push({id:2,name:'Other'}))),/لمالك واحد/);
assert.throws(()=>validate(corrupted(x=>delete x.dues)),/غير صالح/);
assert.throws(()=>validate(JSON.parse('{"__proto__":{},"owners":[]}')),/غير صالح/);
assert.throws(()=>validate(corrupted(x=>x.inspections[0].unitId=999)),/الوحدة/);
assert.throws(()=>validate(corrupted(x=>{
 x.properties.push({id:2,ownerId:1,name:'Building Two'});
 x.units.push({id:2,propertyId:2,code:'202'});
 x.inspections[0].unitId=2;
})),/عقار مختلف/);
assert.throws(()=>validate(corrupted(x=>x.units.push({id:2,propertyId:1,code:' 101  '}))),/رقم وحدة مكرر/);
assert.throws(()=>validate(corrupted(x=>x.contracts.push({
 id:2,unitId:1,tenantId:1,start:'2027-05-01',end:'2028-04-30',total:100,installments:1
}))),/تداخل عقود/);
assert.equal(validate(corrupted(x=>x.contracts.push({
 id:2,unitId:1,tenantId:1,start:'2028-01-01',end:'2028-12-31',total:100,installments:1
}))).contracts.length,2);
assert.throws(()=>validate(corrupted(x=>x.contracts[0].end='2026-12-31')),/تواريخ العقد/);
assert.throws(()=>validate(corrupted(x=>x.dues[0].date='2028-01-01')),/خارج مدة العقد/);
assert.throws(()=>validate(corrupted(x=>x.dues[0].amount=1000.01)),/يتجاوز قيمة العقد/);
assert.throws(()=>validate(corrupted(x=>x.dues[0].amount=999.99)),/لا يساوي قيمة العقد/);
assert.throws(()=>validate(corrupted(x=>{
 x.contracts[0].installments=2;
 x.dues[0].amount=800;
 x.dues.push({id:2,contractId:1,date:'2027-07-01',amount:100});
 x.dues.push({id:3,contractId:1,date:'2027-10-01',amount:100});
})),/يتجاوز جدول العقد/);
console.log('PASS: JSON demo restore validates owner scope, unique IDs, foreign keys and receipts');
