/* Read-only aging report for synthetic browser-local rental data. */
(function(root,factory){
  'use strict';
  const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;
  if(root)root.AqariDemoAging=api;
})(typeof globalThis!=='undefined'?globalThis:this,function(){
  'use strict';
  const buckets=['days1to30','days31to60','days61to90','over90'];
  const labels=['1–30 يومًا','31–60 يومًا','61–90 يومًا','أكثر من 90 يومًا'];
  function utcDay(value){
    if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(value))throw Error('تاريخ تقرير أعمار الديون غير صالح');
    const milliseconds=Date.parse(value+'T00:00:00.000Z');
    if(!Number.isFinite(milliseconds)||new Date(milliseconds).toISOString().slice(0,10)!==value)throw Error('تاريخ تقرير أعمار الديون غير صالح');
    return milliseconds/86400000;
  }
  function cents(value){
    if(value===null||value===undefined||typeof value==='boolean'||String(value).trim()==='')throw Error('مبلغ غير صالح');
    const number=Number(value),scaled=number*100;
    if(!Number.isFinite(number)||number<0||!Number.isSafeInteger(Math.round(scaled))||Math.abs(Math.round(scaled)-scaled)>1e-6)throw Error('مبلغ غير صالح');
    return Math.round(scaled);
  }
  function makeBuckets(){return {days1to30:0,days31to60:0,days61to90:0,over90:0,totalCents:0};}
  function agingReport(properties,units,contracts,dues,payments,{asOf,propertyId='all'}={}){
    if(![properties,units,contracts,dues,payments].every(Array.isArray))throw Error('بيانات الأعمار غير صالحة');
    const today=utcDay(asOf);
    const props=properties.filter(p=>propertyId==='all'||String(p.id)===String(propertyId));
    const byProperty=new Map(props.map(p=>[String(p.id),{propertyId:String(p.id),propertyName:String(p.name||''),...makeBuckets()}]));
    const unitProps=new Map(units.map(u=>[String(u.id),String(u.propertyId)]));
    const contractProps=new Map(contracts.map(c=>[String(c.id),unitProps.get(String(c.unitId))]));
    const paid=new Map();
    for(const payment of payments){
      if(utcDay(payment.date)>today)continue;
      const key=String(payment.dueId),total=(paid.get(key)||0)+cents(payment.amount);
      if(!Number.isSafeInteger(total))throw Error('مجموع السداد غير آمن');
      paid.set(key,total);
    }
    const details=[];
    const totals=makeBuckets();
    for(const due of dues){
      const age=today-utcDay(due.date);
      if(age<=0)continue; // Today and future are not overdue.
      const prop=byProperty.get(contractProps.get(String(due.contractId)));
      if(!prop)continue;
      const outstanding=Math.max(0,cents(due.amount)-(paid.get(String(due.id))||0));
      if(!outstanding)continue;
      const bucket=age<=30?buckets[0]:age<=60?buckets[1]:age<=90?buckets[2]:buckets[3];
      for(const target of [prop,totals]){
        target[bucket]+=outstanding;
        target.totalCents+=outstanding;
        if(!Number.isSafeInteger(target[bucket])||!Number.isSafeInteger(target.totalCents))throw Error('مجموع المتأخرات غير آمن');
      }
      details.push({dueId:due.id,propertyId:prop.propertyId,dueDate:due.date,daysLate:age,bucket,outstandingCents:outstanding});
    }
    return {asOf,rows:[...byProperty.values()].sort((a,b)=>a.propertyName.localeCompare(b.propertyName,'ar')||a.propertyId.localeCompare(b.propertyId)),totals,details};
  }
  function csvCell(value,amount=false){
    const raw=String(value??''),safe=amount&&/^-?\d+\.\d{2}$/.test(raw)?raw:/^[\s]*[=+\-@]/.test(raw)?"'"+raw:raw;
    return '"'+safe.replace(/"/g,'""')+'"';
  }
  function exportAgingCsv(report){
    if(!report||!Array.isArray(report.rows)||!report.totals)throw Error('تقرير أعمار الديون غير صالح');
    const lines=[['العقار','1–30 يومًا','31–60 يومًا','61–90 يومًا','أكثر من 90 يومًا','إجمالي المتأخرات'].map(x=>csvCell(x)).join(',')];
    const format=row=>[csvCell(row.propertyName),...buckets.map(key=>csvCell((row[key]/100).toFixed(2),true)),csvCell((row.totalCents/100).toFixed(2),true)].join(',');
    report.rows.forEach(row=>lines.push(format(row)));
    lines.push(format({...report.totals,propertyName:'الإجمالي الكلي'}));
    return '\uFEFF'+lines.join('\r\n')+'\r\n';
  }
  function agingDetails(report,properties,units,contracts,tenants,dues){
    if(!report||!Array.isArray(report.details)||![properties,units,contracts,tenants,dues].every(Array.isArray))
      throw Error('بيانات تفاصيل الأعمار غير صالحة');
    const propertyById=new Map(properties.map(x=>[String(x.id),x]));
    const unitsById=new Map(units.map(x=>[String(x.id),x]));
    const contractsById=new Map(contracts.map(x=>[String(x.id),x]));
    const tenantsById=new Map(tenants.map(x=>[String(x.id),x]));
    const duesById=new Map(dues.map(x=>[String(x.id),x]));
    return report.details.map(detail=>{
      const due=duesById.get(String(detail.dueId));
      const contract=contractsById.get(String(due?.contractId));
      const unit=unitsById.get(String(contract?.unitId));
      const tenant=tenantsById.get(String(contract?.tenantId));
      const property=propertyById.get(detail.propertyId);
      if(!due||!contract||!unit||!tenant||!property||String(unit.propertyId)!==detail.propertyId)
        throw Error('علاقات قسط متأخر غير مكتملة: '+detail.dueId);
      return {...detail,contractId:contract.id,unitCode:String(unit.code??''),tenantName:String(tenant.name??''),
        propertyName:String(property.name??'')};
    }).sort((a,b)=>b.daysLate-a.daysLate||String(a.dueId).localeCompare(String(b.dueId)));
  }
  function exportAgingDetailsCsv(details){
    if(!Array.isArray(details))throw Error('تفاصيل تقرير الأعمار غير صالحة');
    const lines=[['العقار','رقم العقد','الوحدة','المستأجر','تاريخ الاستحقاق','أيام التأخير','فئة التأخير','الرصيد المتأخر (ر.س)'].map(x=>csvCell(x)).join(',')];
    for(const d of details){
      if(!Number.isSafeInteger(d.daysLate)||d.daysLate<1||!Number.isSafeInteger(d.outstandingCents)||d.outstandingCents<=0)
        throw Error('قسط متأخر غير صالح للتصدير');
      const label=labels[buckets.indexOf(d.bucket)];
      if(!label)throw Error('فئة تقادم غير صالحة');
      lines.push([csvCell(d.propertyName),csvCell(d.contractId),csvCell(d.unitCode),csvCell(d.tenantName),
        csvCell(d.dueDate),csvCell(d.daysLate),csvCell(label),
        csvCell((d.outstandingCents/100).toFixed(2),true)].join(','));
    }
    return '\uFEFF'+lines.join('\r\n')+'\r\n';
  }
  return {agingReport,exportAgingCsv,agingDetails,exportAgingDetailsCsv,labels,buckets};
});
