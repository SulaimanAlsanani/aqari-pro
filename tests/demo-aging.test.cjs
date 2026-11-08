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
 days1to30:2600,days31to60:0,days61to90:4000,over90:0,totalCents:6600
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
const {agingDetails,exportAgingDetailsCsv}=require('../demo-aging.js');
const linkedUnits=[{id:11,propertyId:1,code:'=U-101'},{id:22,propertyId:2,code:'B-202'}];
const linkedContracts=[{id:111,unitId:11,tenantId:1},{id:222,unitId:22,tenantId:2}];
const tenants=[{id:1,name:'=SUM(A1:A2)'},{id:2,name:'مستأجر ثاني'}];
const linked=agingDetails(report,properties,linkedUnits,linkedContracts,tenants,dues);
assert.equal(linked.length,3);
assert.deepEqual(linked.map(d=>d.dueId),[4,2,1]);
assert.equal(linked[0].contractId,111);
assert.equal(linked[0].unitCode,'=U-101');
assert.equal(linked[0].tenantName,'=SUM(A1:A2)');
assert.equal(linked.reduce((sum,d)=>sum+d.outstandingCents,0),report.totals.totalCents);
const detailCsv=exportAgingDetailsCsv(linked);
assert.ok(detailCsv.startsWith('\uFEFF'));
assert.ok(detailCsv.includes('"\'=SUM(A1:A2)"'));
assert.ok(detailCsv.includes('"\'=U-101"'));
assert.ok(detailCsv.includes('"2026-12-31"'));
assert.ok(detailCsv.includes('"90"'));
assert.ok(detailCsv.includes('"40.00"'));
assert.equal(detailCsv.trimEnd().split('\r\n').length,4);
assert.equal(exportAgingDetailsCsv([]).trimEnd().split('\r\n').length,1);
assert.deepEqual(agingDetails(one,properties,linkedUnits,linkedContracts,tenants,dues).map(d=>d.propertyId),['1','1','1']);
assert.throws(()=>agingDetails(report,properties,linkedUnits,linkedContracts,[],dues),/علاقات قسط/);
// Exact bucket boundaries: 30/31, 60/61 and 90/91 days.
const boundaryAges=[30,31,60,61,90,91];
const dayMs=86400000;
const asOfMs=Date.parse('2027-03-31T00:00:00.000Z');
const boundaryDues=boundaryAges.map((age,index)=>({
 id:100+index,contractId:111,
 date:new Date(asOfMs-age*dayMs).toISOString().slice(0,10),amount:1
}));
const boundaryReport=agingReport(properties,units,contracts,boundaryDues,[],{asOf:'2027-03-31'});
assert.deepEqual(boundaryReport.details.map(x=>[x.daysLate,x.bucket]),[
 [30,'days1to30'],[31,'days31to60'],[60,'days31to60'],
 [61,'days61to90'],[90,'days61to90'],[91,'over90']
]);
assert.deepEqual(boundaryReport.totals,{
 days1to30:100,days31to60:200,days61to90:200,over90:100,totalCents:600
});
assert.equal(boundaryReport.rows.reduce((sum,row)=>sum+row.totalCents,0),600);
const {filterAgingDetails,sumAgingDetailsCents}=require('../demo-aging.js');
assert.deepEqual(filterAgingDetails(linked,{tenant:' sum(a1:a2) '}).map(x=>x.dueId),[4,2,1]);
assert.deepEqual(filterAgingDetails(linked,{unit:'u-101'}).map(x=>x.dueId),[4,2,1]);
assert.deepEqual(filterAgingDetails(linked,{bucket:'days1to30'}).map(x=>x.dueId),[2,1]);
assert.deepEqual(filterAgingDetails(linked,{bucket:'days61to90'}).map(x=>x.dueId),[4]);
assert.deepEqual(filterAgingDetails(linked,{tenant:'missing'}),[]);
assert.equal(sumAgingDetailsCents(filterAgingDetails(linked,{bucket:'days1to30'})),2600);
assert.equal(sumAgingDetailsCents([]),0);
assert.equal(exportAgingDetailsCsv(filterAgingDetails(linked,{tenant:'missing'})).trimEnd().split('\\r\\n').length,1);
assert.throws(()=>filterAgingDetails(linked,{bucket:'unknown'}),/فئة/);
assert.throws(()=>sumAgingDetailsCents([{outstandingCents:0}]),/رصيد/);
console.log('PASS: aging buckets, cutoff, overpayment, property isolation and CSV safety');
