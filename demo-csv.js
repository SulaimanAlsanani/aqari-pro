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
    if(!/^(?:0|[1-9]\\d*)(?:\\.\\d{1,2})?$/.test(str))throw Error('مبلغ مالي غير صالح');
    const [whole,fraction='']=str.split('.');
    const cents=Number(whole)*100+Number(fraction.padEnd(2,'0'));
    if(!Number.isSafeInteger(cents))throw Error('مبلغ مالي خارج النطاق');
    return cents;
  }
  function filterExpenseRows(expenses, bounds={}) {
    if(!Array.isArray(expenses))throw Error('سجل المصروفات غير صالح');
    const {from='',to='',min='',max=''}=bounds;
    const dateOk=date=>!date || /^\\d{4}-\\d{2}-\\d{2}$/.test(date);
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
  return {buildExpenseCsv,filterExpenseRows,sumExpenseCents};
});
