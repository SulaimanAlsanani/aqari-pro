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
 const halalas=value=>{
  const str=String(value??'').trim();
  if(!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(str))return null;
  const [whole,frac='']=str.split('.');
  const result=Number(whole)*100+Number(frac.padEnd(2,'0'));
  return Number.isSafeInteger(result)?result:null;
 };
 for(const [name,field] of [['units','area'],['contracts','total'],['dues','amount'],['payments','amount'],['expenses','amount']]){
  for(const item of copy[name]){
   const amount=halalas(item[field]);
   if(amount===null||(name!=='units'&&name!=='expenses'&&amount===0))throw Error('قيمة مالية غير صالحة: '+name+'.'+field);
  }
 }
 const duesById=new Map(copy.dues.map(item=>[key(item.id),halalas(item.amount)]));
 const paidByDue=new Map();
 for(const payment of copy.payments){
  const dueId=key(payment.dueId);
  const paid=(paidByDue.get(dueId)||0)+halalas(payment.amount);
  if(paid>duesById.get(dueId))throw Error('المقبوضات تتجاوز قيمة الاستحقاق: '+dueId);
  paidByDue.set(dueId,paid);
 }
 for(const name of ['expenses','tickets']){
  for(const record of copy[name]){
   if(record.unitId!==undefined&&record.unitId!==''&&!units.has(key(record.unitId)))throw Error('مرجع وحدة غير صالح: '+name);
   if(record.vendorId!==undefined&&record.vendorId!==''&&!index('vendors').has(key(record.vendorId)))throw Error('مرجع مورد غير صالح: '+name);
  }
 }
 return copy;
}
function parse(text){
 if(typeof text!=='string'||new TextEncoder().encode(text).length>MAX_BYTES)throw Error('حجم ملف النسخة أكبر من 8 ميجابايت');
 let data;try{data=JSON.parse(text)}catch(e){throw Error('تعذر قراءة ملف JSON')}
 return validate(data);
}
const MAX_PACKAGE_BYTES=32*1024*1024;
const MAX_ATTACH_BYTES=20*1024*1024;
const attachmentCollections=new Set(['owners','properties','units','tenants','contracts','dues','payments','expenses','tickets','vendors','documents','inspections','tasks']);
function parsePackage(text){
 if(typeof text!=='string'||new TextEncoder().encode(text).length>MAX_PACKAGE_BYTES)throw Error('النسخة الكاملة أكبر من 32 ميجابايت');
 let raw;try{raw=JSON.parse(text)}catch(e){throw Error('تعذر قراءة ملف JSON')}
 if(raw?.format!=='aqari-pro-backup-v2'){
  // Original records-only backups remain supported.
  return {records:validate(raw),attachments:[],legacy:true};
 }
 const records=validate(raw.records);
 if(!Array.isArray(raw.attachments)||raw.attachments.length>5000)throw Error('قائمة المرفقات غير صالحة');
 let bytes=0;
 const seen=new Set();
 const ids=new Map(collections.map(name=>[name,new Set(records[name].map(item=>key(item.id)))]));
 const files=raw.attachments.map(file=>{
  if(!file||typeof file!=='object'||Array.isArray(file))throw Error('مرفق غير صالح');
  if(typeof file.id!=='string'||file.id.length<1||file.id.length>128||seen.has(file.id))throw Error('معرّف مرفق مكرر أو غير صالح');
  seen.add(file.id);
  const match=typeof file.record==='string'&&/^([A-Za-z]+):([1-9]\d*)$/.exec(file.record);
  if(!match||!attachmentCollections.has(match[1])||!ids.get(match[1])?.has(match[2]))throw Error('المرفق غير مرتبط بسجل صالح');
  if(typeof file.name!=='string'||file.name.length<1||file.name.length>255||/[\/\\\x00-\x1f]/.test(file.name))throw Error('اسم المرفق غير صالح');
  if(typeof file.type!=='string'||file.type.length>128||typeof file.created!=='string'||!Number.isFinite(Date.parse(file.created)))throw Error('بيانات المرفق غير صالحة');
  if(!Number.isInteger(file.size)||file.size<1||file.size>10*1024*1024||typeof file.data!=='string'||!/^([A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(file.data))throw Error('محتوى المرفق غير صالح');
  const padding=file.data.endsWith('==')?2:file.data.endsWith('=')?1:0;
  if(file.data.length/4*3-padding!==file.size)throw Error('حجم المرفق لا يطابق محتواه');
  bytes+=file.size;
  if(bytes>MAX_ATTACH_BYTES)throw Error('إجمالي المرفقات يتجاوز 20 ميجابايت');
  return {...file};
 });
 return {records,attachments:files,legacy:false};
}
root.AqariBackup=Object.freeze({parse,validate,parsePackage,MAX_BYTES,MAX_PACKAGE_BYTES});

})(typeof window!=='undefined'?window:globalThis);
