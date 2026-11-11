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
    $('ownerLabel').textContent = (profile.owner?.name || 'حساب مالك غير مهيأ') + ' — ' + (profile.user?.email || '');
  }
  function logoutView() {
    if ($('editDialog').open) $('editDialog').close();
    editTarget = null;
    $('workspace').hidden = true;
    $('loginPanel').hidden = false;
    $('loginForm').reset();
    properties = [];
    for (const id of ['propertyList','unitList','tenantList','unitProperty','propertyPager','unitPager','tenantPager']) $(id).replaceChildren();
    pages.property = pages.unit = pages.tenant = 1;
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
  async function refreshAll() {
    const profile = await api.me();
    showSession(profile);
    await Promise.all([refreshProperties(), refreshTenants()]);
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
    }
  }
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
      status('تم حفظ العقار على الخادم');
    });
  });
  $('unitForm').addEventListener('submit', e => {
    e.preventDefault();
    task(async () => {
      await api.createUnit($('unitProperty').value, fromForm(e.target));
      e.target.reset();
      await refreshUnits();
      status('تم حفظ الوحدة على الخادم');
    });
  });
  $('tenantForm').addEventListener('submit', e => {
    e.preventDefault();
    task(async () => {
      await api.createTenant(fromForm(e.target));
      e.target.reset();
      await refreshTenants();
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
