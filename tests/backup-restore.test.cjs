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

test('rejects forged money strings, fractions and payments above the installment',()=>{
 const invalid=valid();
 invalid.dues[0].amount='1e3';
 assert.throws(()=>backup.validate(invalid),/قيمة مالية غير صالحة/);
 const precise=valid();
 precise.payments[0].amount='1.234';
 assert.throws(()=>backup.validate(precise),/قيمة مالية غير صالحة/);
 const overpaid=valid();
 overpaid.payments.push({id:9,dueId:6,amount:700});
 assert.throws(()=>backup.validate(overpaid),/المقبوضات تتجاوز/);
});
test('rejects stale optional unit and vendor relationships',()=>{
 const invalid=valid();
 invalid.expenses[0].unitId=9999;
 assert.throws(()=>backup.validate(invalid),/مرجع وحدة غير صالح/);
 const orphanVendor=valid();
 orphanVendor.expenses[0].vendorId=9999;
 assert.throws(()=>backup.validate(orphanVendor),/مرجع مورد غير صالح/);
});

test('full backup package validates attachment content and known parent records',()=>{
 const data=valid();
 const file={
  id:'att-1',record:'properties:2',name:'test.txt',type:'text/plain',
  size:4,created:'2026-11-16T10:00:00.000Z',data:Buffer.from('test').toString('base64')
 };
 const packageText=JSON.stringify({format:'aqari-pro-backup-v2',records:data,attachments:[file]});
 const parsed=backup.parsePackage(packageText);
 assert.deepEqual(parsed.records,data);
 assert.deepEqual(parsed.attachments,[file]);
 assert.equal(parsed.legacy,false);
 const legacy=backup.parsePackage(JSON.stringify(data));
 assert.equal(legacy.legacy,true);
 assert.deepEqual(legacy.attachments,[]);
});
test('full backup rejects corrupted attachments, duplicate IDs and orphan file references',()=>{
 const source={
  format:'aqari-pro-backup-v2',records:valid(),attachments:[{
   id:'att-1',record:'properties:2',name:'safe.txt',type:'text/plain',
   size:4,created:'2026-11-16T10:00:00.000Z',data:Buffer.from('test').toString('base64')
  }]
 };
 const damaged=structuredClone(source);damaged.attachments[0].size=5;
 assert.throws(()=>backup.parsePackage(JSON.stringify(damaged)),/حجم المرفق/);
 const orphan=structuredClone(source);orphan.attachments[0].record='properties:999';
 assert.throws(()=>backup.parsePackage(JSON.stringify(orphan)),/المرفق غير مرتبط/);
 const duplicates=structuredClone(source);duplicates.attachments.push({...duplicates.attachments[0]});
 assert.throws(()=>backup.parsePackage(JSON.stringify(duplicates)),/معرّف مرفق مكرر/);
});
