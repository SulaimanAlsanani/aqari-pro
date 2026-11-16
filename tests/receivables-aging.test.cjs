'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
require('../finance.js');
const finance = globalThis.AqariFinance;

test('aging buckets reconcile outstanding halalas across date boundaries', () => {
  const dueDates=['2027-03-01','2027-02-28','2027-02-01','2026-12-31','2026-11-30','2026-10-01'];
  const dues=dueDates.map((date,i)=>({id:i+1,date,amount:100}));
  const payments=[{id:40,dueId:2,amount:25},{id:41,dueId:3,amount:60}];
  const aging=finance.receivablesAging(dues,payments,'2027-03-01');
  assert.deepEqual(aging,{
    notDue:100, days1to30:115,days31to60:100,
    days61to90:0,days90plus:200,total:515
  });
  assert.equal(Object.entries(aging).filter(([key])=>key!=='total').reduce((sum,[,value])=>sum+value,0),aging.total);
});

test('duplicated due identifier does not inflate age totals',()=>{
  const dues=[{id:1,date:'2027-01-01',amount:100},{id:1,date:'2027-01-01',amount:100}];
  assert.equal(finance.receivablesAging(dues,[],'2027-03-01').total,100);
});

test('reject impossible reporting and due dates rather than silently normalize',()=>{
  assert.throws(()=>finance.receivablesAging([],[],'2027-02-30'),/Invalid reporting date/);
  assert.throws(()=>finance.receivablesAging([{id:1,date:'2027-02-30',amount:100}],[],'2027-03-01'),/Invalid due record/);
});
