'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
require('../statements.js');
const statements = globalThis.AqariStatements;

test('tenant statement totals reflect a partially and fully paid lease', () => {
  const data = {
    units: [{ id: 7, propertyId: 3 }],
    contracts: [{ id: 8, tenantId: 4, unitId: 7 }],
    dues: [
      { id: 9, contractId: 8, date: '2027-01-01', amount: 1000 },
      { id: 10, contractId: 8, date: '2027-02-01', amount: 1000 }
    ],
    payments: [
      { id: 11, dueId: 9, date: '2027-01-02', amount: 400 },
      { id: 12, dueId: 9, date: '2027-01-03', amount: 600 }
    ]
  };
  const result = statements.build(4, data, 3);
  assert.equal(result.totalDue, 2000);
  assert.equal(result.totalPaid, 1000);
  assert.equal(result.outstanding, 1000);
  assert.deepEqual(result.rows.map(x => [x.paid, x.outstanding]), [[1000, 0], [0, 1000]]);
  assert.equal(statements.build(4, data, 999).rows.length, 0);
});

test('CSV export includes UTF-8 BOM, CRLF, quoted text and safe spreadsheet cells', () => {
  const exportData = {
    rows: [
      { dueId: '=SUM(1,2)', contractId: 'A,"B"', date: '2027-01-01', amount: 1000, paid: 400, outstanding: 600 },
      { dueId: '-1+2', contractId: 'normal', date: '2027-02-01', amount: 1000, paid: 600, outstanding: 400 }
    ]
  };
  const csv = statements.exportCsv(exportData);
  assert.equal(csv.codePointAt(0), 0xfeff, 'Excel UTF-8 BOM');
  assert.ok(csv.includes('\r\n'), 'Windows-compatible record separator');
  assert.ok(csv.includes('"\'=SUM(1,2)"'), 'formula leading character neutralized and comma quoted');
  assert.ok(csv.includes('"A,""B"""'), 'CSV quote escaping');
  assert.ok(csv.includes("'-1+2"), 'negative-leading formula escaped');
  assert.equal(csv.trimEnd().split('\r\n').length, 3);
});

test('property performance CSV safely quotes property names', () => {
  const csv=statements.exportRows([
    ['العقار','المقبوضات','صافي التدفق'],
    ['=HYPERLINK("evil")',1200,800],
    ['عقار, رقم "2"',400,-50]
  ]);
  assert.equal(csv.codePointAt(0),0xfeff);
  assert.ok(csv.includes('"\'=HYPERLINK(""evil"")"'));
  assert.ok(csv.includes('"عقار, رقم ""2"""'));
  assert.ok(csv.includes('400,-50'));
  assert.equal(csv.trimEnd().split('\r\n').length,3);
});
