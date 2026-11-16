'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
require('../backup.js');
const backup=globalThis.AqariBackup;
const valid=()=>({
 owners:[{id:1,name:'Demo'}],properties:[{id:2,ownerId:1,name:'Property'}],
 units:[{id:3,propertyId:2,code:'101',area:100}],
 tenants:[{id:4,name:'Tenant'}],
 contracts:[{id:5,unitId:3,tenantId:4,total:12000}],
 dues:[{id:6,contractId:5,amount:1000}],
 payments:[{id:7,dueId:6,amount:400}],
 expenses:[{id:8,propertyId:2,amount:50}],
 vendors:[],tickets:[],documents:[],inspections:[],tasks:[],
 payouts:[],audit:[],ejarLogs:[],followUps:[]
});
test('round trips single-owner records without discarding collections',()=>{
 const data=valid();
 assert.deepEqual(backup.parse(JSON.stringify(data)),data);
});
test('rejects corrupt JSON and absent collections without touching source',()=>{
 assert.throws(()=>backup.parse('{bad'),/JSON/);
 const data=valid();delete data.payments;
 assert.throws(()=>backup.parse(JSON.stringify(data)),/payments/);
});
test('rejects orphan receipts and duplicate financial identifiers',()=>{
 const data=valid();data.payments[0].dueId=900;
 assert.throws(()=>backup.validate(data),/payments.dueId/);
 const d=valid();d.dues.push({...d.dues[0]});
 assert.throws(()=>backup.validate(d),/رقم سجل مكرر/);
});
test('rejects incompatible multi-owner and invalid financial values',()=>{
 const data=valid();data.owners.push({id:9,name:'Other'});
 assert.throws(()=>backup.validate(data),/مالك واحد/);
 const invalid=valid();invalid.payments[0].amount=-1;
 assert.throws(()=>backup.validate(invalid),/قيمة مالية غير صالحة/);
});
test('rejects oversized backup',()=>{
 assert.throws(()=>backup.parse(' '.repeat(backup.MAX_BYTES+1)),/8 ميجابايت/);
});
