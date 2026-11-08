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
console.log('PASS: expense CSV escaping, UTF-8 BOM, invoice fields and formula sanitization');
