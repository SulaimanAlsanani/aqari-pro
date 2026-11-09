/* Tenant statement calculations. Demo-only, no official receipts. */
(function(root){
'use strict';
function cents(value){
 const input=String(value??'').trim();
 if(!/^(0|[1-9]\d*)(\.\d{1,2})?$/.test(input))throw Error('Invalid SAR value');
 const [integer,decimal='']=input.split('.');
 const n=Number(integer)*100+Number(decimal.padEnd(2,'0'));
 if(!Number.isSafeInteger(n))throw Error('Amount exceeds safe range');
 return n;
}
function build(tenantId,data,propertyId){
 const contracts=data.contracts.filter(x=>String(x.tenantId)===String(tenantId));
 const contractById=new Map(contracts.map(x=>[String(x.id),x]));
 const unitById=new Map(data.units.map(x=>[String(x.id),x]));
 const dues=data.dues.filter(d=>{
  const contract=contractById.get(String(d.contractId));
  const unit=contract&&unitById.get(String(contract.unitId));
  return unit&&(propertyId==='all'||String(unit.propertyId)===String(propertyId));
 });
 const dueById=new Map(dues.map(d=>[String(d.id),d]));
 const payments=data.payments.filter(p=>dueById.has(String(p.dueId)));
 const paidByDue=new Map();
 for(const payment of payments){
  const k=String(payment.dueId);
  paidByDue.set(k,(paidByDue.get(k)||0)+cents(payment.amount));
 }
 let dueCents=0,paidCents=0;
 const rows=dues.map(d=>{
  const amount=cents(d.amount),paid=paidByDue.get(String(d.id))||0;
  dueCents+=amount;
  return {dueId:d.id,contractId:d.contractId,date:d.date,amount:amount/100,paid:paid/100,outstanding:Math.max(0,amount-paid)/100};
 }).sort((a,b)=>a.date.localeCompare(b.date)||String(a.dueId).localeCompare(String(b.dueId)));
 for(const payment of payments)paidCents+=cents(payment.amount);
 return {tenantId,rows,payments:payments.slice().sort((a,b)=>a.date.localeCompare(b.date)),totalDue:dueCents/100,totalPaid:paidCents/100,outstanding:Math.max(0,dueCents-paidCents)/100};
}
root.AqariStatements=Object.freeze({build});
})(typeof window!=='undefined'?window:globalThis);
