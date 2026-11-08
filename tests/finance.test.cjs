const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const context={};vm.runInNewContext(fs.readFileSync('finance.js','utf8'),context);
const finance=context.AqariFinance;
test('converts valid SAR values to exact halalas',()=>{
 assert.equal(finance.toHalalas('12.35'),1235);
 assert.equal(finance.toHalalas('0.01'),1);
 for(const invalid of ['1.234','-2','abc','','1e3','12.','01.2'])assert.equal(finance.toHalalas(invalid),null);
});
test('installment values add up without rounding drift',()=>{
 for(const [amount,count] of [['100',3],['30000',12],['999999.99',7],['0.01',1]]){
  const rows=finance.schedule('2026-01-31',amount,count);
  assert.equal(rows.length,count);
  assert.equal(rows.reduce((n,row)=>n+finance.toHalalas(row.amount),0),finance.toHalalas(amount));
 }
});
test('month-end due dates never roll into March',()=>{
 const rows=finance.schedule('2026-01-31','1200',12);
 assert.equal(rows[0].date,'2026-01-31');
 assert.equal(rows[1].date,'2026-02-28');
 assert.equal(rows[2].date,'2026-03-31');
 assert.equal(finance.dateAfterMonths('2024-01-31',1),'2024-02-29');
});
test('partial payments and invalid financial inputs',()=>{
 assert.equal(finance.remaining(100,[25.25,24.75]),50);
 assert.equal(finance.remaining(100,[100]),0);
 assert.throws(()=>finance.remaining(100,[1.234]));
 assert.throws(()=>finance.schedule('2026-02-30','100',2));
 assert.throws(()=>finance.schedule('2026-01-01','0.01',2));
 assert.throws(()=>finance.schedule('2026-01-01','20.333',2));
});

test('multi-year contract supports distinct monthly dates',()=>{
 const rows=finance.schedule('2026-01-31','24000',24,'2028-01-30');
 assert.equal(rows.length,24);
 assert.equal(new Set(rows.map(x=>x.date)).size,24);
 assert.equal(rows[0].date,'2026-01-31');
 assert.equal(rows[1].date,'2026-02-28');
 assert.equal(rows[23].date,'2027-12-31');
 assert.throws(()=>finance.schedule('2026-01-01','2400',24,'2026-12-31'));
});
