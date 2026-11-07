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
console.log('PASS: exact rent cents, calendar clamping, supported frequencies and input validation');
