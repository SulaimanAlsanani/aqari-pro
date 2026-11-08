/* CSV export helper for synthetic expense data in the browser-only demo. */
(function(root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.AqariDemoCsv = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function() {
  'use strict';
  const headers = ['العقار', 'التاريخ', 'التصنيف', 'الوصف', 'المبلغ (ر.س)', 'مرجع الفاتورة'];
  function cell(value) {
    const raw = String(value ?? '');
    // Excel may interpret attacker-provided cell text as a formula. Prefix risky values.
    const safe = /^[\s]*[=+\-@]/.test(raw) || /^[\t\r\n]/.test(raw) ? "'" + raw : raw;
    return '"' + safe.replace(/"/g, '""') + '"';
  }
  function buildExpenseCsv(expenses, properties) {
    if (!Array.isArray(expenses) || !Array.isArray(properties)) throw Error('بيانات التصدير غير صالحة');
    const names = new Map(properties.map(p => [String(p.id), String(p.name ?? '')]));
    const lines = [headers.map(cell).join(',')];
    for (const item of expenses) {
      if (!item || typeof item !== 'object') throw Error('سجل مصروف غير صالح');
      const amount = Number(item.amount);
      if (!Number.isFinite(amount) || amount < 0 || !Number.isSafeInteger(Math.round(amount * 100)))
        throw Error('مبلغ مصروف غير صالح');
      const row = [names.get(String(item.propertyId)) ?? 'غير محدد', item.date, item.category,
        item.description, (Math.round(amount * 100) / 100).toFixed(2), item.invoiceReference ?? ''];
      lines.push(row.map(cell).join(','));
    }
    return '\uFEFF' + lines.join('\r\n') + '\r\n';
  }
  function toCents(amount) {
    const str=String(amount ?? '').trim();
    if(!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(str))throw Error('مبلغ مالي غير صالح');
    const [whole,fraction='']=str.split('.');
    const cents=Number(whole)*100+Number(fraction.padEnd(2,'0'));
    if(!Number.isSafeInteger(cents))throw Error('مبلغ مالي خارج النطاق');
    return cents;
  }
  function filterExpenseRows(expenses, bounds={}) {
    if(!Array.isArray(expenses))throw Error('سجل المصروفات غير صالح');
    const {from='',to='',min='',max=''}=bounds;
    const dateOk=date=>!date || /^\d{4}-\d{2}-\d{2}$/.test(date);
    if(!dateOk(from)||!dateOk(to))throw Error('نطاق التاريخ غير صالح');
    const minCents=min===''?null:toCents(min),maxCents=max===''?null:toCents(max);
    if(from&&to&&from>to)return [];
    if(minCents!==null&&maxCents!==null&&minCents>maxCents)return [];
    return expenses.filter(row=>{
      const amount=toCents(row.amount);
      return (!from||String(row.date)>=from)&&(!to||String(row.date)<=to)
        &&(minCents===null||amount>=minCents)&&(maxCents===null||amount<=maxCents);
    });
  }
  function sumExpenseCents(expenses) {
    if(!Array.isArray(expenses))throw Error('سجل المصروفات غير صالح');
    return expenses.reduce((total,row)=>{
      const next=total+toCents(row.amount);
      if(!Number.isSafeInteger(next))throw Error('إجمالي المصروفات يتجاوز النطاق الآمن');
      return next;
    },0);
  }
  function groupExpenseByProperty(expenses, properties) {
    if(!Array.isArray(expenses)||!Array.isArray(properties))throw Error('بيانات الملخص غير صالحة');
    const names=new Map(properties.map(property=>[String(property.id),String(property.name??'')]));
    const groups=new Map();
    for(const expense of expenses){
      const id=String(expense.propertyId),cents=toCents(expense.amount);
      if(!names.has(id))throw Error('العقار المرتبط بالمصروف غير موجود');
      if(!groups.has(id))groups.set(id,{propertyId:id,propertyName:names.get(id),count:0,totalCents:0});
      const row=groups.get(id);
      row.count++;
      row.totalCents+=cents;
      if(!Number.isSafeInteger(row.totalCents))throw Error('إجمالي العقار يتجاوز النطاق الآمن');
    }
    const result=[...groups.values()].sort((a,b)=>a.propertyName.localeCompare(b.propertyName,'ar')||a.propertyId.localeCompare(b.propertyId));
    const totalCents=result.reduce((sum,row)=>{
      const next=sum+row.totalCents;
      if(!Number.isSafeInteger(next))throw Error('إجمالي المصروفات يتجاوز النطاق الآمن');
      return next;
    },0);
    return {groups:result,count:expenses.length,totalCents};
  }
  function buildExpenseSummaryCsv(summary) {
    if(!summary||!Array.isArray(summary.groups))throw Error('ملخص المصروفات غير صالح');
    const lines=[['العقار','عدد المصروفات','الإجمالي (ر.س)'].map(cell).join(',')];
    for(const row of summary.groups)lines.push([row.propertyName,row.count,(row.totalCents/100).toFixed(2)].map(cell).join(','));
    lines.push(['الإجمالي الكلي',summary.count,(summary.totalCents/100).toFixed(2)].map(cell).join(','));
    return '\uFEFF'+lines.join('\r\n')+'\r\n';
  }
  // Income is assigned by the receipt date, not by the installment due date.
  function comparePropertyCashFlow(properties,units,contracts,dues,payments,expenses,bounds={}) {
    if(![properties,units,contracts,dues,payments,expenses].every(Array.isArray))throw Error('بيانات المقارنة غير صالحة');
    const from=bounds.from||'',to=bounds.to||'',selected=bounds.propertyId;
    const validDate=d=>!d||(/^\d{4}-\d{2}-\d{2}$/.test(d)&&!Number.isNaN(Date.parse(d)));
    if(!validDate(from)||!validDate(to))throw Error('تاريخ المقارنة غير صالح');
    const selectedProperties=properties.filter(p=>selected===undefined||selected==='all'||String(p.id)===String(selected));
    const unitToProperty=new Map(units.map(u=>[String(u.id),String(u.propertyId)]));
    const leaseToProperty=new Map(contracts.map(c=>[String(c.id),unitToProperty.get(String(c.unitId))]));
    const dueToProperty=new Map(dues.map(d=>[String(d.id),leaseToProperty.get(String(d.contractId))]));
    const rows=new Map(selectedProperties.map(p=>[String(p.id),{propertyId:String(p.id),propertyName:String(p.name??''),receiptsCents:0,expensesCents:0,netCents:0}]));
    const inRange=date=>(!from||date>=from)&&(!to||date<=to);
    for(const payment of payments){
      if(!inRange(payment.date))continue;
      const row=rows.get(dueToProperty.get(String(payment.dueId)));
      if(!row)continue;
      row.receiptsCents+=toCents(payment.amount);
      if(!Number.isSafeInteger(row.receiptsCents))throw Error('إجمالي المقبوضات خارج النطاق');
    }
    for(const expense of expenses){
      if(!inRange(expense.date))continue;
      const row=rows.get(String(expense.propertyId));
      if(!row)continue;
      row.expensesCents+=toCents(expense.amount);
      if(!Number.isSafeInteger(row.expensesCents))throw Error('إجمالي المصروفات خارج النطاق');
    }
    const results=[...rows.values()].sort((a,b)=>a.propertyName.localeCompare(b.propertyName,'ar')||a.propertyId.localeCompare(b.propertyId));
    const totals={receiptsCents:0,expensesCents:0,netCents:0};
    for(const row of results){
      row.netCents=row.receiptsCents-row.expensesCents;
      totals.receiptsCents+=row.receiptsCents;
      totals.expensesCents+=row.expensesCents;
      totals.netCents+=row.netCents;
      if(!Object.values(totals).every(Number.isSafeInteger))throw Error('إجمالي المقارنة خارج النطاق');
    }
    return {rows:results,totals,from,to};
  }
  function buildCashFlowComparisonCsv(comparison) {
    if(!comparison||!Array.isArray(comparison.rows)||!comparison.totals)throw Error('تقرير المقارنة غير صالح');
    const lines=[['العقار','المقبوضات (ر.س)','المصروفات (ر.س)','صافي التدفق (ر.س)'].map(cell).join(',')];
    const currency=cents=>(cents/100).toFixed(2);
    for(const r of comparison.rows)lines.push([r.propertyName,currency(r.receiptsCents),currency(r.expensesCents),currency(r.netCents)].map(cell).join(','));
    lines.push(['الإجمالي الكلي',currency(comparison.totals.receiptsCents),currency(comparison.totals.expensesCents),currency(comparison.totals.netCents)].map(cell).join(','));
    return '\\uFEFF'+lines.join('\\r\\n')+'\\r\\n';
  }
  return {buildExpenseCsv,filterExpenseRows,sumExpenseCents,groupExpenseByProperty,buildExpenseSummaryCsv,comparePropertyCashFlow,buildCashFlowComparisonCsv};
});
