/* Staging-only UI. Never include in public GitHub Pages bundle. */
(() => {
 'use strict';
 const $ = id => document.getElementById(id);
 const api = window.AqariApiSession.create({
   csrfToken: () => {
     const item = document.cookie.split('; ').find(x => x.startsWith('XSRF-TOKEN='));
     return item ? item.slice('XSRF-TOKEN='.length) : '';
   }
 });
 let properties = [];
 let busy = false;
 function status(message, error=false) {
   $('status').textContent = message;
   $('status').style.color = error ? '#a32b28' : '#235e79';
 }
 function item(list, label, details) {
   const li = document.createElement('li');
   const name = document.createElement('strong');
   name.textContent = label;
   li.appendChild(name);
   if (details) {
     const span = document.createElement('small');
     span.textContent = details;
     li.appendChild(span);
   }
   list.appendChild(li);
 }
 function formData(form) {
   const values = Object.fromEntries(new FormData(form));
   for (const key of Object.keys(values)) if (values[key] === '') delete values[key];
   return values;
 }
 function showSession(profile) {
   $('workspace').hidden = false;
   $('loginPanel').hidden = true;
   $('ownerLabel').textContent = (profile.owner?.name || 'حساب مالك غير مهيأ') + ' — ' + (profile.user?.email || '');
 }
 function logoutView() {
   $('workspace').hidden = true;
   $('loginPanel').hidden = false;
   $('loginForm').reset();
   properties = [];
   for (const id of ['propertyList','unitList','tenantList','unitProperty']) $(id).replaceChildren();
 }
 async function refreshProperties() {
   const response = await api.properties();
   properties = response.data || [];
   $('propertyList').replaceChildren();
   $('unitProperty').replaceChildren();
   for (const property of properties) {
     item($('propertyList'), property.name, property.city);
     const option = document.createElement('option');
     option.value = String(property.id);
     option.textContent = property.name;
     $('unitProperty').appendChild(option);
   }
   $('unitForm').querySelector('button').disabled = properties.length === 0;
   await refreshUnits();
 }
 async function refreshUnits() {
   $('unitList').replaceChildren();
   const id = $('unitProperty').value;
   if (!id) return;
   const response = await api.units(id);
   for (const unit of (response.data || [])) item($('unitList'), unit.code, unit.type || '');
 }
 async function refreshTenants() {
   const response = await api.tenants();
   $('tenantList').replaceChildren();
   for (const tenant of (response.data || [])) item($('tenantList'), tenant.name, tenant.phone || '');
 }
 async function refreshAll() {
   const profile = await api.me();
   showSession(profile);
   await Promise.all([refreshProperties(), refreshTenants()]);
   status('متصل ببيئة Staging — البيانات من Laravel API (أول صفحة من السجلات)');
 }
 async function task(action) {
   if (busy) return;
   busy = true;
   for (const button of document.querySelectorAll('button')) button.disabled = true;
   try { await action(); }
   catch (error) {
     if (error.status === 401 || error.status === 419) logoutView();
     status(error.message || 'تعذر الاتصال بالخادم', true);
   } finally {
     busy = false;
     for (const button of document.querySelectorAll('button')) button.disabled = false;
     $('unitForm').querySelector('button').disabled = properties.length === 0;
   }
 }
 $('loginForm').addEventListener('submit', e => {
   e.preventDefault();
   task(async () => {
     const v = formData(e.target);
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
 $('unitProperty').addEventListener('change', () => task(refreshUnits));
 $('propertyForm').addEventListener('submit', e => {
   e.preventDefault();
   task(async () => {
     await api.createProperty(formData(e.target));
     e.target.reset();
     await refreshProperties();
     status('تم حفظ العقار على الخادم');
   });
 });
 $('unitForm').addEventListener('submit', e => {
   e.preventDefault();
   task(async () => {
     await api.createUnit($('unitProperty').value, formData(e.target));
     e.target.reset();
     await refreshUnits();
     status('تم حفظ الوحدة على الخادم');
   });
 });
 $('tenantForm').addEventListener('submit', e => {
   e.preventDefault();
   task(async () => {
     await api.createTenant(formData(e.target));
     e.target.reset();
     await refreshTenants();
     status('تم حفظ المستأجر على الخادم');
   });
 });
 api.me().then(refreshAll).catch(() => logoutView());
})();
