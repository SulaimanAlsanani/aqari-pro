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
      // Mutations happen only in this throwaway browser context.
      // Verify that a newly created property can own a unit and both survive reload.
      const propertyName = 'عقار اختبار Chromium ' + scenario.name;
      const unitCode = 'CI-' + scenario.name;
      await page.locator('#nav [data-page="properties"]').click();
      const before = await page.evaluate(() => {
        const raw = localStorage.getItem('aqari-pro-gh-v1');
        const parsed = raw ? JSON.parse(raw) : null;
        return parsed ? { properties: parsed.properties.length, units: parsed.units.length } : null;
      });
      await page.locator('#content [data-do="add"][data-kind="properties"]').click();
      await page.locator('#editForm [name="name"]').fill(propertyName);
      await page.locator('#editForm [name="city"]').fill('الرياض');
      await page.locator('#editForm [name="district"]').fill('اختبار');
      await page.locator('#editForm [name="type"]').fill('سكني');
      await page.locator('#editForm button[type="submit"]').click();
      assert.equal(await page.locator('#editForm').count(), 0, 'property form closed on save');
      const createdProperty = await page.evaluate(name => {
        const db = JSON.parse(localStorage.getItem('aqari-pro-gh-v1'));
        return db.properties.find(x => x.name === name);
      }, propertyName);
      assert.ok(createdProperty, 'property saved to isolated browser storage');
      assert.equal(Number(createdProperty.ownerId), 1);

      await page.locator('#nav [data-page="units"]').click();
      await page.locator('#content [data-do="add"][data-kind="units"]').click();
      await page.locator('#editForm [name="propertyId"]').selectOption(String(createdProperty.id));
      await page.locator('#editForm [name="code"]').fill(unitCode);
      await page.locator('#editForm [name="type"]').fill('شقة');
      await page.locator('#editForm [name="floor"]').fill('الأول');
      await page.locator('#editForm [name="area"]').fill('100');
      await page.locator('#editForm button[type="submit"]').click();
      const persisted = await page.evaluate(({ propertyName, unitCode }) => {
        const db = JSON.parse(localStorage.getItem('aqari-pro-gh-v1'));
        const property = db.properties.find(x => x.name === propertyName);
        const unit = db.units.find(x => x.code === unitCode && String(x.propertyId) === String(property?.id));
        return { property: !!property, unit: !!unit, properties: db.properties.length, units: db.units.length };
      }, { propertyName, unitCode });
      assert.ok(persisted.property && persisted.unit, 'unit is linked to created property');
      if (before) {
        assert.equal(persisted.properties, before.properties + 1);
        assert.equal(persisted.units, before.units + 1);
      }
      await page.reload({ waitUntil: 'load' });
      const afterReload = await page.evaluate(({ propertyName, unitCode }) => {
        const db = JSON.parse(localStorage.getItem('aqari-pro-gh-v1'));
        const property = db.properties.find(x => x.name === propertyName);
        return !!property && db.units.some(x => x.code === unitCode && String(x.propertyId) === String(property.id));
      }, { propertyName, unitCode });
      assert.ok(afterReload, 'property and unit must survive reload');
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
