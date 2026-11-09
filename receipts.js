/* Local demo receipt functions. Not a tax invoice or official Ejar payment confirmation. */
(function(root){
'use strict';
const clean=value=>String(value??'').trim();
function halalas(value){
 const s=clean(value);
 if(!/^(0|[1-9]\d*)(?:\.\d{1,2})?$/.test(s))return null;
 const [whole,frac='']=s.split('.');
 const result=Number(whole)*100+Number(frac.padEnd(2,'0'));
 return Number.isSafeInteger(result)?result:null;
}
function validDate(value){
 const s=clean(value);
 if(!/^\d{4}-\d{2}-\d{2}$/.test(s))return false;
 const d=new Date(s+'T00:00:00Z');
 return Number.isFinite(d.getTime())&&d.toISOString().slice(0,10)===s;
}
function validatePayment(proposed,due,existing){
 if(!due)return 'الاستحقاق غير موجود';
 const amount=halalas(proposed.amount);
 if(amount===null||amount<=0)return 'المبلغ غير صالح أو يحوي أكثر من منزلتين عشريتين';
 if(!validDate(proposed.date))return 'تاريخ التحصيل غير صالح';
 if(!clean(proposed.method))return 'طريقة الدفع مطلوبة';
 const reference=clean(proposed.reference);
 if(reference.length>100)return 'مرجع الدفع أطول من 100 حرف';
 let settled=0;
 for(const payment of existing.filter(p=>String(p.dueId)===String(due.id))){
  const old=halalas(payment.amount);
  if(old===null)return 'بيانات التحصيل السابقة غير صالحة';
  settled+=old;
 }
 const dueAmount=halalas(due.amount);
 if(dueAmount===null||amount>dueAmount-settled)return 'المبلغ يتجاوز الرصيد المتبقي';
 if(reference&&existing.some(p=>String(p.dueId)===String(due.id)&&clean(p.reference).toLowerCase()===reference.toLowerCase()&&clean(p.method)===clean(proposed.method)))return 'مرجع الدفع مستخدم مسبقًا لهذا الاستحقاق';
 return null;
}
function receipt(payment,data){
 const due=data.dues.find(x=>String(x.id)===String(payment.dueId));
 if(!due)throw Error('Missing due');
 const contract=data.contracts.find(x=>String(x.id)===String(due.contractId));
 const tenant=data.tenants.find(x=>String(x.id)===String(contract?.tenantId));
 const unit=data.units.find(x=>String(x.id)===String(contract?.unitId));
 const property=data.properties.find(x=>String(x.id)===String(unit?.propertyId));
 const owner=data.owners.find(x=>String(x.id)===String(property?.ownerId))||data.owners[0];
 return {id:payment.id,date:payment.date,amount:payment.amount,method:payment.method,reference:payment.reference||'—',dueId:due.id,contractId:contract?.id||'—',tenant:tenant?.name||'غير محدد',unit:unit?.code||'غير محدد',property:property?.name||'غير محدد',owner:owner?.name||'غير محدد'};
}
root.AqariReceipts=Object.freeze({validatePayment,receipt});
})(typeof window!=='undefined'?window:globalThis);
