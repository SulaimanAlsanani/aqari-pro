/* Audit-only reconciliation and property performance for the local demo. */
(function(root){
'use strict';
function cents(value){
 const text=String(value??'').trim();
 if(!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(text))return null;
 const [whole,fraction='']=text.split('.');
 const result=Number(whole)*100+Number(fraction.padEnd(2,'0'));
 return Number.isSafeInteger(result)?result:null;
}
function calculate(data,propertyId='all'){
 const issues=[],dueById=new Map(),contractById=new Map(data.contracts.map(x=>[String(x.id),x])),unitById=new Map(data.units.map(x=>[String(x.id),x]));
 const propertyKeys=new Map(data.properties.map(x=>[String(x.id),x]));
 const scoped=key=>propertyId==='all'||String(key)===String(propertyId);
 const targetDues=[];
 for(const due of data.dues){
  const contract=contractById.get(String(due.contractId)),unit=unitById.get(String(contract?.unitId));
  if(!unit||!propertyKeys.has(String(unit.propertyId))){issues.push({type:'orphan_due',id:due.id,message:'استحقاق بلا عقد أو عقار صالح'});continue}
  if(!scoped(unit.propertyId))continue;
  const key=String(due.id);
  if(dueById.has(key))issues.push({type:'duplicate_due_id',id:due.id,message:'رقم استحقاق مكرر'});
  else dueById.set(key,due);
  targetDues.push(due);
 }
 const paymentsByDue=new Map(),paymentIds=new Set();
 let paid=0,invalid=0,orphan=0;
 for(const payment of data.payments){
  const due=dueById.get(String(payment.dueId));
  if(!due){
   if(propertyId==='all'&&!data.dues.some(x=>String(x.id)===String(payment.dueId))){orphan++;issues.push({type:'orphan_payment',id:payment.id,message:'سند بلا استحقاق'});}
   continue;
  }
  const key=String(payment.id),amount=cents(payment.amount);
  if(paymentIds.has(key))issues.push({type:'duplicate_payment_id',id:payment.id,message:'رقم سند مكرر'});
  else paymentIds.add(key);
  if(amount===null){invalid++;issues.push({type:'invalid_payment',id:payment.id,message:'مبلغ سند غير صالح'});continue}
  paymentsByDue.set(String(payment.dueId),(paymentsByDue.get(String(payment.dueId))||0)+amount);
  paid+=amount;
 }
 let dueTotal=0,overpaid=0;
 for(const due of targetDues){
  const amount=cents(due.amount),received=paymentsByDue.get(String(due.id))||0;
  if(amount===null){issues.push({type:'invalid_due',id:due.id,message:'مبلغ استحقاق غير صالح'});continue}
  dueTotal+=amount;
  if(received>amount){overpaid+=received-amount;issues.push({type:'overpayment',id:due.id,message:'تحصيل أكبر من قيمة الاستحقاق'});}
 }
 let expenses=0;
 for(const item of data.expenses.filter(x=>scoped(x.propertyId))){
  const amount=cents(item.amount);
  if(amount===null){issues.push({type:'invalid_expense',id:item.id,message:'مصروف غير صالح'});continue}
  expenses+=amount;
 }
 return {due:dueTotal/100,received:paid/100,uncollected:Math.max(0,dueTotal-paid+overpaid)/100,overpaid:overpaid/100,expenses:expenses/100,net:(paid-expenses)/100,issues,orphanPayments:orphan,invalidPayments:invalid,balanced:issues.length===0};
}
function perProperty(data){
 return data.properties.map(property=>({id:property.id,name:property.name,...calculate(data,property.id)}));
}
root.AqariReconciliation=Object.freeze({calculate,perProperty});
})(typeof window!=='undefined'?window:globalThis);
