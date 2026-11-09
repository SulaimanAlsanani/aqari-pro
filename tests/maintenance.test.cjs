const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const context={};vm.runInNewContext(fs.readFileSync('maintenance.js','utf8'),context);
const m=context.AqariMaintenance;
const data={properties:[{id:1,name:'أ'},{id:2,name:'ب'}],units:[{id:10,propertyId:1},{id:20,propertyId:2}],vendors:[{id:4,name:'المورد'}],expenses:[{id:1,propertyId:1,unitId:10,vendorId:4,category:'صيانة',amount:100.25,date:'2026-11-09'},{id:2,propertyId:2,category:'كهرباء',amount:40,date:'2026-11-09'}],tickets:[{id:1,propertyId:1,unitId:10,vendorId:4,cost:300,status:'مفتوح'},{id:2,propertyId:2,cost:50,status:'مكتمل'}]};
test('rejects unit belonging to a different property and unknown vendor',()=>{
 assert.match(m.recordError({propertyId:1,unitId:20,vendorId:4,cost:10},data,'ticket'),/لا تتبع/);
 assert.match(m.recordError({propertyId:1,vendorId:999,cost:10},data,'ticket'),/المورد/);
 assert.equal(m.recordError({propertyId:1,unitId:10,vendorId:4,cost:'10.50'},data,'ticket'),null);
});
test('rejects invalid precision and dates',()=>{
 assert.match(m.recordError({propertyId:1,amount:'1.234',date:'2026-11-09'},data,'expense'),/الهللة/);
 assert.match(m.recordError({propertyId:1,amount:'10',date:'2026-02-30'},data,'expense'),/تاريخ/);
});
test('does not double count ticket estimates as expenses',()=>{
 const a=m.performance(data,'all');
 assert.equal(a.spent,140.25);
 assert.equal(a.properties[0].spent,100.25);
 assert.equal(a.properties[0].estimated,300);
 assert.equal(a.properties[0].tickets,1);
 assert.equal(a.byVendor[0].amount,100.25);
 assert.equal(a.byUnit[0].amount,100.25);
 const b=m.performance(data,'2');
 assert.equal(b.spent,40);
 assert.equal(b.properties.length,1);
});
