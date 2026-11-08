'use strict';
const assert=require('node:assert/strict');
const {buildExpenseCsv}=require('../demo-csv.js');
const properties=[{id:1,name:'عمارة, الشاطئ'},{id:2,name:'=DANGEROUS'}];
const records=[
 {propertyId:1,date:'2027-03-01',category:'صيانة',description:'إصلاح "المصعد", شهري',amount:10.25,invoiceReference:'INV-001'},
 {propertyId:2,date:'2027-03-02',category:'خدمات',description:'=SUM(A1:A2)',amount:20,invoiceReference:'@malicious'}
];
const csv=buildExpenseCsv(records,properties);
assert.ok(csv.startsWith('\uFEFF'));
assert.ok(csv.includes('"مرجع الفاتورة"'));
assert.ok(csv.includes('"عمارة, الشاطئ"'));
assert.ok(csv.includes('"إصلاح ""المصعد"", شهري"'));
assert.ok(csv.includes('"10.25"'));
assert.ok(csv.includes('"20.00"'));
assert.ok(csv.includes('"\'=DANGEROUS"'));
assert.ok(csv.includes('"\'=SUM(A1:A2)"'));
assert.ok(csv.includes('"\'@malicious"'));
assert.equal(csv.trimEnd().split('\r\n').length,3);
assert.equal(buildExpenseCsv([],properties).trimEnd().split('\r\n').length,1);
assert.throws(()=>buildExpenseCsv({},properties),/غير صالحة/);
assert.throws(()=>buildExpenseCsv([{amount:Infinity}],properties),/غير صالح/);
const {filterExpenseRows,sumExpenseCents}=require('../demo-csv.js');
const expenses=[
 {id:1,date:'2027-01-10',amount:10.25},
 {id:2,date:'2027-02-10',amount:20.50},
 {id:3,date:'2027-02-28',amount:30.01}
];
assert.deepEqual(filterExpenseRows(expenses,{from:'2027-02-10',to:'2027-02-28'}).map(x=>x.id),[2,3]);
assert.deepEqual(filterExpenseRows(expenses,{min:'20.50',max:'30.01'}).map(x=>x.id),[2,3]);
assert.deepEqual(filterExpenseRows(expenses,{from:'2027-02-10',to:'2027-02-28',min:'30.01'}).map(x=>x.id),[3]);
assert.deepEqual(filterExpenseRows(expenses,{from:'2027-03-01',to:'2027-01-01'}),[]);
assert.deepEqual(filterExpenseRows(expenses,{min:'30',max:'20'}),[]);
assert.equal(sumExpenseCents(filterExpenseRows(expenses,{from:'2027-02-10'})),5051);
assert.equal(sumExpenseCents([]),0);
assert.throws(()=>filterExpenseRows(expenses,{min:'0.001'}),/مبلغ مالي/);
assert.throws(()=>filterExpenseRows(expenses,{to:'bad-date'}),/نطاق التاريخ/);
const {groupExpenseByProperty,buildExpenseSummaryCsv}=require('../demo-csv.js');
const portfolioProperties=[{id:1,name:'عمارة ألف'},{id:2,name:'عمارة باء'}];
const sample=[
 {propertyId:2,amount:0.01},{propertyId:1,amount:0.10},
 {propertyId:1,amount:0.20},{propertyId:2,amount:1.99}
];
const grouped=groupExpenseByProperty(sample,portfolioProperties);
assert.equal(grouped.count,4);
assert.equal(grouped.totalCents,230);
assert.deepEqual(grouped.groups.map(g=>[g.propertyName,g.count,g.totalCents]),[
 ['عمارة ألف',2,30],['عمارة باء',2,200]
]);
const summaryCsv=buildExpenseSummaryCsv(grouped);
assert.ok(summaryCsv.startsWith('\uFEFF'));
assert.ok(summaryCsv.includes('"عمارة ألف","2","0.30"'));
assert.ok(summaryCsv.includes('"عمارة باء","2","2.00"'));
assert.ok(summaryCsv.includes('"الإجمالي الكلي","4","2.30"'));
assert.equal(summaryCsv.trimEnd().split('\r\n').length,4);
assert.equal(buildExpenseSummaryCsv(groupExpenseByProperty([],portfolioProperties)).trimEnd().split('\r\n').length,2);
assert.throws(()=>groupExpenseByProperty([{propertyId:999,amount:1}],portfolioProperties),/العقار/);
assert.throws(()=>groupExpenseByProperty([{propertyId:1,amount:'0.001'}],portfolioProperties),/مبلغ مالي/);
const {comparePropertyCashFlow,buildCashFlowComparisonCsv}=require('../demo-csv.js');
const cProperties=[{id:1,name:'عمارة ألف'},{id:2,name:'عمارة باء'}];
const cUnits=[{id:11,propertyId:1},{id:22,propertyId:2}];
const cContracts=[{id:111,unitId:11},{id:222,unitId:22}];
const cDues=[{id:1111,contractId:111},{id:2222,contractId:222}];
const cPayments=[
 {dueId:1111,date:'2027-02-01',amount:10.10},
 {dueId:1111,date:'2027-03-01',amount:20.20},
 {dueId:2222,date:'2027-02-15',amount:5.05}
];
const cExpenses=[
 {propertyId:1,date:'2027-02-12',amount:2.02},
 {propertyId:2,date:'2027-02-18',amount:7.07},
 {propertyId:1,date:'2027-04-01',amount:50.00}
];
const compare=options=>comparePropertyCashFlow(cProperties,cUnits,cContracts,cDues,cPayments,cExpenses,options);
const feb=compare({from:'2027-02-01',to:'2027-02-28'});
assert.deepEqual(feb.rows.map(x=>[x.propertyId,x.receiptsCents,x.expensesCents,x.netCents]),[['1',1010,202,808],['2',505,707,-202]]);
assert.deepEqual(feb.totals,{receiptsCents:1515,expensesCents:909,netCents:606});
const one=compare({propertyId:'2',from:'2027-02-01',to:'2027-02-28'});
assert.equal(one.rows.length,1);assert.equal(one.totals.netCents,-202);
const empty=compare({from:'2029-01-01',to:'2029-12-31'});
assert.equal(empty.totals.netCents,0);
// Regression: inclusive date boundaries and property scoping must agree with cent-based totals.
const firstDay=compare({from:'2027-02-01',to:'2027-02-01'});
assert.deepEqual(firstDay.rows.map(x=>[x.propertyId,x.receiptsCents,x.expensesCents,x.netCents]),[
 ['1',1010,0,1010],['2',0,0,0]
]);
const lastDay=compare({from:'2027-02-28',to:'2027-02-28'});
assert.deepEqual(lastDay.totals,{receiptsCents:0,expensesCents:0,netCents:0});
const march=compare({propertyId:'1',from:'2027-03-01',to:'2027-03-31'});
assert.deepEqual(march.rows.map(x=>[x.propertyId,x.receiptsCents,x.expensesCents,x.netCents]),[
 ['1',2020,0,2020]
]);
assert.deepEqual(march.totals,{receiptsCents:2020,expensesCents:0,netCents:2020});
const propertyOne=compare({propertyId:'1',from:'2027-02-01',to:'2027-02-28'});
assert.deepEqual(propertyOne.totals,{receiptsCents:1010,expensesCents:202,netCents:808});
assert.ok(!buildCashFlowComparisonCsv(propertyOne).includes('عمارة باء'));
assert.ok(buildCashFlowComparisonCsv(propertyOne).includes('"الإجمالي الكلي","10.10","2.02","8.08"'));
const comparisonCsv=buildCashFlowComparisonCsv(feb);
assert.ok(comparisonCsv.startsWith('\uFEFF'));
assert.ok(comparisonCsv.includes('"عمارة ألف","10.10","2.02","8.08"'));
assert.ok(comparisonCsv.includes('"عمارة باء","5.05","7.07","-2.02"'));
assert.ok(comparisonCsv.includes('"الإجمالي الكلي","15.15","9.09","6.06"'));
assert.equal(comparisonCsv.trimEnd().split('\r\n').length,4);
assert.throws(()=>compare({from:'not-date'}),/تاريخ/);
const {propertyCollectionKpis}=require('../demo-csv.js');
const kpis=options=>propertyCollectionKpis(cProperties,cUnits,cContracts,[
 {id:1111,contractId:111,date:'2027-02-01',amount:30.30},
 {id:2222,contractId:222,date:'2027-02-15',amount:10.10}
],cPayments,cExpenses,options);
const febKpis=kpis({from:'2027-02-01',to:'2027-02-28'});
assert.deepEqual(febKpis.rows.map(x=>[x.propertyId,x.dueCents,x.collectedDueCents,x.arrearsCents,x.collectionRate,x.operatingMargin]),[
 ['1',3030,1010,2020,33.33,80],
 ['2',1010,505,505,50,-40]
]);
assert.deepEqual([febKpis.totals.dueCents,febKpis.totals.collectedDueCents,febKpis.totals.arrearsCents,febKpis.totals.collectionRate],[4040,1515,2525,37.5]);
// Portfolio collection rate is weighted by due amounts, not an average of property rates.
assert.notEqual(febKpis.totals.collectionRate,
  (febKpis.rows[0].collectionRate + febKpis.rows[1].collectionRate) / 2);
assert.equal(febKpis.totals.collectionRate,37.5);
assert.equal(febKpis.totals.arrearsCents,
  febKpis.rows.reduce((sum,row)=>sum+row.arrearsCents,0));
assert.equal(febKpis.totals.dueCents,
  febKpis.rows.reduce((sum,row)=>sum+row.dueCents,0));
const isolatedKpis=kpis({from:'2027-02-01',to:'2027-02-28',propertyId:'2'});
assert.equal(isolatedKpis.rows.length,1);
assert.equal(isolatedKpis.totals.arrearsCents,505);
const zeroKpis=kpis({from:'2029-01-01',to:'2029-12-31'});
assert.equal(zeroKpis.totals.collectionRate,null);
assert.equal(zeroKpis.totals.operatingMargin,null);
const latePaid=kpis({from:'2027-02-01',to:'2027-03-31'});
assert.equal(latePaid.rows[0].collectedDueCents,3030);
assert.equal(latePaid.rows[0].arrearsCents,0);
console.log('PASS: expense CSV escaping, UTF-8 BOM, invoice fields and formula sanitization');
