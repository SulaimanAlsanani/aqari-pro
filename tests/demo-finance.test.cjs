'use strict';
const assert = require('node:assert/strict');
const { createInstallments, addMonthsClamped } = require('../demo-finance.js');

function check(start, amount, count, expectedDates) {
  const dues = createInstallments(start, amount, count);
  assert.equal(dues.length, count);
  assert.deepEqual(dues.map(d => d.date), expectedDates);
  assert.equal(
    dues.reduce((cents, d) => cents + Math.round(d.amount * 100), 0),
    Math.round(amount * 100),
    'All installment amounts must add up to the exact rent in cents'
  );
  dues.forEach(d => assert.ok(d.amount >= 0.01));
}
check('2026-01-31', 100, 4, ['2026-01-31','2026-04-30','2026-07-31','2026-10-31']);
check('2028-01-31', 1200, 12, [
  '2028-01-31','2028-02-29','2028-03-31','2028-04-30','2028-05-31','2028-06-30',
  '2028-07-31','2028-08-31','2028-09-30','2028-10-31','2028-11-30','2028-12-31'
]);
check('2026-01-31', 100.01, 2, ['2026-01-31','2026-07-31']);
check('2026-02-28', 0.12, 12, [
  '2026-02-28','2026-03-28','2026-04-28','2026-05-28','2026-06-28','2026-07-28',
  '2026-08-28','2026-09-28','2026-10-28','2026-11-28','2026-12-28','2027-01-28'
]);
assert.equal(addMonthsClamped('2026-01-31', 1), '2026-02-28');
assert.throws(() => createInstallments('2026-01-01', 100, 3));
assert.throws(() => createInstallments('2026-01-01', 0.01, 12));
assert.throws(() => createInstallments('2026-02-30', 1200, 12));
const { toCents, validatePayment } = require('../demo-finance.js');
assert.equal(toCents('0.01'), 1);
assert.equal(toCents('100.05'), 10005);
assert.equal(validatePayment('0.01', '100.00', [99.99]), 0.01);
assert.equal(validatePayment('50', 100, [25, 25]), 50);
assert.throws(() => validatePayment('0.02', '100', [99.99]));
assert.throws(() => validatePayment('0', 100, []));
assert.throws(() => validatePayment('0.001', 100, []));
assert.throws(() => validatePayment('Infinity', 100, []));
assert.throws(() => validatePayment('abc', 100, []));
assert.throws(() => validatePayment('1', 100, [100]));
const { createLeaseSchedule } = require('../demo-finance.js');
assert.equal(createLeaseSchedule('2026-01-31', '2026-12-31', '1200.01', 12).length, 12);
assert.equal(createLeaseSchedule('2026-01-31', '2026-07-31', '600', 2).length, 2);
assert.throws(() => createLeaseSchedule('2026-01-31', '2026-06-30', '600', 2), /يتجاوز نهاية العقد/);
assert.throws(() => createLeaseSchedule('2026-02-01', '2026-01-31', '1200', 1), /نهاية العقد/);
assert.throws(() => createLeaseSchedule('2026-01-31', '2026-02-30', '1200', 1), /تاريخ بداية غير صالح/);
assert.throws(() => createLeaseSchedule('2026-01-31', '2026-12-31', '1200.001', 12), /هللتين/);
assert.throws(() => createInstallments('2026-01-01', 12.345, 12), /هللتين/);
const { nextRecordId } = require('../demo-finance.js');
assert.equal(nextRecordId([]), 1);
assert.equal(nextRecordId([{id:1},{id:8},{id:3}]), 9);
assert.equal(nextRecordId([{id:'100'},{id:102},{id:101}]), 103);
assert.equal(nextRecordId([{id:1000},{id:1001}]), 1002);
assert.equal(nextRecordId([{id:1},{id:1}]), 2);
assert.throws(() => nextRecordId(null), /مجموعة السجلات/);
assert.throws(() => nextRecordId([{id:Number.MAX_SAFE_INTEGER}]), /الحد الأقصى/);
const existingDues = [{id:100},{id:200}];
const beginning = nextRecordId(existingDues);
const generatedIds = Array.from({length:12}, (_, i) => beginning + i);
assert.equal(new Set([...existingDues.map(x=>x.id), ...generatedIds]).size, 14);
const { validateRecordRelation } = require('../demo-finance.js');
const referenceFixture = {
 properties:[{id:1},{id:2}],
 units:[{id:1,propertyId:1,code:'A1'},{id:2,propertyId:2,code:'B1'}],
 tenants:[{id:1}]
};
assert.equal(validateRecordRelation('units',{propertyId:'1',code:'NEW'},referenceFixture),true);
assert.throws(()=>validateRecordRelation('units',{propertyId:999,code:'NEW'},referenceFixture),/عقار موجود/);
assert.throws(()=>validateRecordRelation('units',{propertyId:1,code:' a1 '},referenceFixture),/مكرر/);
assert.throws(()=>validateRecordRelation('inspections',{propertyId:1,unitId:2},referenceFixture),/لا تتبع/);
assert.equal(validateRecordRelation('inspections',{propertyId:1,unitId:1},referenceFixture),true);
assert.throws(()=>validateRecordRelation('contracts',{unitId:999,tenantId:1},referenceFixture),/وحدة موجودة/);
assert.throws(()=>validateRecordRelation('contracts',{unitId:1,tenantId:999},referenceFixture),/مستأجر موجود/);
assert.throws(()=>validateRecordRelation('expenses',{propertyId:999},referenceFixture),/عقار موجود/);
console.log('PASS: exact rent cents, calendar clamping, supported frequencies and input and payment and lease-period and collision-free ID validation');
