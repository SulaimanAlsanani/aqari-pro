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
      // Create a lease only for the newly added unit, then verify due schedule.
      await page.locator('#nav [data-page="contracts"]').click();
      await page.locator('#content [data-do="add"][data-kind="contracts"]').click();
      const newUnit = await page.evaluate(({ propertyName, unitCode }) => {
        const db = JSON.parse(localStorage.getItem('aqari-pro-gh-v1'));
        const property = db.properties.find(x => x.name === propertyName);
        return db.units.find(x => x.code === unitCode && String(x.propertyId) === String(property.id));
      }, { propertyName, unitCode });
      assert.ok(newUnit, 'new unit exists before contract creation');
      await page.locator('#editForm [name="unitId"]').selectOption(String(newUnit.id));
      await page.locator('#editForm [name="start"]').fill('2027-01-01');
      await page.locator('#editForm [name="end"]').fill('2027-12-31');
      await page.locator('#editForm [name="total"]').fill('12000');
      await page.locator('#editForm [name="installments"]').fill('12');
      await page.locator('#editForm [name="deposit"]').fill('1000');
      await page.locator('#editForm button[type="submit"]').click();
      assert.equal(await page.locator('#editForm').count(), 0, 'lease form closed on valid save');
      const leaseResult = await page.evaluate(unitId => {
        const db = JSON.parse(localStorage.getItem('aqari-pro-gh-v1'));
        const lease = db.contracts.find(x => String(x.unitId) === String(unitId));
        return { exists: !!lease, count: db.dues.filter(d => String(d.contractId) === String(lease?.id)).length };
      }, newUnit.id);
      assert.ok(leaseResult.exists, 'new lease persisted');
      assert.equal(leaseResult.count, 12, '12 installment dues generated');

      // Test real receipt workflow against the new lease's first installment.
      // The browser context is disposable; no shared or production data is touched.
      const due = await page.evaluate(unitId => {
        const db = JSON.parse(localStorage.getItem('aqari-pro-gh-v1'));
        const lease = db.contracts.find(x => String(x.unitId) === String(unitId));
        return db.dues.find(x => String(x.contractId) === String(lease.id));
      }, newUnit.id);
      assert.ok(due, 'installment exists for receipt workflow');
      assert.equal(Number(due.amount), 1000);
      await page.locator('#nav [data-page="dues"]').click();
      await page.locator('#propertyFilter').selectOption(String(createdProperty.id));
      const recordSelector = '#content [data-attach-entity="dues"][data-attach-id="' + due.id + '"]';
      const dialogs = [];
      page.on('dialog', async dialog => {
        dialogs.push(dialog.message());
        await dialog.dismiss();
      });
      const paymentCount = () => page.evaluate(dueId => {
        const db = JSON.parse(localStorage.getItem('aqari-pro-gh-v1'));
        const payments = db.payments.filter(x => String(x.dueId) === String(dueId));
        return { count: payments.length, amount: payments.reduce((total, p) => total + Number(p.amount), 0) };
      }, due.id);
      async function attemptPayment(amount, reference) {
        await page.locator(recordSelector + ' [data-do="pay"]').click();
        await page.locator('#editForm [name="amount"]').fill(String(amount));
        await page.locator('#editForm [name="date"]').fill('2027-01-02');
        await page.locator('#editForm [name="method"]').fill('تحويل بنكي');
        await page.locator('#editForm [name="reference"]').fill(reference);
        await page.locator('#editForm button[type="submit"]').click();
      }
      await attemptPayment(400, 'CI-PARTIAL-' + scenario.name);
      assert.deepEqual(await paymentCount(), { count: 1, amount: 400 }, 'partial receipt saved once');
      assert.equal(await page.locator('#editForm').count(), 0);
      await attemptPayment(700, 'CI-EXCESS-' + scenario.name);
      assert.equal(await page.locator('#editForm').count(), 1, 'overpayment form remains open');
      assert.ok(dialogs.some(x => x.includes('يتجاوز الرصيد')), 'overpayment rejected with a warning');
      assert.deepEqual(await paymentCount(), { count: 1, amount: 400 }, 'overpayment cannot mutate receipts');
      await page.locator('#modal [data-do="close"]').click();
      await attemptPayment(100, 'CI-PARTIAL-' + scenario.name);
      assert.ok(dialogs.some(x => x.includes('مرجع الدفع مستخدم')), 'duplicate reference rejected');
      assert.deepEqual(await paymentCount(), { count: 1, amount: 400 }, 'duplicate reference cannot mutate receipts');
      await page.locator('#modal [data-do="close"]').click();
      await attemptPayment(600, 'CI-FINAL-' + scenario.name);
      assert.deepEqual(await paymentCount(), { count: 2, amount: 1000 }, 'installment fully settled without overpayment');
      assert.equal(await page.locator(recordSelector + ' [data-do="pay"]').count(), 0, 'paid installment no longer offers receipt action');
      await page.locator('#nav [data-page="payments"]').click();
      assert.ok(await page.locator('#content [data-attach-entity="payments"]').count() >= 2);
      console.log('PASS: ' + scenario.name + ' partial, duplicate reference, excess, final payment');

      // The same receipts must appear in a property-filtered tenant statement.
      await page.locator('#nav [data-page="statements"]').click();
      await page.locator('#propertyFilter').selectOption(String(createdProperty.id));
      const tenantId = await page.evaluate(unitId => {
        const db = JSON.parse(localStorage.getItem('aqari-pro-gh-v1'));
        return db.contracts.find(x => String(x.unitId) === String(unitId)).tenantId;
      }, newUnit.id);
      await page.locator('#tenantStatementSelection').selectOption(String(tenantId));
      const summary = await page.evaluate(({ tenantId, propertyId }) => {
        const db = JSON.parse(localStorage.getItem('aqari-pro-gh-v1'));
        const result = window.AqariStatements.build(tenantId, db, propertyId);
        return {
          totalDue: result.totalDue,
          totalPaid: result.totalPaid,
          outstanding: result.outstanding,
          firstDuePaid: result.rows.find(x => x.date === '2027-01-01')?.paid
        };
      }, { tenantId, propertyId: String(createdProperty.id) });
      assert.deepEqual(summary, {
        totalDue: 12000, totalPaid: 1000, outstanding: 11000, firstDuePaid: 1000
      }, 'tenant statement reconciles to created lease receipts');
      const downloadEvent = page.waitForEvent('download');
      await page.locator('#content [data-do="statementCsv"]').click();
      const statementFile = await downloadEvent;
      assert.ok(statementFile.suggestedFilename().endsWith('.csv'));
      const csvPath = await statementFile.path();
      const csvText = require('node:fs').readFileSync(csvPath, 'utf8');
      assert.equal(csvText.codePointAt(0), 0xfeff);
      assert.ok(csvText.includes('2027-01-01,1000,1000,0'));
      assert.equal(csvText.trimEnd().split('\r\n').length, 13);

      // Create a real expense and reconcile the property-specific report end to end.
      await page.locator('#nav [data-page="expenses"]').click();
      await page.locator('#content [data-do="add"][data-kind="expenses"]').click();
      await page.locator('#editForm [name="propertyId"]').selectOption(String(createdProperty.id));
      await page.locator('#editForm [name="category"]').fill('صيانة');
      await page.locator('#editForm [name="description"]').fill('مصروف اختبار آلي');
      await page.locator('#editForm [name="amount"]').fill('250');
      await page.locator('#editForm [name="date"]').fill('2027-01-02');
      await page.locator('#editForm button[type="submit"]').click();
      assert.equal(await page.locator('#editForm').count(), 0, 'expense form saved');
      await page.locator('#nav [data-page="reports"]').click();
      await page.locator('#propertyFilter').selectOption(String(createdProperty.id));
      const propertyReport = await page.evaluate(propertyId => {
        const db = JSON.parse(localStorage.getItem('aqari-pro-gh-v1'));
        const x = window.AqariReconciliation.calculate(db, propertyId);
        return { due:x.due, received:x.received, uncollected:x.uncollected, expenses:x.expenses, net:x.net, balanced:x.balanced };
      }, String(createdProperty.id));
      assert.deepEqual(propertyReport, {
        due:12000, received:1000, uncollected:11000,
        expenses:250, net:750, balanced:true
      }, 'property report reconciles dues, receipts, expense and cash flow');
      const reportContent = await page.locator('#content').innerText();
      for (const amount of ['12,000', '11,000', '1,000', '250', '750']) {
        assert.ok(reportContent.includes(amount), 'report visibly includes ' + amount);
      }
      const performanceDownload = page.waitForEvent('download');
      await page.locator('#content [data-do="propertyPerformanceCsv"]').click();
      const performanceFile = await performanceDownload;
      assert.equal(performanceFile.suggestedFilename(), 'aqari-property-performance.csv');
      const performanceCsv = require('node:fs').readFileSync(await performanceFile.path(), 'utf8');
      assert.equal(performanceCsv.codePointAt(0), 0xfeff);
      assert.ok(performanceCsv.includes('12000,1000,11000,250,750'));
      assert.equal(performanceCsv.trimEnd().split('\r\n').length,2);

      await page.reload({ waitUntil: 'load' });
      const afterReload = await page.evaluate(({ propertyName, unitCode }) => {
        const db = JSON.parse(localStorage.getItem('aqari-pro-gh-v1'));
        const property = db.properties.find(x => x.name === propertyName);
        const unit = db.units.find(x => x.code === unitCode && String(x.propertyId) === String(property?.id));
        const lease = db.contracts.find(x => String(x.unitId) === String(unit?.id));
        const dues = db.dues.filter(x => String(x.contractId) === String(lease?.id));
        const firstDue = dues.find(x => x.date === '2027-01-01');
        const paid = db.payments.filter(x => String(x.dueId) === String(firstDue?.id));
        return {
          property: !!property, unit: !!unit, lease: !!lease,
          dues: dues.length, receipts: paid.length,
          collected: paid.reduce((sum, p) => sum + Number(p.amount), 0)
        };
      }, { propertyName, unitCode });
      assert.deepEqual(afterReload, {
        property: true, unit: true, lease: true,
        dues: 12, receipts: 2, collected: 1000
      }, 'property, unit, contract and receipts must survive reload');
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
