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
