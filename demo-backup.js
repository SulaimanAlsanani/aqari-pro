/* Strict local-only JSON backup validation, used by GitHub Pages demo. */
(function(root, factory) {
  'use strict';
  const api=factory();
  if(typeof module==='object' && module.exports)module.exports=api;
  if(root)root.AqariDemoBackup=api;
})(typeof globalThis!=='undefined'?globalThis:this,function(){
  'use strict';
  const collections=['owners','properties','units','tenants','contracts','dues','payments','expenses','vendors','tickets','documents','inspections','tasks','payouts','audit','ejarLogs'];
  function validate(input) {
    if(!input||typeof input!=='object'||Array.isArray(input))throw Error('صيغة النسخة الاحتياطية غير صحيحة');
    const output=Object.create(null);
    for(const collection of collections){
      const rows=input[collection];
      if(!Array.isArray(rows)||rows.length>50000)throw Error('قسم غير صالح أو كبير جدًا: '+collection);
      const ids=new Set();
      output[collection]=rows.map(row=>{
        if(!row||typeof row!=='object'||Array.isArray(row))throw Error('سجل غير صالح في '+collection);
        const id=Number(row.id);
        if(!Number.isSafeInteger(id)||id<1||ids.has(id))throw Error('معرف مكرر أو غير صالح في '+collection);
        ids.add(id);
        return {...row,id};
      });
    }
    if(output.owners.length!==1||output.owners[0].id!==1)throw Error('هذه النسخة مخصصة لمالك واحد فقط');
    function relation(rows,key,parents,label,optional=false){
      const valid=new Set(parents.map(r=>r.id));
      for(const row of rows){
        if(optional&&(row[key]===null||row[key]===undefined||row[key]===''))continue;
        const id=Number(row[key]);
        if(!Number.isSafeInteger(id)||!valid.has(id))throw Error('مرجع '+label+' غير صالح في السجل '+row.id);
      }
    }
    relation(output.properties,'ownerId',output.owners,'مالك العقار');
    relation(output.units,'propertyId',output.properties,'العقار');
    relation(output.contracts,'unitId',output.units,'الوحدة');
    relation(output.contracts,'tenantId',output.tenants,'المستأجر');
    relation(output.dues,'contractId',output.contracts,'العقد');
    relation(output.payments,'dueId',output.dues,'القسط');
    for(const key of ['expenses','tickets','documents','inspections'])relation(output[key],'propertyId',output.properties,'العقار',key==='documents');
    function cents(value,label){
      const num=Number(value);
      if(!Number.isFinite(num)||num<0||Math.abs(Math.round(num*100)-num*100)>1e-6)
        throw Error('مبلغ مالي غير صالح في '+label);
      return Math.round(num*100);
    }
    const paid=new Map();
    for(const row of output.dues)cents(row.amount,'الأقساط');
    for(const row of output.payments){
      const value=cents(row.amount,'المقبوضات');
      if(value===0)throw Error('دفعة صفرية في النسخة');
      const dueId=Number(row.dueId);
      paid.set(dueId,(paid.get(dueId)||0)+value);
    }
    for(const due of output.dues){
      if((paid.get(due.id)||0)>cents(due.amount,'الأقساط'))throw Error('تحصيل يتجاوز القسط '+due.id);
    }
    for(const row of output.contracts)cents(row.total,'العقود');
    for(const row of output.expenses)cents(row.amount,'المصروفات');
    return output;
  }
  return {collections,validate};
});
