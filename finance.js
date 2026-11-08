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
function schedule(start,total,installments){
 const cents=toHalalas(total);
 if(cents===null||cents<=0||!Number.isInteger(installments)||installments<1||installments>120||cents<installments)throw Error('Invalid installment schedule');
 const base=Math.floor(cents/installments),remainder=cents%installments;
 return Array.from({length:installments},(_,i)=>({
  date:dateAfterMonths(start,Math.round(i*12/installments)),
  amount:(base+(i<remainder?1:0))/100
 }));
}
function remaining(due,paidAmounts){
 const total=toHalalas(due);
 if(total===null)throw Error('Invalid due');
 const paid=paidAmounts.reduce((sum,value)=>{const cents=toHalalas(value);if(cents===null)throw Error('Invalid payment');return sum+cents},0);
 return Math.max(0,total-paid)/100;
}
root.AqariFinance=Object.freeze({toHalalas,dateAfterMonths,schedule,remaining});
})(typeof window!=='undefined'?window:globalThis);
