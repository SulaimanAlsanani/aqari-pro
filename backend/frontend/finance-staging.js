/* Aqari Pro financial workflows: staging-only, not published on GitHub Pages. */
(function (root) {
  'use strict';

  function cents(value) {
    const amount = String(value ?? '');
    if (!/^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/.test(amount)) throw new Error('مبلغ غير صالح');
    const [whole, decimals = ''] = amount.split('.');
    return Number(whole) * 100 + Number(decimals.padEnd(2, '0'));
  }

  function localDate() {
    const now = new Date();
    const two = value => String(value).padStart(2, '0');
    return now.getFullYear() + '-' + two(now.getMonth() + 1) + '-' + two(now.getDate());
  }

  function money(value) {
    const abs = Math.abs(value);
    return (value < 0 ? '-' : '') + String(Math.floor(abs / 100)) +
      '.' + String(abs % 100).padStart(2, '0');
  }

  function remaining(due) {
    const posted = (due.payments || []).filter(p => !p.reversed_at)
      .reduce((sum, payment) => sum + cents(payment.amount), 0);
    return Math.max(0, cents(due.amount) - posted);
  }

  function create({api, task, status, document: doc, onContractCreated}) {
    const $ = id => doc.getElementById(id);
    const pages = {contract: 1, payment: 1};
    const totalPages = {contract: 1, payment: 1};
    let currentContract = null;
    let chosenDue = null;
    let reversalId = null;
    let properties = [];
    let tenants = [];

    function element(tag, content, className) {
      const node = doc.createElement(tag);
      if (content != null) node.textContent = String(content);
      if (className) node.className = className;
      return node;
    }

    async function allPages(query) {
      const values = [];
      let total = 1;
      for (let page = 1; page <= total; page++) {
        const response = await query(page);
        const max = Number(response.last_page) || 1;
        if (max > 25) throw new Error('تتجاوز القائمة 500 سجل؛ يلزم دعم البحث الخادمي قبل إكمالها.');
        total = max;
        values.push(...(response.data || []));
      }
      return values;
    }

    function options(select, records, label, placeholder) {
      const selected = select.value;
      select.replaceChildren();
      if (placeholder != null) {
        const empty = element('option', placeholder);
        empty.value = '';
        select.append(empty);
      }
      for (const item of records) {
        const option = element('option', label(item));
        option.value = String(item.id);
        select.append(option);
      }
      if (records.some(item => String(item.id) === selected)) select.value = selected;
    }

    async function refreshUnits() {
      const propertyId = $('contractProperty').value;
      const units = propertyId ? await allPages(page => api.units(propertyId, page)) : [];
      options($('contractUnit'), units, unit => unit.code, 'اختر الوحدة');
    }

    async function refreshChoices() {
      const [newProperties, newTenants] = await Promise.all([
        allPages(page => api.properties(page)),
        allPages(page => api.tenants(page))
      ]);
      properties = newProperties;
      tenants = newTenants;
      options($('contractProperty'), properties, p => p.name + ' — ' + p.city, 'اختر العقار');
      options($('contractTenant'), tenants, t => t.name, 'اختر المستأجر');
      options($('reportProperty'), properties, p => p.name, 'جميع العقارات');
      await refreshUnits();
      syncControls();
    }

    function paginate(resource, response, reload) {
      const container = $(resource + 'Pager');
      container.replaceChildren();
      pages[resource] = Number(response.current_page) || pages[resource];
      totalPages[resource] = Math.max(1, Number(response.last_page) || 1);
      const previous = element('button', 'السابق');
      previous.type = 'button';
      previous.addEventListener('click', () => task(async () => {
        pages[resource]--;
        await reload();
      }));
      const next = element('button', 'التالي');
      next.type = 'button';
      next.addEventListener('click', () => task(async () => {
        pages[resource]++;
        await reload();
      }));
      container.append(previous,
        element('span', 'صفحة ' + pages[resource] + ' من ' + totalPages[resource]), next);
      syncControls();
    }

    async function refreshContracts() {
      const response = await api.contracts(pages.contract);
      $('contractList').replaceChildren();
      for (const contract of response.data || []) {
        const li = element('li');
        const details = element('div');
        details.append(element('strong', 'عقد #' + contract.id),
          element('small', ' · ' + (contract.unit?.code || 'وحدة #' + contract.unit_id) +
            ' · ' + (contract.tenant?.name || 'مستأجر #' + contract.tenant_id) +
            ' · ' + String(contract.total) + ' ر.س'));
        const show = element('button', 'عرض الأقساط');
        show.type = 'button';
        show.addEventListener('click', () => task(() => loadContract(contract.id)));
        li.append(details, show);
        $('contractList').append(li);
      }
      paginate('contract', response, refreshContracts);
    }

    async function loadContract(contractId) {
      const result = await api.contract(contractId);
      const contract = result.data;
      currentContract = contract.id;
      $('contractDetailPanel').hidden = false;
      $('contractDetailNumber').textContent = '#' + contract.id;
      $('contractDetailSummary').textContent = 'الفترة: ' + String(contract.start_date).slice(0, 10) +
        ' — ' + String(contract.end_date).slice(0, 10) + ' | قيمة العقد ' + contract.total + ' ر.س';
      $('dueList').replaceChildren();
      for (const due of contract.dues || []) {
        const remainingCents = remaining(due);
        const li = element('li');
        const info = element('div');
        info.append(element('strong', 'استحقاق #' + due.id + ' — ' + String(due.due_date).slice(0, 10)),
          element('small', ' · المطلوب: ' + due.amount + ' · المتبقي: ' + money(remainingCents) + ' ر.س'));
        const receipts = (due.payments || []).length;
        if (receipts) info.append(element('small', ' · عمليات الدفع المسجلة: ' + receipts + ' (تشمل المعكوسة)'));
        li.append(info);
        if (remainingCents > 0) {
          const collect = element('button', 'تسجيل تحصيل');
          collect.type = 'button';
          collect.addEventListener('click', () => {
            chosenDue = due.id;
            $('postPaymentPanel').hidden = false;
            $('chosenDueId').textContent = '#' + due.id;
            $('postPaymentForm').elements.amount.value = money(remainingCents);
            $('postPaymentForm').elements.received_at.value = localDate();
            $('postPaymentForm').scrollIntoView({behavior: 'smooth', block: 'nearest'});
          });
          li.append(collect);
        }
        $('dueList').append(li);
      }
    }

    async function refreshPayments() {
      const response = await api.payments(pages.payment);
      $('paymentList').replaceChildren();
      for (const payment of response.data || []) {
        const li = element('li');
        const info = element('div');
        info.append(element('strong', 'سند #' + payment.id + ' — ' + payment.amount + ' ر.س'),
          element('small', ' · قسط #' + payment.due_id + ' · ' + payment.method +
            ' · ' + String(payment.received_at).slice(0, 10) +
            (payment.reference ? ' · المرجع ' + payment.reference : '')));
        li.append(info);
        if (payment.reversed_at) {
          li.append(element('small', 'معكوس — لا يدخل في المقبوضات الفعلية'));
        } else {
          const reverse = element('button', 'عكس السند');
          reverse.type = 'button';
          reverse.addEventListener('click', () => {
            reversalId = payment.id;
            $('reversePaymentForm').hidden = false;
            $('reversePaymentId').textContent = '#' + payment.id;
          });
          li.append(reverse);
        }
        $('paymentList').append(li);
      }
      paginate('payment', response, refreshPayments);
    }

    async function refreshReport() {
      const selected = $('reportProperty').value;
      const response = await api.receivables(selected || undefined);
      const labels = {
        due: 'إجمالي الاستحقاقات', collected: 'المقبوض فعليًا',
        outstanding: 'الرصيد المتبقي', overdue: 'إجمالي المتأخر',
        not_due: 'غير مستحق بعد', days_1_30: 'تأخير 1–30 يوم',
        days_31_60: 'تأخير 31–60 يوم', days_61_90: 'تأخير 61–90 يوم',
        days_91_plus: 'تأخير أكثر من 90 يومًا'
      };
      $('receivablesSummary').replaceChildren();
      for (const [key, label] of Object.entries(labels)) {
        const tile = element('div', null, 'stat');
        tile.append(element('small', label),
          element('strong', String(response.amounts?.[key] || '0.00') + ' ر.س'));
        $('receivablesSummary').append(tile);
      }
      $('receivablesDate').textContent = 'التقرير بتاريخ ' + response.as_of +
        ' — عدد الأقساط: ' + response.installments_count;
    }

    function syncControls() {
      for (const key of ['contract', 'payment']) {
        const controls = $(key + 'Pager').querySelectorAll('button');
        if (controls[0]) controls[0].disabled = pages[key] <= 1;
        if (controls[1]) controls[1].disabled = pages[key] >= totalPages[key];
      }
      const canCreate = Boolean($('contractProperty').value && $('contractUnit').value && $('contractTenant').value);
      const submit = $('contractForm').querySelector('button[type="submit"]');
      if (submit) submit.disabled = !canCreate;
    }

    async function refresh() {
      await refreshChoices();
      await Promise.all([refreshContracts(), refreshPayments(), refreshReport()]);
      if (currentContract != null) await loadContract(currentContract);
    }

    function reset() {
      pages.contract = pages.payment = 1;
      totalPages.contract = totalPages.payment = 1;
      currentContract = chosenDue = reversalId = null;
      properties = []; tenants = [];
      for (const id of ['contractList','contractPager','paymentList','paymentPager',
        'dueList','receivablesSummary','contractProperty','contractUnit','contractTenant']) $(id).replaceChildren();
      $('contractDetailPanel').hidden = true;
      $('postPaymentPanel').hidden = true;
      $('reversePaymentForm').hidden = true;
      $('reportProperty').replaceChildren();
      const all = element('option', 'جميع العقارات'); all.value = '';
      $('reportProperty').append(all);
    }

    $('contractProperty').addEventListener('change', () => task(refreshUnits));
    $('reportProperty').addEventListener('change', () => task(refreshReport));
    $('refreshContractOptions').addEventListener('click', () => task(refreshChoices));
    $('contractForm').addEventListener('submit', e => {
      e.preventDefault();
      task(async () => {
        const v = Object.fromEntries(new FormData(e.target));
        // Never send a foreign-key owner field or the display-only property selector.
        const data = {unit_id: v.unit_id, tenant_id: v.tenant_id, start_date: v.start_date,
          end_date: v.end_date, total: v.total, installments: Number(v.installments)};
        const result = await api.createContract(data);
        pages.contract = 1;
        await refreshContracts();
        await loadContract(result.data.id);
        if (onContractCreated) await onContractCreated();
        e.target.reset();
        await refreshUnits();
        status('تم إنشاء مسودة العقد وجدول الأقساط على الخادم');
      });
    });
    $('postPaymentForm').addEventListener('submit', e => {
      e.preventDefault();
      if (chosenDue == null) return;
      const dueId = chosenDue;
      task(async () => {
        const data = Object.fromEntries(new FormData(e.target));
        await api.postPayment(dueId, data);
        chosenDue = null;
        $('postPaymentPanel').hidden = true;
        e.target.reset();
        if (currentContract != null) await loadContract(currentContract);
        await Promise.all([refreshPayments(), refreshReport()]);
        status('تم تسجيل التحصيل في PostgreSQL');
      });
    });
    $('cancelPayment').addEventListener('click', () => {
      chosenDue = null;
      $('postPaymentPanel').hidden = true;
    });
    $('reversePaymentForm').addEventListener('submit', e => {
      e.preventDefault();
      if (reversalId == null) return;
      const paymentId = reversalId;
      task(async () => {
        const reason = e.target.elements.reason.value.trim();
        await api.reversePayment(paymentId, reason);
        reversalId = null;
        $('reversePaymentForm').hidden = true;
        e.target.reset();
        if (currentContract != null) await loadContract(currentContract);
        await Promise.all([refreshPayments(), refreshReport()]);
        status('تم عكس السند مع الحفاظ على سجل التدقيق');
      });
    });
    $('cancelReverse').addEventListener('click', () => {
      reversalId = null;
      $('reversePaymentForm').hidden = true;
    });

    return Object.freeze({refresh, refreshChoices, refreshReport, reset, syncControls});
  }

  root.AqariFinanceStaging = Object.freeze({create, cents, money, remaining});
})(typeof window !== 'undefined' ? window : globalThis);
