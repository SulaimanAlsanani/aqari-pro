'use strict';
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

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
      assert.equal(await page.locator('#view input[type=file]').count(), 0, 'upload picker closes after save');
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
      await page.locator('#field-property_id').fill(propertyId);
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
      await page.locator('#field-unit_id').fill(unitId);
      await page.locator('#field-tenant_id').fill(tenantId);
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
      await page.locator('#field-lease_id').fill(leaseId);
      await page.locator('#field-amount').fill('1200');
      await page.locator('#field-paid_on').fill('2025-12-31');
      await page.locator('#field-method').selectOption('bank_transfer');
      await page.locator('#view form button[type=submit]').click();
      const paymentRows = page.locator('#view tbody tr');
      await paymentRows.first().waitFor({ timeout: 15000 });
      let matchedPayment = false;
      for (let idx = 0; idx < await paymentRows.count(); idx++) {
        const cells = paymentRows.nth(idx).locator('td');
        if ((await cells.nth(1).innerText()).trim() !== leaseId) continue;
        assert.equal((await cells.nth(2).innerText()).trim(), '1200.00');
        matchedPayment = true;
        break;
      }
      assert.ok(matchedPayment, 'payment belongs to the newly created lease');
      assert.equal(await page.locator('#notice.error').count(), 0, 'no record creation errors');

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
