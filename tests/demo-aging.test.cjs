'use strict';
const assert=require('node:assert/strict');
const {agingReport,exportAgingCsv}=require('../demo-aging.js');
const properties=[{id:1,name:'عمارة ألف'},{id:2,name:'=Unsafe'}];
const units=[{id:11,propertyId:1},{id:22,propertyId:2}];
const contracts=[{id:111,unitId:11},{id:222,unitId:22}];
const dues=[
 {id:1,contractId:111,date:'2027-03-30',amount:10},
 {id:2,contractId:111,date:'2027-03-01',amount:20},
 {id:3,contractId:111,date:'2027-01-31',amount:30},
 {id:4,contractId:111,date:'2026-12-31',amount:40},
 {id:5,contractId:222,date:'2027-03-31',amount:50},
 {id:6,contractId:222,date:'2027-04-01',amount:60}
];
const payments=[
 {dueId:1,date:'2027-03-31',amount:4},
 {dueId:2,date:'2027-04-01',amount:20},
 {dueId:3,date:'2027-03-31',amount:50},
 {dueId:4,date:'2027-04-02',amount:40}
];
const report=agingReport(properties,units,contracts,dues,payments,{asOf:'2027-03-31'});
assert.deepEqual(report.totals,{
 days1to30:2600,days31to60:0,days61to90:0,over90:4000,totalCents:6600
});
assert.equal(report.details.length,3);
assert.deepEqual(report.details.map(x=>[x.dueId,x.daysLate,x.outstandingCents]),[
 [1,1,600],[2,30,2000],[4,90,4000]
]);
// A due dated on the report day is not overdue, and a future payment is excluded.
assert.ok(!report.details.some(x=>x.dueId===5||x.dueId===6));
const one=agingReport(properties,units,contracts,dues,payments,{asOf:'2027-03-31',propertyId:1});
assert.equal(one.rows.length,1);
assert.equal(one.totals.totalCents,6600);
const csv=exportAgingCsv(report);
assert.ok(csv.startsWith('\uFEFF'));
assert.ok(csv.includes('"\'=Unsafe"'));
assert.equal(csv.trimEnd().split('\r\n').length,4);
assert.throws(()=>agingReport(properties,units,contracts,dues,payments,{asOf:'2027-02-30'}),/تاريخ/);
console.log('PASS: aging buckets, cutoff, overpayment, property isolation and CSV safety');
