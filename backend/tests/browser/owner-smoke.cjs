'use strict';
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const { readFile } = require('node:fs/promises');

const BASE = process.env.BROWSER_BASE_URL || 'http://127.0.0.1:8082';
const EMAIL = 'browser.owner@example.test';
const PASSWORD = 'ci-browser-password-2026';

async function main() {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH || undefined });
  try {
    for (const viewport of [{ width: 1366, height: 820 }, { width: 390, height: 844 }]) {
      const page = await browser.newPage({ viewport, locale: 'ar-SA' });
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      const response = await page.goto(BASE + '/app/index.html', { waitUntil: 'domcontentloaded' });
      assert.equal(response.status(), 200);
      assert.equal(await page.locator('html').getAttribute('dir'), 'rtl');

      await page.locator('#email').fill(EMAIL);
      await page.locator('#password').fill(PASSWORD);
      await page.locator('#loginForm button[type=submit]').click();
      await page.locator('#workspace').waitFor({ state: 'visible', timeout: 15000 });
      await page.locator('#view .metric').first().waitFor({ timeout: 15000 });
      assert.equal(await page.locator('#loginPanel').isVisible(), false, 'login panel hidden');

      await page.locator('#nav button').filter({ hasText: 'العقارات' }).click();
      await page.locator('#sectionTitle').getByText('العقارات').waitFor();
      await page.locator('#view button').filter({ hasText: '+ إضافة سجل' }).click();
      await page.locator('#field-name').fill('عقار اختبار المتصفح ' + viewport.width);
      await page.locator('#field-city').fill('الرياض');
      await page.locator('#field-district').fill('الملز');
      await page.locator('#view form button[type=submit]').click();

      await page.locator('#view td').filter({ hasText: 'عقار اختبار المتصفح ' + viewport.width }).first().waitFor({ timeout: 15000 });
      assert.equal(await page.locator('#notice.error').count(), 0, 'no form error');

      // Continue the owner's actual workflow using the same visible forms.
      const propertyRow = page.locator('#view tbody tr').filter({ hasText: 'عقار اختبار المتصفح ' + viewport.width });
      const propertyId = (await propertyRow.locator('td').first().innerText()).trim();
      assert.match(propertyId, /^[0-9]+$/);

      // Exercise the real owner attachment UI: upload, preview and delete.
      await propertyRow.getByRole('button', { name: /المرفقات/ }).click();
      await page.getByText('مرفقات السجل #' + propertyId).waitFor({ timeout: 15000 });
      await page.getByRole('button', { name: /إضافة مرفق/ }).click();
      // An allowed extension beyond the 10 MiB limit must be rejected by the API.
      await page.locator('#view input[type=file]').setInputFiles({
        name: 'oversized.pdf', mimeType: 'application/pdf',
        buffer: Buffer.alloc(10241 * 1024)
      });
      await page.getByRole('button', { name: 'رفع الملف' }).click();
      await page.locator('#notice.error').waitFor({ state: 'visible', timeout: 15000 });
      assert.equal(await page.locator('#view input[type=file]').isVisible(), true, 'failed upload remains editable');
      const attachmentName = 'inspection-' + viewport.width + '.png';
      const png = Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==',
        'base64'
      );
      await page.locator('#view input[type=file]').setInputFiles({
        name: attachmentName, mimeType: 'image/png', buffer: png
      });
      await page.getByRole('button', { name: 'رفع الملف' }).click();
      await page.getByText('📄 ' + attachmentName + ' — تم الإرفاق').waitFor({ timeout: 15000 });
      assert.equal(await page.locator('#view input[type=file]').isVisible(), false, 'upload picker closes after save');
      await page.getByRole('button', { name: 'معاينة' }).click();
      const preview = page.getByRole('dialog', { name: 'معاينة ' + attachmentName });
      await preview.waitFor({ state: 'visible', timeout: 15000 });
      assert.match(await preview.locator('img').getAttribute('src'), /^blob:/);
      await preview.getByRole('button', { name: 'إغلاق المعاينة' }).click();
      await preview.waitFor({ state: 'detached', timeout: 15000 });
      page.once('dialog', dialog => dialog.accept());
      await page.getByRole('button', { name: 'حذف', exact: true }).click();
      await page.getByText('لا توجد ملفات مرفقة بهذا السجل.').waitFor({ timeout: 15000 });
      assert.equal(await page.locator('#notice.error').count(), 0, 'attachment operations succeeded');
      await page.getByRole('button', { name: 'الرجوع' }).click();

      await page.locator('#nav button').filter({ hasText: 'الوحدات' }).click();
      await page.locator('#sectionTitle').getByText('الوحدات').waitFor();
      await page.locator('#view button').filter({ hasText: '+ إضافة سجل' }).click();
      await page.locator('#field-property_id').locator('..')
        .getByRole('button', { name: 'اختيار من السجلات' }).click();
      const propertyPicker = page.getByRole('dialog', { name: 'اختيار رقم العقار' });
      await propertyPicker.locator('input[type=search]').fill('عقار اختبار المتصفح ' + viewport.width);
      await propertyPicker.getByRole('button', { name: new RegExp('عقار اختبار المتصفح ' + viewport.width) })
        .click();
      assert.equal(await page.locator('#field-property_id').inputValue(), propertyId);
      await page.locator('#field-code').fill('U-' + viewport.width);
      await page.locator('#field-type').fill('apartment');
      await page.locator('#view form button[type=submit]').click();
      const unitRow = page.locator('#view tbody tr').filter({ hasText: 'U-' + viewport.width });
      await unitRow.waitFor({ timeout: 15000 });
      const unitId = (await unitRow.locator('td').first().innerText()).trim();
      assert.match(unitId, /^[0-9]+$/);

      await page.locator('#nav button').filter({ hasText: 'المستأجرون' }).click();
      await page.locator('#sectionTitle').getByText('المستأجرون').waitFor();
      await page.locator('#view button').filter({ hasText: '+ إضافة سجل' }).click();
      await page.locator('#field-name').fill('مستأجر اختبار المتصفح ' + viewport.width);
      await page.locator('#field-phone').fill('0500000000');
      await page.locator('#view form button[type=submit]').click();
      const tenantRow = page.locator('#view tbody tr').filter({ hasText: 'مستأجر اختبار المتصفح ' + viewport.width });
      await tenantRow.waitFor({ timeout: 15000 });
      const tenantId = (await tenantRow.locator('td').first().innerText()).trim();
      assert.match(tenantId, /^[0-9]+$/);

      await page.locator('#nav button').filter({ hasText: 'العقود' }).click();
      await page.locator('#sectionTitle').getByText('العقود').waitFor();
      await page.locator('#view button').filter({ hasText: '+ إضافة سجل' }).click();
      await page.locator('#field-unit_id').locator('..')
        .getByRole('button', { name: 'اختيار من السجلات' }).click();
      const unitPicker = page.getByRole('dialog', { name: 'اختيار رقم الوحدة' });
      await unitPicker.locator('input[type=search]').fill('U-' + viewport.width);
      await unitPicker.getByRole('button', { name: new RegExp('U-' + viewport.width) }).click();
      assert.equal(await page.locator('#field-unit_id').inputValue(), unitId);
      await page.locator('#field-tenant_id').locator('..')
        .getByRole('button', { name: 'اختيار من السجلات' }).click();
      const tenantPicker = page.getByRole('dialog', { name: 'اختيار رقم المستأجر' });
      await tenantPicker.locator('input[type=search]').fill('مستأجر اختبار المتصفح ' + viewport.width);
      await tenantPicker.getByRole('button', { name: new RegExp('مستأجر اختبار المتصفح ' + viewport.width) }).click();
      assert.equal(await page.locator('#field-tenant_id').inputValue(), tenantId);
      await page.locator('#field-starts_on').fill('2025-01-01');
      await page.locator('#field-ends_on').fill('2025-12-31');
      await page.locator('#field-total_amount').fill('1200');
      await page.locator('#field-installments').selectOption('12');
      await page.locator('#view form button[type=submit]').click();
      const leaseRow = page.locator('#view tbody tr').first();
      await leaseRow.waitFor({ timeout: 15000 });
      assert.equal((await leaseRow.locator('td').nth(1).innerText()).trim(), unitId);
      assert.equal((await leaseRow.locator('td').nth(2).innerText()).trim(), tenantId);
      const leaseId = (await leaseRow.locator('td').first().innerText()).trim();
      assert.match(leaseId, /^[0-9]+$/);
      page.once('dialog', dialog => dialog.accept());
      await leaseRow.getByRole('button', { name: 'تفعيل', exact: true }).click();
      await page.locator('#view tbody tr').filter({ hasText: leaseId }).getByText('active', { exact: true }).waitFor({ timeout: 15000 });

      await page.locator('#nav button').filter({ hasText: 'المقبوضات' }).click();
      await page.locator('#sectionTitle').getByText('المقبوضات').waitFor();
      await page.locator('#view button').filter({ hasText: '+ إضافة سجل' }).click();
      await page.locator('#field-lease_id').locator('..')
        .getByRole('button', { name: 'اختيار من السجلات' }).click();
      const leasePicker = page.getByRole('dialog', { name: 'اختيار رقم العقد' });
      await leasePicker.locator('input[type=search]').fill(leaseId);
      await leasePicker.getByRole('button', { name: new RegExp('عقد #' + leaseId + ' ') }).click();
      assert.equal(await page.locator('#field-lease_id').inputValue(), leaseId);
      await page.locator('#field-amount').fill('600');
      await page.locator('#field-paid_on').fill('2025-12-31');
      await page.locator('#field-method').selectOption('bank_transfer');
      await page.locator('#view form button[type=submit]').click();
      const paymentRows = page.locator('#view tbody tr');
      await paymentRows.first().waitFor({ timeout: 15000 });
      let matchedPayment = false;
      for (let idx = 0; idx < await paymentRows.count(); idx++) {
        const cells = paymentRows.nth(idx).locator('td');
        if ((await cells.nth(1).innerText()).trim() !== leaseId) continue;
        assert.equal((await cells.nth(2).innerText()).trim(), '600.00');
        matchedPayment = true;
        break;
      }
      assert.ok(matchedPayment, 'payment belongs to the newly created lease');
      assert.equal(await page.locator('#notice.error').count(), 0, 'no record creation errors');

      // Maintenance form must require a selected property before it offers units.
      await page.locator('#nav button').filter({ hasText: 'الصيانة' }).click();
      await page.locator('#sectionTitle').getByText('الصيانة').waitFor();
      await page.locator('#view button').filter({ hasText: '+ إضافة سجل' }).click();
      const maintenanceUnitPicker = page.locator('#field-unit_id').locator('..')
        .getByRole('button', { name: 'اختيار من السجلات' });
      await maintenanceUnitPicker.click();
      assert.equal(await page.getByRole('dialog', { name: 'اختيار رقم الوحدة' }).count(), 0,
        'maintenance unit picker needs a property first');
      await page.locator('#field-property_id').fill(propertyId);
      await maintenanceUnitPicker.click();
      const maintenanceDialog = page.getByRole('dialog', { name: 'اختيار رقم الوحدة' });
      await maintenanceDialog.locator('input[type=search]').fill('U-' + viewport.width);
      await maintenanceDialog.getByRole('button', { name: new RegExp('U-' + viewport.width) }).click();
      assert.equal(await page.locator('#field-unit_id').inputValue(), unitId);
      await page.locator('#field-property_id').fill('');
      assert.equal(await page.locator('#field-unit_id').inputValue(), '',
        'changing the maintenance property must clear the previous unit');
      await page.locator('#field-property_id').fill(propertyId);
      await maintenanceUnitPicker.click();
      const updatedDialog = page.getByRole('dialog', { name: 'اختيار رقم الوحدة' });
      await updatedDialog.locator('input[type=search]').fill('U-' + viewport.width);
      await updatedDialog.getByRole('button', { name: new RegExp('U-' + viewport.width) }).click();
      assert.equal(await page.locator('#field-unit_id').inputValue(), unitId);
      await page.locator('#field-title').fill('بلاغ صيانة تجريبي ' + viewport.width);
      await page.locator('#field-priority').selectOption('normal');
      await page.locator('#view form button[type=submit]').click();
      await page.locator('#view td').filter({ hasText: 'بلاغ صيانة تجريبي ' + viewport.width }).first()
        .waitFor({ timeout: 15000 });
      assert.equal(await page.locator('#notice.error').count(), 0,
        'scoped maintenance ticket submitted without error');

      // This synthetic lease ended in 2025, so its unit must be vacant in
      // the current occupancy snapshot even though the lease is still active.
      await page.locator('#nav button').filter({ hasText: 'الإشغال والشواغر' }).click();
      await page.locator('#view h3').first().getByText('الإشغال والشواغر').waitFor({timeout:15000});
      assert.equal(await page.locator('#view .metrics .metric').count(),6,
        'occupancy report includes six current-state indicators');
      const occupancyRow=page.locator('#view table').first().locator('tbody tr')
        .filter({ hasText: 'عقار اختبار المتصفح ' + viewport.width });
      await occupancyRow.waitFor({timeout:15000});
      assert.equal((await occupancyRow.locator('td').nth(2).innerText()).trim(),'1');
      assert.equal((await occupancyRow.locator('td').nth(3).innerText()).trim(),'0');
      assert.equal((await occupancyRow.locator('td').nth(4).innerText()).trim(),'1');
      await page.locator('#occupancy-property-id').fill(propertyId);
      await page.locator('#occupancy-apply').click();
      await page.getByText('العقار #' + propertyId, {exact:true})
        .waitFor({timeout:15000});
      assert.equal(await page.locator('#view .metrics .metric').count(),6);
      assert.equal(await page.locator('#notice.error').count(),0,
        'occupancy drilldown must not show a server or browser error');

      const agingResponse=page.waitForResponse(response=>
        response.url().includes('/api/reports/receivables')
        && response.request().method()==='GET',{timeout:15000});
      await page.locator('#nav button').filter({ hasText: 'أعمار المتأخرات' }).click();
      assert.equal((await agingResponse).status(),200,
        'owner aging screen must not hit rate limits after occupancy navigation');
      await page.getByText('أعمار الذمم المدينة — الإيجارات').waitFor({ timeout: 15000 });
      assert.equal(await page.locator('#view .metrics .metric').count(), 7,
        'aging report includes all current and overdue money buckets');
      await page.getByText('المتأخرات بحسب العقار').waitFor({timeout:15000});
      // The synthetic lease is only half paid: the owner must see 600.00
      // remaining, and the property breakdown must reconcile to that amount.
      const agingProperty = page.locator('#view table').first().locator('tbody tr')
        .filter({ hasText: 'عقار اختبار المتصفح ' + viewport.width });
      await agingProperty.waitFor({timeout:15000});
      assert.equal((await agingProperty.locator('td').nth(1).innerText()).trim(), '600.00');
      assert.equal((await agingProperty.locator('td').nth(3).innerText()).trim(), '600.00');
      await page.locator('#aging-property-id').fill(propertyId);
      await page.locator('#aging-apply').click();
      await page.getByText('العقار #' + propertyId, { exact: true })
        .waitFor({ timeout: 15000 });
      assert.equal(await page.locator('#view .metrics .metric').count(), 7,
        'the property-scoped report retains its seven accounting metrics');

      // Download all current unpaid dues in CSV, scoped to the chosen property.
      const exportResponse = page.waitForResponse(response =>
        response.url().includes('/api/exports/receivables-aging?property_id=' + propertyId)
        && response.request().method() === 'GET', {timeout:15000});
      // Attach a rejection handler immediately: otherwise a missed download
      // event can crash Node before the HTTP failure is reported.
      const downloadPromise = page.waitForEvent('download', {timeout:20000})
        .catch(() => null);
      await page.locator('#aging-export').click();
      const csvResponse = await exportResponse;
      assert.equal(csvResponse.status(),200,
        'owner CSV export endpoint must return a valid downloadable response');
      assert.equal(await page.locator('#notice.error').count(),0,
        'the export UI must not show an API error');
      const downloaded = await downloadPromise;
      assert.ok(downloaded, 'CSV HTTP succeeded but the browser did not start a download');
      assert.match(downloaded.suggestedFilename(), /^aqari-receivables-aging-[0-9-]+\.csv$/);
      const csvContents = await readFile(await downloaded.path(), 'utf8');
      assert.ok(csvContents.startsWith('\uFEFF'), 'CSV must include UTF-8 BOM for Arabic Excel');
      assert.ok(csvContents.includes('تاريخ التقرير'), 'CSV must contain Arabic accounting headers');
      assert.ok(csvContents.includes('المتبقي'), 'CSV must include outstanding balance column');
      assert.ok(csvContents.includes('100.00'), 'CSV must contain actual uncollected due balances');
      // One Arabic header and six unpaid monthly installments (600/12).
      assert.equal(csvContents.trim().split(/\r?\n/).length, 7,
        'download must include all six remaining installments rather than just a dashboard preview');

      assert.equal(await page.locator('#notice.error').count(), 0,
        'aging report loads without a server or browser error');

      await page.locator('#nav button').filter({ hasText: 'أرصدة التأمينات' }).click();
      await page.getByText('أرصدة تأمينات المستأجرين').waitFor({ timeout: 15000 });
      await page.locator('#nav button').filter({ hasText: 'مطابقة الحسابات' }).click();
      await page.getByText('فحص التوافق بين الأقساط والمقبوضات').waitFor({ timeout: 15000 });

      await page.locator('#logout').click();
      await page.locator('#loginPanel').waitFor({ state: 'visible', timeout: 15000 });
      assert.equal(await page.locator('#workspace').isVisible(), false);
      assert.deepEqual(errors, [], 'no uncaught browser JavaScript exceptions');
      console.log('PASS browser owner workflow viewport=' + viewport.width);
      await page.close();
    }
  } finally {
    await browser.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
