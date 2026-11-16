'use strict';
const { chromium, devices } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const origin = process.env.AQARI_BROWSER_URL || 'http://127.0.0.1:8765/';
  const scenarios = [
    { name: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true },
    { name: 'desktop', viewport: { width: 1440, height: 900 } },
  ];
  try {
    for (const scenario of scenarios) {
      const context = await browser.newContext({ ...scenario, locale: 'ar-SA' });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(origin, { waitUntil: 'load' });
      await page.locator('#nav [data-page="properties"]').click();
      assert.equal(await page.locator('#title').innerText(), 'عقاراتي');
      assert.equal(await page.locator('#nav [data-page="properties"]').getAttribute('aria-current'), 'page');
      await page.locator('#content [data-do="add"][data-kind="properties"]').first().click();
      assert.equal(await page.locator('#editForm').count(), 1);
      await page.locator('#modal [data-do="close"]').first().click();
      assert.equal(await page.locator('#editForm').count(), 0);
      for (const [section, title] of [
        ['units', 'الوحدات'], ['contracts', 'العقود'], ['payments', 'سندات القبض'],
        ['expenses', 'المصروفات'], ['tickets', 'طلبات الصيانة']
      ]) {
        await page.locator('#nav [data-page="' + section + '"]').click();
        assert.equal(await page.locator('#title').innerText(), title, scenario.name + ' ' + section);
        assert.ok(await page.locator('#content').isVisible());
      }
      if (scenario.name === 'mobile') {
        const width = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        assert.ok(width <= 2, 'mobile document overflowed viewport by ' + width + 'px');
      }
      assert.deepEqual(errors, [], scenario.name + ' uncaught page errors');
      const storageKey = await page.evaluate(() => window.localStorage.getItem('aqari-pro-gh-v1'));
      // Navigation and opening/closing blank forms must not overwrite existing storage.
      await page.reload({ waitUntil: 'load' });
      assert.equal(await page.evaluate(() => window.localStorage.getItem('aqari-pro-gh-v1')), storageKey);
      console.log('PASS: ' + scenario.name + ' navigation, dialog dismissal, local storage stability');
      await context.close();
    }
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
