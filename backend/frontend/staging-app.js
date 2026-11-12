/* Staging-only first-party application. Never publish to public GitHub Pages. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const api = window.AqariApiSession.create({
    csrfToken: () => {
      const cookie = document.cookie.split('; ').find(x => x.startsWith('XSRF-TOKEN='));
      return cookie ? cookie.slice('XSRF-TOKEN='.length) : '';
    }
  });
  const pages = {property: 1, unit: 1, tenant: 1};
  let properties = [];
  let busy = false;
  let editTarget = null;
  let finance = null;
  let attachments = null;
  const recovery = new URLSearchParams(window.location.hash.startsWith('#reset=') ? window.location.hash.slice(1) : '');
  let recoveryToken = recovery.get('reset') || null;
  let recoveryEmail = recovery.get('email') || null;
  if (recoveryToken) window.history.replaceState(null, '', window.location.pathname + window.location.search);

  function status(message, error=false) {
    $('status').textContent = message;
    $('status').style.color = error ? '#a32b28' : '#235e79';
  }
  function fromForm(form) {
    const values = Object.fromEntries(new FormData(form));
    for (const key of Object.keys(values)) if (values[key] === '') delete values[key];
    return values;
  }
  function text(tag, value) {
    const element = document.createElement(tag);
    element.textContent = String(value ?? '');
    return element;
  }
  function row(list, record, label, details, type) {
    const li = document.createElement('li');
    const display = document.createElement('div');
    display.append(text('strong', label));
    if (details) display.append(text('small', ' — ' + details));
    li.append(display);
    const actions = document.createElement('div');
    actions.className = 'row-actions';
    const edit = text('button', 'تعديل');
    edit.type = 'button';
    edit.addEventListener('click', () => openEditor(type, record));
    actions.append(edit);
    li.append(actions);
    list.append(li);
  }
  function pager(container, resource, response, refresh) {
    container.replaceChildren();
    const current = Number(response.current_page) || pages[resource];
    const last = Number(response.last_page) || 1;
    pages[resource] = Math.min(Math.max(current, 1), last);
    const previous = text('button', 'السابق');
    previous.type = 'button';
    previous.disabled = pages[resource] <= 1;
    previous.addEventListener('click', () => task(async () => {
      pages[resource]--;
      await refresh();
    }));
    const next = text('button', 'التالي');
    next.type = 'button';
    next.disabled = pages[resource] >= last;
    next.addEventListener('click', () => task(async () => {
      pages[resource]++;
      await refresh();
    }));
    container.append(previous, text('span', 'صفحة ' + pages[resource] + ' من ' + last), next);
  }
  function showSession(profile) {
    $('workspace').hidden = false;
    $('loginPanel').hidden = true;
    $('resetPanel').hidden = true;
    $('ownerLabel').textContent = (profile.owner?.name || 'حساب مالك غير مهيأ') + ' — ' + (profile.user?.email || '');
  }
  function logoutView() {
    if ($('editDialog').open) $('editDialog').close();
    editTarget = null;
    $('workspace').hidden = true;
    $('loginPanel').hidden = Boolean(recoveryToken);
    $('resetPanel').hidden = !recoveryToken;
    $('resetEmailLabel').textContent = recoveryEmail || '';
    $('loginForm').reset();
    $('changePasswordForm').reset();
    $('revokeOthersForm').reset();
    $('activeSessions').replaceChildren();
    $('loginActivity').replaceChildren();
    properties = [];
    for (const id of ['propertyList','unitList','tenantList','unitProperty','propertyPager','unitPager','tenantPager']) $(id).replaceChildren();
    pages.property = pages.unit = pages.tenant = 1;
    if (finance) finance.reset();
    if (attachments) attachments.reset();
  }
  async function refreshProperties() {
    const selected = $('unitProperty').value;
    const response = await api.properties(pages.property);
    properties = response.data || [];
    $('propertyList').replaceChildren();
    $('unitProperty').replaceChildren();
    for (const property of properties) {
      row($('propertyList'), property, property.name, property.city, 'property');
      const option = document.createElement('option');
      option.value = String(property.id);
      option.textContent = property.name;
      $('unitProperty').append(option);
    }
    if (properties.some(property => String(property.id) === selected)) $('unitProperty').value = selected;
    else pages.unit = 1;
    pager($('propertyPager'), 'property', response, refreshProperties);
    await refreshUnits();
  }
  async function refreshUnits() {
    $('unitList').replaceChildren();
    $('unitPager').replaceChildren();
    const propertyId = $('unitProperty').value;
    if (!propertyId) return;
    const response = await api.units(propertyId, pages.unit);
    for (const unit of (response.data || [])) row($('unitList'), unit, unit.code, unit.type || '', 'unit');
    pager($('unitPager'), 'unit', response, refreshUnits);
  }
  async function refreshTenants() {
    const response = await api.tenants(pages.tenant);
    $('tenantList').replaceChildren();
    for (const tenant of (response.data || [])) row($('tenantList'), tenant, tenant.name, tenant.phone || '', 'tenant');
    pager($('tenantPager'), 'tenant', response, refreshTenants);
  }
  async function refreshAccount() {
    const result = await api.account();
    $('accountForm').elements.name.value = result.data.name || '';
    $('accountForm').elements.phone.value = result.data.phone || '';
    $('accountEmail').value = result.data.email || '';
  }
  async function refreshSecuritySessions() {
    const response = await api.securitySessions();
    $('activeSessions').replaceChildren();
    const list = Array.isArray(response.data) ? response.data : [];
    for (const session of list) {
      const line = text('li', session.current ? '● الجهاز الحالي' : 'جهاز آخر');
      const details = text('small', ' — ' + (session.device || 'متصفح غير معروف') +
        ' — ' + (session.ip_address || 'عنوان غير معروف') +
        ' — ' + new Date(Number(session.last_activity) * 1000).toLocaleString('ar-SA'));
      line.append(details);
      $('activeSessions').append(line);
    }
    if (!list.length) $('activeSessions').append(text('li', 'لا توجد جلسات نشطة مسجلة.'));
    const events = Array.isArray(response.recent_activity) ? response.recent_activity : [];
    const names = {
      login_succeeded: 'دخول ناجح',
      login_failed: 'محاولة دخول غير ناجحة',
      logout: 'تسجيل خروج',
      other_sessions_revoked: 'إنهاء الجلسات الأخرى'
    };
    $('loginActivity').replaceChildren();
    for (const event of events) {
      const when = event.occurred_at ? new Date(event.occurred_at).toLocaleString('ar-SA') : '';
      $('loginActivity').append(text('li',
        (names[event.event] || 'حدث أمني') + ' — ' + when +
        ' — ' + (event.ip_address || 'عنوان غير معروف')));
    }
    if (!events.length) $('loginActivity').append(text('li', 'لا توجد أحداث مسجلة.'));
  }
  async function refreshAll() {
    const profile = await api.me();
    showSession(profile);
    await Promise.all([refreshProperties(), refreshTenants(), finance.refresh(), attachments.refresh(), refreshAccount(), refreshSecuritySessions()]);
    status('متصل ببيئة Staging — البيانات من Laravel API');
  }
  function field(label, name, value, required=false) {
    const wrapper = document.createElement('label');
    wrapper.append(text('span', label));
    const input = document.createElement('input');
    input.name = name;
    input.value = value ?? '';
    input.required = required;
    input.maxLength = name === 'phone' ? 30 : name === 'code' || name === 'type' ? 60 : name === 'city' || name === 'district' ? 100 : 180;
    if (name === 'area') {
      input.type = 'number';
      input.step = '0.01';
      input.min = '0';
      input.removeAttribute('maxlength');
    }
    wrapper.append(input);
    return wrapper;
  }
  function openEditor(type, record) {
    editTarget = {type, id: record.id, propertyId: $('unitProperty').value};
    $('editFields').replaceChildren();
    const fields = type === 'property' ?
      [['اسم العقار','name',true],['المدينة','city',true],['الحي','district',false],['النوع','type',false]] :
      type === 'unit' ?
      [['رمز الوحدة','code',true],['نوع الوحدة','type',false],['المساحة','area',false]] :
      [['اسم المستأجر','name',true],['رقم التواصل','phone',false]];
    $('editTitle').textContent = type === 'property' ? 'تعديل العقار' : type === 'unit' ? 'تعديل الوحدة' : 'تعديل المستأجر';
    for (const [label, key, required] of fields) $('editFields').append(field(label, key, record[key], required));
    $('editDialog').showModal();
  }
  async function task(action) {
    if (busy) return;
    busy = true;
    for (const b of document.querySelectorAll('button')) b.disabled = true;
    try { await action(); }
    catch (error) {
      if (error.status === 401 || error.status === 419) logoutView();
      const validation = error.errors && Object.values(error.errors).flat().join('؛ ');
      status(validation || error.message || 'تعذر الاتصال بالخادم', true);
    } finally {
      busy = false;
      for (const b of document.querySelectorAll('button')) b.disabled = false;
      $('unitForm').querySelector('button').disabled = properties.length === 0;
      for (const resource of ['property','unit','tenant']) {
        const pager = $(resource+'Pager');
        const buttons = pager.querySelectorAll('button');
        if (buttons[0]) buttons[0].disabled = pages[resource] <= 1;
        if (buttons[1]) {
          const match = pager.textContent.match(/من\s+(\d+)/);
          buttons[1].disabled = !match || pages[resource] >= Number(match[1]);
        }
      }
      if (finance) finance.syncControls();
      if (attachments) attachments.syncControls();
    }
  }
  attachments = window.AqariAttachmentStaging.create({api, task, status, document});
  finance = window.AqariFinanceStaging.create({api, task, status, document,
    onContractCreated: () => attachments.refreshChoices()});
  $('forgotForm').addEventListener('submit', e => {
    e.preventDefault();
    task(async () => {
      const email = e.target.elements.email.value;
      const result = await api.requestPasswordReset(email);
      e.target.reset();
      status(result.message || 'إذا كان البريد مسجلًا، فستصلك رسالة استعادة كلمة المرور.');
    });
  });
  $('resetForm').addEventListener('submit', e => {
    e.preventDefault();
    if (!recoveryToken || !recoveryEmail) return status('رابط الاستعادة غير صالح.', true);
    task(async () => {
      const data = fromForm(e.target);
      const result = await api.resetPassword({
        token: recoveryToken,
        email: recoveryEmail,
        password: data.password,
        password_confirmation: data.password_confirmation
      });
      recoveryToken = recoveryEmail = null;
      e.target.reset();
      logoutView();
      status(result.message || 'تم تعيين كلمة المرور. سجّل الدخول مجددًا.');
    });
  });
  $('cancelReset').addEventListener('click', () => {
    recoveryToken = recoveryEmail = null;
    $('resetForm').reset();
    logoutView();
    status('يمكنك تسجيل الدخول أو طلب رابط استعادة جديد.');
  });
  $('accountForm').addEventListener('submit', e => {
    e.preventDefault();
    task(async () => {
      await api.updateAccount(fromForm(e.target));
      await refreshAccount();
      const profile = await api.me();
      showSession(profile);
      status('تم حفظ بيانات حساب المالك.');
    });
  });
  $('changePasswordForm').addEventListener('submit', e => {
    e.preventDefault();
    task(async () => {
      const result = await api.changePassword(fromForm(e.target));
      e.target.reset();
      logoutView();
      status(result.message || 'تم تغيير كلمة المرور. سجّل الدخول مجددًا.');
    });
  });
  $('refreshSessions').addEventListener('click', () => task(async () => {
    await refreshSecuritySessions();
    status('تم تحديث قائمة الأجهزة والنشاط الأمني.');
  }));
  $('revokeOthersForm').addEventListener('submit', e => {
    e.preventDefault();
    task(async () => {
      const currentPassword = e.target.elements.current_password.value;
      const result = await api.revokeOtherSessions(currentPassword);
      e.target.reset();
      await refreshSecuritySessions();
      status(result.message || 'تم تسجيل خروج الأجهزة الأخرى.');
    });
  });
  $('loginForm').addEventListener('submit', e => {
    e.preventDefault();
    task(async () => {
      const v = fromForm(e.target);
      await api.login(v.email, v.password);
      e.target.reset();
      await refreshAll();
    });
  });
  $('logout').addEventListener('click', () => task(async () => {
    await api.logout();
    logoutView();
    status('تم تسجيل الخروج');
  }));
  $('refresh').addEventListener('click', () => task(refreshAll));
  $('unitProperty').addEventListener('change', () => {
    pages.unit = 1;
    task(refreshUnits);
  });
  $('propertyForm').addEventListener('submit', e => {
    e.preventDefault();
    task(async () => {
      await api.createProperty(fromForm(e.target));
      e.target.reset();
      await refreshProperties();
      await finance.refreshChoices();
      await attachments.refreshChoices();
      status('تم حفظ العقار على الخادم');
    });
  });
  $('unitForm').addEventListener('submit', e => {
    e.preventDefault();
    task(async () => {
      await api.createUnit($('unitProperty').value, fromForm(e.target));
      e.target.reset();
      await refreshUnits();
      await finance.refreshChoices();
      status('تم حفظ الوحدة على الخادم');
    });
  });
  $('tenantForm').addEventListener('submit', e => {
    e.preventDefault();
    task(async () => {
      await api.createTenant(fromForm(e.target));
      e.target.reset();
      await refreshTenants();
      await finance.refreshChoices();
      status('تم حفظ المستأجر على الخادم');
    });
  });
  $('cancelEdit').addEventListener('click', () => $('editDialog').close());
  $('editDialog').addEventListener('close', () => { editTarget = null; });
  $('editForm').addEventListener('submit', e => {
    e.preventDefault();
    if (!editTarget) return;
    const target = {...editTarget};
    task(async () => {
      const values = fromForm(e.target);
      if (target.type === 'property') {
        await api.updateProperty(target.id, values);
        await refreshProperties();
      } else if (target.type === 'unit') {
        await api.updateUnit(target.propertyId, target.id, values);
        await refreshUnits();
      } else {
        await api.updateTenant(target.id, values);
        await refreshTenants();
      }
      $('editDialog').close();
      status('تم تحديث السجل على الخادم');
    });
  });
  api.me().then(refreshAll).catch(() => logoutView());
})();
