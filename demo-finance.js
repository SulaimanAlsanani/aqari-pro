/* Pure schedule logic for the public demo; no backend or financial settlement. */
(function(root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.AqariDemoFinance = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function() {
  'use strict';
  const allowed = new Set([1, 2, 4, 12]);
  function addMonthsClamped(iso, offset) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) throw Error('تاريخ بداية غير صالح');
    const [year, month, day] = iso.split('-').map(Number);
    const original = new Date(Date.UTC(year, month - 1, day));
    if (!Number.isFinite(original.getTime()) || original.toISOString().slice(0, 10) !== iso)
      throw Error('تاريخ بداية غير صالح');
    const destination = new Date(Date.UTC(year, month - 1 + offset, 1));
    const maxDay = new Date(Date.UTC(destination.getUTCFullYear(), destination.getUTCMonth() + 1, 0)).getUTCDate();
    destination.setUTCDate(Math.min(day, maxDay));
    return destination.toISOString().slice(0, 10);
  }
  function createInstallments(start, total, count) {
    if (!allowed.has(count)) throw Error('عدد الأقساط يجب أن يكون 1 أو 2 أو 4 أو 12');
    if (!Number.isFinite(total) || total <= 0 || Math.round(total * 100) < count)
      throw Error('قيمة العقد لا تكفي لتوزيع الأقساط');
    const cents = Math.round(total * 100);
    const base = Math.floor(cents / count);
    const extra = cents % count;
    return Array.from({length: count}, (_, i) => ({
      date: addMonthsClamped(start, i * (12 / count)),
      amount: (base + (i < extra ? 1 : 0)) / 100
    }));
  }
  function toCents(amount) {
    const value = typeof amount === 'number' ? String(amount) : String(amount ?? '').trim();
    if (!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(value))
      throw Error('المبلغ يجب أن يكون رقمًا موجبًا بدقة هللتين كحد أقصى');
    const [whole, fractional = ''] = value.split('.');
    const cents = Number(whole) * 100 + Number(fractional.padEnd(2, '0'));
    if (!Number.isSafeInteger(cents)) throw Error('المبلغ أكبر من النطاق المسموح');
    return cents;
  }
  function validatePayment(amount, dueAmount, previousPayments) {
    const cents = toCents(amount);
    const due = toCents(dueAmount);
    if (!Array.isArray(previousPayments)) throw Error('بيانات الدفعات السابقة غير صحيحة');
    const alreadyPaid = previousPayments.reduce((sum, p) => sum + toCents(p), 0);
    const remaining = due - alreadyPaid;
    if (cents <= 0 || cents > remaining)
      throw Error('المبلغ يجب أن يكون أكبر من صفر ولا يتجاوز المتبقي من القسط');
    return cents / 100;
  }
  return { addMonthsClamped, createInstallments, toCents, validatePayment };
});
