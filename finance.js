/* Aqari Pro financial rules — amounts are stored in SAR, calculations use integer halalas. */
(function(root){
'use strict';
function toHalalas(value){
 const str=String(value??'').trim();
 if(!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(str))return null;
 const parts=str.split('.'),whole=Number(parts[0]),fraction=Number((parts[1]||'').padEnd(2,'0'));
 const result=whole*100+fraction;
 return Number.isSafeInteger(result)?result:null;
}
function dateAfterMonths(start,months){
 if(!/^\d{4}-\d{2}-\d{2}$/.test(start)||!Number.isInteger(months)||months<0)throw Error('Invalid date or offset');
 const [year,month,day]=start.split('-').map(Number);
 const anchor=new Date(Date.UTC(year,month-1,day));
 if(anchor.toISOString().slice(0,10)!==start)throw Error('Invalid start date');
 const first=new Date(Date.UTC(year,month-1+months,1));
 const last=new Date(Date.UTC(first.getUTCFullYear(),first.getUTCMonth()+1,0)).getUTCDate();
 return new Date(Date.UTC(first.getUTCFullYear(),first.getUTCMonth(),Math.min(day,last))).toISOString().slice(0,10);
}
function schedule(start,total,installments,end){
 const cents=toHalalas(total);
 if(cents===null||cents<=0||!Number.isInteger(installments)||installments<1||installments>120||cents<installments)throw Error('Invalid installment schedule');
 const monthSlots=end?Array.from({length:121},(_,i)=>i).filter(i=>dateAfterMonths(start,i)<=end).length:12;
 if(end&&(!/^\d{4}-\d{2}-\d{2}$/.test(end)||end<start||dateAfterMonths(start,0)>end))throw Error('Invalid contract end date');
 if(monthSlots<installments||monthSlots>120)throw Error('Too many installments for the contract duration');
 const base=Math.floor(cents/installments),remainder=cents%installments;
 return Array.from({length:installments},(_,i)=>({
  date:dateAfterMonths(start,Math.floor(i*monthSlots/installments)),
  amount:(base+(i<remainder?1:0))/100
 }));
}
function remaining(due,paidAmounts){
 const total=toHalalas(due);
 if(total===null)throw Error('Invalid due');
 const paid=paidAmounts.reduce((sum,value)=>{const cents=toHalalas(value);if(cents===null)throw Error('Invalid payment');return sum+cents},0);
 return Math.max(0,total-paid)/100;
}
function receivablesAging(dues,payments,asOf){
 if(!/^\d{4}-\d{2}-\d{2}$/.test(asOf)||Number.isNaN(Date.parse(asOf+'T00:00:00Z')))throw Error('Invalid reporting date');
 const day=Date.parse(asOf+'T00:00:00Z')/86400000;
 const paymentsByDue=new Map();
 for(const p of payments){
  const amount=toHalalas(p.amount);
  if(amount===null)throw Error('Invalid payment amount');
  const key=String(p.dueId);
  paymentsByDue.set(key,(paymentsByDue.get(key)||0)+amount);
 }
 const result={notDue:0,days1to30:0,days31to60:0,days61to90:0,days90plus:0,total:0};
 for(const due of dues){
  const amount=toHalalas(due.amount);
  const dueDay=Date.parse(due.date+'T00:00:00Z')/86400000;
  if(amount===null||!Number.isFinite(dueDay))throw Error('Invalid due record');
  const outstanding=Math.max(0,amount-(paymentsByDue.get(String(due.id))||0));
  const age=day-dueDay;
  const band=age<=0?'notDue':age<=30?'days1to30':age<=60?'days31to60':age<=90?'days61to90':'days90plus';
  result[band]+=outstanding;
  result.total+=outstanding;
 }
 for(const key of Object.keys(result))result[key]/=100;
 return result;
}
function collectionAlerts(dues,payments,today,windowDays=7){
 const now=Date.parse(today+'T00:00:00Z');
 if(!/^\d{4}-\d{2}-\d{2}$/.test(today)||!Number.isFinite(now)||new Date(now).toISOString().slice(0,10)!==today||!Number.isInteger(windowDays)||windowDays<0)throw Error('Invalid alert window');
 const map=new Map();
 for(const p of payments){
  const cents=toHalalas(p.amount);
  if(cents===null)throw Error('Invalid payment');
  const id=String(p.dueId);
  map.set(id,(map.get(id)||0)+cents);
 }
 const alerts={overdue:[],upcoming:[],partial:[]};
 for(const due of dues){
  const cents=toHalalas(due.amount),date=Date.parse(due.date+'T00:00:00Z');
  if(cents===null||!/^\d{4}-\d{2}-\d{2}$/.test(due.date)||!Number.isFinite(date)||new Date(date).toISOString().slice(0,10)!==due.date)throw Error('Invalid due');
  const paidCents=map.get(String(due.id))||0,remainingCents=Math.max(0,cents-paidCents);
  if(remainingCents===0)continue;
  const item={id:due.id,date:due.date,remaining:remainingCents/100,days:Math.round((date-now)/86400000)};
  if(item.days<0)alerts.overdue.push(item);
  else if(item.days<=windowDays)alerts.upcoming.push(item);
  if(paidCents>0)alerts.partial.push(item);
 }
 alerts.overdue.sort((a,b)=>a.days-b.days);
 alerts.upcoming.sort((a,b)=>a.days-b.days);
 return alerts;
}
root.AqariFinance=Object.freeze({toHalalas,dateAfterMonths,schedule,remaining,receivablesAging,collectionAlerts});
})(typeof window!=='undefined'?window:globalThis);
