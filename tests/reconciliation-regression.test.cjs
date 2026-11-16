'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
require('../reconciliation.js');
const recon = globalThis.AqariReconciliation;

const sample = () => ({
  properties: [{id:1,name:'North'}, {id:2,name:'South'}],
  units: [{id:10,propertyId:1}, {id:20,propertyId:2}],
  contracts: [{id:100,unitId:10}, {id:200,unitId:20}],
  dues: [{id:1000,contractId:100,amount:1000}, {id:2000,contractId:200,amount:500}],
  payments: [{id:3000,dueId:1000,amount:400}, {id:3001,dueId:1000,amount:600}, {id:3002,dueId:2000,amount:100}],
  expenses: [{id:4000,propertyId:1,amount:250}, {id:4001,propertyId:2,amount:150}]
});

test('portfolio totals reconcile to dues, receipts, expenses and cash-flow', () => {
  const result = recon.calculate(sample());
  assert.equal(result.due,1500);
  assert.equal(result.received,1100);
  assert.equal(result.uncollected,400);
  assert.equal(result.expenses,400);
  assert.equal(result.net,700);
  assert.equal(result.balanced,true);
});

test('property filter excludes unrelated cash and expenses', () => {
  const a = recon.calculate(sample(),1);
  assert.deepEqual([a.due,a.received,a.uncollected,a.expenses,a.net],[1000,1000,0,250,750]);
  const b = recon.calculate(sample(),2);
  assert.deepEqual([b.due,b.received,b.uncollected,b.expenses,b.net],[500,100,400,150,-50]);
  const properties = recon.perProperty(sample());
  assert.equal(properties.length,2);
  assert.equal(properties.reduce((total,p) => total + p.net,0),700);
});

test('duplicate due identifiers are flagged and never counted twice', () => {
  const data = sample();
  data.dues.push({id:1000,contractId:100,amount:1000});
  const result = recon.calculate(data);
  assert.equal(result.due,1500);
  assert.equal(result.uncollected,400);
  assert.equal(result.balanced,false);
  assert.ok(result.issues.some(issue=>issue.type==='duplicate_due_id'));
});

test('overpayment and orphan payment generate review issues', () => {
  const data = sample();
  data.payments.push({id:3003,dueId:1000,amount:100});
  data.payments.push({id:3004,dueId:999999,amount:20});
  const result = recon.calculate(data);
  assert.equal(result.overpaid,100);
  assert.equal(result.orphanPayments,1);
  assert.equal(result.uncollected,400);
  assert.equal(result.balanced,false);
  assert.ok(result.issues.some(issue=>issue.type==='overpayment'));
  assert.ok(result.issues.some(issue=>issue.type==='orphan_payment'));
});
