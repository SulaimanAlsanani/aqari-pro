/* Validate local demo JSON backups before any storage mutation. */
(function(root){
'use strict';
const collections=['owners','properties','units','tenants','contracts','dues','payments','expenses','vendors','tickets','documents','inspections','tasks','payouts','audit','ejarLogs','followUps'];
const MAX_BYTES=8*1024*1024;
const id=value=>(typeof value==='number'&&Number.isSafeInteger(value)&&value>0)||(typeof value==='string'&&/^[1-9]\d*$/.test(value));
const key=value=>String(value);
function validate(data){
 if(!data||typeof data!=='object'||Array.isArray(data))throw Error('ملف النسخة الاحتياطية غير صالح');
 const copy={};
 for(const name of collections){
  const records=data[name];
  if(!Array.isArray(records)||records.length>100000)throw Error('مجموعة بيانات غير صالحة: '+name);
  const seen=new Set();
  copy[name]=records.map(item=>{
   if(!item||typeof item!=='object'||Array.isArray(item)||!id(item.id))throw Error('سجل غير صالح: '+name);
   if(seen.has(key(item.id)))throw Error('رقم سجل مكرر: '+name);
   seen.add(key(item.id));
   return {...item};
  });
 }
 if(copy.owners.length!==1||key(copy.owners[0].id)!=='1')throw Error('النسخة يجب أن تحتوي على مالك واحد');
 const index=name=>new Set(copy[name].map(item=>key(item.id)));
 const owners=index('owners'),properties=index('properties'),units=index('units'),
  tenants=index('tenants'),contracts=index('contracts'),dues=index('dues');
 const check=(records,field,targets,name)=>{
  for(const item of records)if(!targets.has(key(item[field])))throw Error('مرجع غير صالح: '+name+'.'+field);
 };
 check(copy.properties,'ownerId',owners,'properties');
 check(copy.units,'propertyId',properties,'units');
 check(copy.contracts,'unitId',units,'contracts');
 check(copy.contracts,'tenantId',tenants,'contracts');
 check(copy.dues,'contractId',contracts,'dues');
 check(copy.payments,'dueId',dues,'payments');
 check(copy.expenses,'propertyId',properties,'expenses');
 check(copy.tickets,'propertyId',properties,'tickets');
 check(copy.documents,'propertyId',properties,'documents');
 check(copy.inspections,'propertyId',properties,'inspections');
 for(const [name,field] of [['units','area'],['contracts','total'],['dues','amount'],['payments','amount'],['expenses','amount']]){
  for(const item of copy[name]){
   const num=Number(item[field]);
   if(!Number.isFinite(num)||num<0||!Number.isSafeInteger(Math.round(num*100)))throw Error('قيمة مالية غير صالحة: '+name+'.'+field);
  }
 }
 return copy;
}
function parse(text){
 if(typeof text!=='string'||new TextEncoder().encode(text).length>MAX_BYTES)throw Error('حجم ملف النسخة أكبر من 8 ميجابايت');
 let data;try{data=JSON.parse(text)}catch(e){throw Error('تعذر قراءة ملف JSON')}
 return validate(data);
}
root.AqariBackup=Object.freeze({parse,validate,MAX_BYTES});
})(typeof window!=='undefined'?window:globalThis);
