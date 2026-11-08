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
    const cents = toCents(total);
    if (cents <= 0 || cents < count)
      throw Error('قيمة العقد لا تكفي لتوزيع الأقساط');
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
  function createLeaseSchedule(start, end, total, count) {
    const startValid = addMonthsClamped(start, 0);
    const endValid = addMonthsClamped(end, 0);
    if (startValid > endValid) throw Error('نهاية العقد يجب أن تكون بعد تاريخ بدايته أو مساوية له');
    const installments = createInstallments(start, total, count);
    if (installments.some(item => item.date > end))
      throw Error('تاريخ استحقاق أحد الأقساط يتجاوز نهاية العقد؛ عدّل المدة أو عدد الأقساط');
    return installments;
  }
  function validateReceiptReference(reference, previousPayments) {
    if(typeof reference!=='string'||!reference.trim())throw Error('مرجع التحصيل مطلوب');
    if(!Array.isArray(previousPayments))throw Error('بيانات المقبوضات السابقة غير صالحة');
    const normalized=reference.trim().toLowerCase();
    if(previousPayments.some(p=>String(p.reference??'').trim().toLowerCase()===normalized))
      throw Error('مرجع سند القبض مستخدم سابقًا؛ أدخل مرجعًا فريدًا');
    return reference.trim();
  }
  function expenseFingerprint(expense) {
    const property=Number(expense?.propertyId);
    const date=String(expense?.date??'').trim();
    const category=String(expense?.category??'').trim().toLowerCase().replace(/\s+/g,' ');
    const description=String(expense?.description??'').trim().toLowerCase().replace(/\s+/g,' ');
    if(!Number.isSafeInteger(property)||property<=0||!/^(?:\d{4})-\d{2}-\d{2}$/.test(date)
      ||!category||!description)throw Error('بيانات المصروف غير مكتملة');
    const amount=toCents(expense?.amount);
    if(amount<=0)throw Error('قيمة المصروف يجب أن تكون أكبر من صفر');
    return JSON.stringify([property,date,category,description,amount]);
  }
  function validateUniqueExpense(expense, previousExpenses) {
    if(!Array.isArray(previousExpenses))throw Error('سجل المصروفات غير صالح');
    const signature=expenseFingerprint(expense);
    const ref=String(expense.invoiceReference??'').trim().toLowerCase();
    if(ref){
      if(previousExpenses.some(previous=>Number(previous.propertyId)===Number(expense.propertyId)
        && String(previous.invoiceReference??'').trim().toLowerCase()===ref))
        throw Error('مرجع فاتورة المصروف مستخدم سابقًا في هذا العقار');
    }else if(previousExpenses.some(previous=>!String(previous.invoiceReference??'').trim()
      && expenseFingerprint(previous)===signature)){
      throw Error('يوجد مصروف مطابق مسجل مسبقًا لهذا العقار في اليوم نفسه');
    }
    return true;
  }
  function nextRecordId(records) {
    if (!Array.isArray(records)) throw Error('مجموعة السجلات غير صالحة');
    let maximum = 0;
    for (const record of records) {
      const id = Number(record?.id);
      if (Number.isSafeInteger(id) && id > maximum) maximum = id;
    }
    if (!Number.isSafeInteger(maximum + 1)) throw Error('تم بلوغ الحد الأقصى لمعرفات السجلات');
    return maximum + 1;
  }
  function validateRecordRelation(kind, record, portfolio) {
    if (!portfolio || typeof portfolio !== 'object') throw Error('بيانات المحفظة غير متاحة');
    const exists = (collection, id) => Array.isArray(portfolio[collection])
      && portfolio[collection].some(x => Number(x.id) === Number(id) && Number.isSafeInteger(Number(id)) && Number(id) > 0);
    if (['units','expenses','tickets','documents','inspections'].includes(kind)
        && !exists('properties',record.propertyId))
      throw Error('يجب اختيار عقار موجود');
    if (kind === 'inspections') {
      if (!exists('units',record.unitId)) throw Error('يجب اختيار وحدة موجودة');
      const unit = portfolio.units.find(x => Number(x.id) === Number(record.unitId));
      if (Number(unit.propertyId) !== Number(record.propertyId))
        throw Error('الوحدة المختارة لا تتبع العقار المحدد');
    }
    if (kind === 'contracts') {
      if (!exists('units',record.unitId)) throw Error('يجب اختيار وحدة موجودة');
      if (!exists('tenants',record.tenantId)) throw Error('يجب اختيار مستأجر موجود');
    }
    if (kind === 'units') {
      const code = String(record.code ?? '').trim().toLowerCase();
      if (!code) throw Error('رقم الوحدة مطلوب');
      if (portfolio.units.some(x => Number(x.propertyId) === Number(record.propertyId)
        && String(x.code ?? '').trim().toLowerCase() === code))
        throw Error('رقم الوحدة مكرر داخل العقار');
    }
    return true;
  }
  return { addMonthsClamped, createInstallments, createLeaseSchedule, toCents, validatePayment, validateReceiptReference, expenseFingerprint, validateUniqueExpense, nextRecordId, validateRecordRelation };
});
