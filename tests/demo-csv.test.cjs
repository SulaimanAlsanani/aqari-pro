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
console.log('PASS: expense CSV escaping, UTF-8 BOM, invoice fields and formula sanitization');
