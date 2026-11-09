/* Maintenance management rules for Aqari Pro demo. Ticket costs are estimates; only expenses represent recorded spending. */
(function(root){
'use strict';
function cents(value){
 const s=String(value??'').trim();
 if(!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(s))return null;
 const [whole,fraction='']=s.split('.');
 const amount=Number(whole)*100+Number(fraction.padEnd(2,'0'));
 return Number.isSafeInteger(amount)?amount:null;
}
function recordError(record,data,kind){
 if(!data.properties.some(p=>String(p.id)===String(record.propertyId)))return 'العقار غير موجود';
 if(record.unitId){
  const unit=data.units.find(x=>String(x.id)===String(record.unitId));
  if(!unit||String(unit.propertyId)!==String(record.propertyId))return 'الوحدة لا تتبع العقار المحدد';
 }
 if(record.vendorId&&!data.vendors.some(x=>String(x.id)===String(record.vendorId)))return 'المورد غير موجود';
 const cost=cents(kind==='ticket'?record.cost:record.amount);
 if(cost===null)return 'المبلغ يجب أن يكون بالريال والهللة دون كسور إضافية';
 if(kind==='expense'&&(!record.date||!/^\d{4}-\d{2}-\d{2}$/.test(record.date)||!Number.isFinite(Date.parse(record.date+'T00:00:00Z'))||new Date(record.date+'T00:00:00Z').toISOString().slice(0,10)!==record.date))return 'تاريخ المصروف غير صالح';
 return null;
}
function performance(data,propertyId='all'){
 const include=x=>propertyId==='all'||String(x)===String(propertyId);
 const costs=new Map(),tickets=new Map(),estimated=new Map(),unitCosts=new Map(),vendorCosts=new Map(),categoryCosts=new Map();
 let total=0;
 for(const expense of data.expenses.filter(x=>include(x.propertyId))){
  const value=cents(expense.amount);
  if(value===null)continue;
  total+=value;
  const property=String(expense.propertyId);
  costs.set(property,(costs.get(property)||0)+value);
  if(expense.unitId)unitCosts.set(String(expense.unitId),(unitCosts.get(String(expense.unitId))||0)+value);
  if(expense.vendorId)vendorCosts.set(String(expense.vendorId),(vendorCosts.get(String(expense.vendorId))||0)+value);
  const category=String(expense.category||'غير مصنف');
  categoryCosts.set(category,(categoryCosts.get(category)||0)+value);
 }
 for(const ticket of data.tickets.filter(x=>include(x.propertyId))){
  const property=String(ticket.propertyId);
  tickets.set(property,(tickets.get(property)||0)+1);
  const value=cents(ticket.cost);
  if(value!==null)estimated.set(property,(estimated.get(property)||0)+value);
 }
 const list=(map,key)=>Array.from(map,([id,amount])=>({id,amount:amount/100})).sort((a,b)=>b.amount-a.amount||a.id.localeCompare(b.id));
 return {spent:total/100,properties:data.properties.filter(x=>include(x.id)).map(p=>({id:p.id,name:p.name,spent:(costs.get(String(p.id))||0)/100,tickets:tickets.get(String(p.id))||0,estimated:(estimated.get(String(p.id))||0)/100})),byUnit:list(unitCosts),byVendor:list(vendorCosts),byCategory:list(categoryCosts)};
}
root.AqariMaintenance=Object.freeze({cents,recordError,performance});
})(typeof window!=='undefined'?window:globalThis);
