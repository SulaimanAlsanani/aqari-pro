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
    relation(output.inspections,'unitId',output.units,'الوحدة');
    for(const inspection of output.inspections){
      const unit=output.units.find(x=>Number(x.id)===Number(inspection.unitId));
      if(Number(unit.propertyId)!==Number(inspection.propertyId))
        throw Error('محضر فحص يربط وحدة بعقار مختلف: '+inspection.id);
    }
    const codes=new Set();
    for(const unit of output.units){
      const code=String(unit.code??'').trim().toLowerCase();
      if(!code)throw Error('رقم وحدة غير صالح: '+unit.id);
      const uniqueKey=unit.propertyId+':'+code;
      if(codes.has(uniqueKey))throw Error('رقم وحدة مكرر داخل العقار: '+unit.code);
      codes.add(uniqueKey);
    }
    function cents(value,label){
      const num=Number(value);
      if(!Number.isFinite(num)||num<0||Math.abs(Math.round(num*100)-num*100)>1e-6)
        throw Error('مبلغ مالي غير صالح في '+label);
      return Math.round(num*100);
    }
    // Validate lease periods without requiring legacy demo fixtures to have every future due row.
    const dateValid=value=>typeof value==='string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
      && Number.isFinite(Date.parse(value)) && new Date(value).toISOString().slice(0,10)===value;
    const leasesByUnit=new Map(), duesByContract=new Map();
    for(const contract of output.contracts){
      if(!dateValid(contract.start)||!dateValid(contract.end)||contract.start>contract.end)
        throw Error('تواريخ العقد غير صالحة: '+contract.id);
      const unitId=Number(contract.unitId), prior=leasesByUnit.get(unitId)||[];
      if(prior.some(other=>other.start<=contract.end&&contract.start<=other.end))
        throw Error('تداخل عقود على الوحدة: '+unitId);
      prior.push(contract);leasesByUnit.set(unitId,prior);
    }
    for(const due of output.dues){
      const contract=output.contracts.find(x=>Number(x.id)===Number(due.contractId));
      if(!dateValid(due.date)||due.date<contract.start||due.date>contract.end)
        throw Error('تاريخ قسط خارج مدة العقد: '+due.id);
      duesByContract.set(contract.id,[...(duesByContract.get(contract.id)||[]),due]);
    }
    for(const contract of output.contracts){
      const dues=duesByContract.get(contract.id)||[];
      const dueTotal=dues.reduce((sum,d)=>sum+cents(d.amount,'الأقساط'),0);
      if(dueTotal>cents(contract.total,'العقود'))
        throw Error('مجموع الأقساط يتجاوز قيمة العقد: '+contract.id);
      if(Number.isInteger(contract.installments)&&dues.length>contract.installments)
        throw Error('عدد الأقساط يتجاوز جدول العقد: '+contract.id);
      if(dues.length===Number(contract.installments)&&dueTotal!==cents(contract.total,'العقود'))
        throw Error('مجموع الأقساط لا يساوي قيمة العقد: '+contract.id);
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
