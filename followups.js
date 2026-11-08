/* Local collection follow-up ledger; no SMS, WhatsApp or external notifications. */
(function(root){
'use strict';
const channels=['اتصال','رسالة','بريد إلكتروني','زيارة','ملاحظة داخلية'];
const outcomes=['لم يتم الرد','تم التواصل','وعد بالسداد','طلب مهلة','نزاع على المبلغ','أخرى'];
function validDate(value){
 if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(value))return false;
 const time=Date.parse(value+'T00:00:00Z');
 return Number.isFinite(time)&&new Date(time).toISOString().slice(0,10)===value;
}
function validate(data,dues){
 if(!dues.some(d=>String(d.id)===String(data.dueId)))return 'الاستحقاق غير موجود';
 if(!validDate(data.date))return 'تاريخ المتابعة غير صحيح';
 if(data.nextDate&&!validDate(data.nextDate))return 'تاريخ المتابعة القادمة غير صحيح';
 if(data.nextDate&&data.nextDate<data.date)return 'تاريخ المتابعة القادمة يسبق تاريخ المتابعة';
 if(!channels.includes(data.channel))return 'وسيلة التواصل غير مدعومة';
 if(!outcomes.includes(data.outcome))return 'نتيجة المتابعة غير مدعومة';
 if(typeof data.notes!=='string'||data.notes.trim().length>1000)return 'الملاحظة تتجاوز 1000 حرف';
 return null;
}
function pending(records,dues,payments,today){
 const paid=new Map();
 for(const p of payments)paid.set(String(p.dueId),(paid.get(String(p.dueId))||0)+Math.round(Number(p.amount)*100));
 const dueMap=new Map(dues.map(d=>[String(d.id),d]));
 return records.filter(r=>{
  const due=dueMap.get(String(r.dueId));
  return due&&validDate(r.nextDate)&&r.nextDate<=today&&Math.round(Number(due.amount)*100)>(paid.get(String(r.dueId))||0);
 }).sort((a,b)=>a.nextDate.localeCompare(b.nextDate));
}
root.AqariFollowUps=Object.freeze({channels,outcomes,validDate,validate,pending});
})(typeof window!=='undefined'?window:globalThis);
