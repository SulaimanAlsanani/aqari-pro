'use strict';
// Isolated public-demo browser acceptance: owner -> property -> unit -> tenant
// -> contract -> receipt -> expense -> filtered financial reports.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const KEY = 'aqari-pro-gh-v1';
const fixture = {
  owners: [{id:1,name:'مالك اختبار',phone:'0500000000',fee:0}],
  properties: [], units: [], tenants: [], contracts: [], dues: [], payments: [],
  expenses: [], vendors: [], tickets: [], documents: [], inspections: [],
  tasks: [], payouts: [], audit: [], ejarLogs: []
};

(async () => {
  const browser = await chromium.launch({headless:true,args:['--no-sandbox']});
  try {
    const context = await browser.newContext();
    await context.addInitScript(({key,data}) => {
      if (!localStorage.getItem('aqari-portfolio-test-loaded')) {
        localStorage.setItem(key, JSON.stringify(data));
        localStorage.setItem('aqari-portfolio-test-loaded','1');
      }
    }, {key:KEY,data:fixture});
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    const state = () => page.evaluate(key => JSON.parse(localStorage.getItem(key)), KEY);
    await page.goto('http://127.0.0.1:8765/', {waitUntil:'domcontentloaded'});

    async function add(kind, fields) {
      await page.locator('[data-page="'+kind+'"]').click();
      await page.locator('[data-do="add"][data-kind="'+kind+'"]').click();
      const form = page.locator('#editForm');
      for (const [name,value] of Object.entries(fields)) {
        const field = form.locator('[name="'+name+'"]');
        if (await field.evaluate(el => el.tagName === 'SELECT')) {
          await field.selectOption(String(value));
        } else {
          await field.fill(String(value));
        }
      }
      await form.locator('button[type="submit"]').click();
      await form.waitFor({state:'detached',timeout:8000});
    }

    await add('properties',{name:'عمارة الاختبار',city:'جدة',district:'الشاطئ',type:'سكني'});
    let db = await state();
    assert.equal(db.properties.length,1);
    assert.equal(db.properties[0].ownerId,1);
    const propertyId = db.properties[0].id;

    await add('units',{propertyId,type:'شقة',code:'A-101',floor:'الأول',area:105});
    db = await state();
    assert.equal(db.units.length,1);
    assert.equal(Number(db.units[0].propertyId),propertyId);
    const unitId = db.units[0].id;

    await add('tenants',{name:'مستأجر الاختبار',phone:'0501234567'});
    db = await state();
    assert.equal(db.tenants.length,1);
    const tenantId = db.tenants[0].id;

    await add('contracts',{unitId,tenantId,start:'2027-01-31',end:'2027-12-31',total:1200,installments:12,deposit:100});
    db = await state();
    assert.equal(db.contracts.length,1);
    assert.equal(Number(db.contracts[0].unitId),unitId);
    assert.equal(Number(db.contracts[0].tenantId),tenantId);
    assert.equal(db.dues.length,12);
    assert.equal(db.dues[1].date,'2027-02-28');
    assert.equal(db.dues.reduce((s,d)=>s+Math.round(d.amount*100),0),120000);

    await page.locator('[data-page="dues"]').click();
    await page.locator('#propertyFilter').selectOption(String(propertyId));
    assert.equal(await page.locator('.record[data-attach-entity="dues"]').count(),12);
    await page.locator('.record[data-attach-entity="dues"]').first().locator('[data-do="pay"]').click();
    const payForm = page.locator('#editForm');
    await payForm.locator('[name="amount"]').fill('40.25');
    await payForm.locator('[name="date"]').fill('2027-02-02');
    await payForm.locator('[name="method"]').selectOption('bank_transfer');
    await payForm.locator('[name="reference"]').fill('DEMO-RECEIPT');
    await payForm.locator('button[type="submit"]').click();
    await payForm.waitFor({state:'detached'});
    db = await state();
    assert.equal(db.payments.length,1);
    assert.equal(Math.round(db.payments[0].amount*100),4025);

    await add('expenses',{propertyId,category:'صيانة',description:'صيانة تجريبية',amount:'10.25',date:'2027-02-03'});
    db = await state();
    assert.equal(db.expenses.length,1);
    assert.equal(Number(db.expenses[0].propertyId),propertyId);

    // Duplicating exactly the same expense must be rejected before it reaches localStorage.
    await page.locator('[data-page="expenses"]').click();
    await page.locator('[data-do="add"][data-kind="expenses"]').click();
    const duplicateExpenseForm=page.locator('#editForm');
    await duplicateExpenseForm.locator('[name="propertyId"]').selectOption(String(propertyId));
    await duplicateExpenseForm.locator('[name="category"]').fill('صيانة');
    await duplicateExpenseForm.locator('[name="description"]').fill('صيانة تجريبية');
    await duplicateExpenseForm.locator('[name="amount"]').fill('10.25');
    await duplicateExpenseForm.locator('[name="date"]').fill('2027-02-03');
    const duplicateExpenseAlert=new Promise(resolve=>page.once('dialog',async dialog=>{
      const message=dialog.message();
      await dialog.accept();
      resolve(message);
    }));
    await duplicateExpenseForm.locator('button[type="submit"]').click();
    assert.match(await duplicateExpenseAlert,/مصروف مطابق/);
    assert.equal((await state()).expenses.length,1);
    await page.locator('#modal [data-do="close"]').click();

    // Identical expense details with two independent invoice references are legitimate.
    await add('expenses',{propertyId,category:'صيانة',description:'صيانة تجريبية',amount:'10.25',date:'2027-02-03',invoiceReference:'INV-2027-002'});
    assert.equal((await state()).expenses.length,2);
    await page.locator('[data-page="expenses"]').click();
    await page.locator('[data-do="add"][data-kind="expenses"]').click();
    const invoiceForm=page.locator('#editForm');
    await invoiceForm.locator('[name="propertyId"]').selectOption(String(propertyId));
    await invoiceForm.locator('[name="category"]').fill('صيانة');
    await invoiceForm.locator('[name="description"]').fill('صيانة تجريبية');
    await invoiceForm.locator('[name="amount"]').fill('10.25');
    await invoiceForm.locator('[name="date"]').fill('2027-02-03');
    await invoiceForm.locator('[name="invoiceReference"]').fill(' inv-2027-002 ');
    const invoiceAlert=new Promise(resolve=>page.once('dialog',async d=>{const message=d.message();await d.accept();resolve(message)}));
    await invoiceForm.locator('button[type="submit"]').click();
    assert.match(await invoiceAlert,/مرجع فاتورة/);
    assert.equal((await state()).expenses.length,2);
    await page.locator('#modal [data-do="close"]').click();

    // Invoice field must be visible, and invoice-specific filtering must not conflate similar expenses.
    await page.locator('[data-page="expenses"]').click();
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),2);
    await page.locator('#expenseInvoiceFilter').fill('inv-2027-002');
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),1);
    assert.ok((await page.locator('.record[data-attach-entity="expenses"]').innerText()).includes('INV-2027-002'));
    await page.locator('#findText').fill('صيانة تجريبية');
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),1);
    await page.locator('#expenseInvoiceFilter').fill('INV-NOT-FOUND');
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),0);
    await page.locator('#expenseInvoiceFilter').fill('');
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),2);
    await page.locator('#findText').fill('');

    await page.locator('[data-page="reports"]').click();
    await page.locator('#propertyFilter').selectOption(String(propertyId));
    const reports = await page.locator('#content').innerText();
    assert.ok(reports.includes('1,200'), 'Rent dues total missing');
    assert.ok(reports.includes('40.25'), 'Receipt missing');
    assert.ok(reports.includes('20.5'), 'Two independently referenced expenses missing');
    assert.ok(reports.includes('19.75'), 'Net cash flow missing');

    // A different property must not show the first property's financial results.
    await add('properties',{name:'عقار منفصل',city:'جدة',district:'الروضة',type:'سكني'});
    db = await state();
    assert.equal(db.properties.length,2);
    const secondId = db.properties[1].id;

    // Reject duplicate unit codes in the same property, even with different casing.
    await page.locator('[data-page="units"]').click();
    await page.locator('[data-do="add"][data-kind="units"]').click();
    await page.locator('#editForm [name="propertyId"]').selectOption(String(propertyId));
    await page.locator('#editForm [name="code"]').fill(' a-101 ');
    await page.locator('#editForm [name="type"]').fill('شقة');
    await page.locator('#editForm [name="floor"]').fill('الأول');
    await page.locator('#editForm [name="area"]').fill('90');
    const errors=[];
    page.on('dialog',async dialog=>{errors.push(dialog.message());await dialog.accept()});
    await page.locator('#editForm button[type="submit"]').click();
    assert.ok(errors.some(message=>message.includes('مكرر')));
    assert.equal((await state()).units.length,1);
    await page.locator('#modal [data-do="close"]').click();

    // Inspection form filters units by property; no cross-property option is available.
    await page.locator('[data-page="inspections"]').click();
    await page.locator('[data-do="add"][data-kind="inspections"]').click();
    await page.locator('#editForm [name="propertyId"]').selectOption(String(secondId));
    assert.equal(await page.locator('#editForm [name="unitId"]').isDisabled(),true);
    assert.deepEqual(await page.locator('#editForm [name="unitId"] option').evaluateAll(options=>options.map(x=>x.value)),['']);
    assert.equal((await state()).inspections.length,0);
    await page.locator('#editForm [name="propertyId"]').selectOption(String(propertyId));
    assert.equal(await page.locator('#editForm [name="unitId"]').isEnabled(),true);
    assert.deepEqual(await page.locator('#editForm [name="unitId"] option').evaluateAll(options=>options.map(x=>x.value)),[String(unitId)]);
    await page.locator('#modal [data-do="close"]').click();

    // Contract form scopes its units to the selected property too.
    await page.locator('[data-page="contracts"]').click();
    await page.locator('[data-do="add"][data-kind="contracts"]').click();
    await page.locator('#editForm [name="contractPropertyId"]').selectOption(String(secondId));
    assert.equal(await page.locator('#editForm [name="unitId"]').isDisabled(),true);
    await page.locator('#editForm [name="contractPropertyId"]').selectOption(String(propertyId));
    assert.deepEqual(await page.locator('#editForm [name="unitId"] option').evaluateAll(options=>options.map(x=>x.value)),[String(unitId)]);
    await page.locator('#modal [data-do="close"]').click();

    // Add a unit to the second property and check both forms offer only that unit.
    await add('units',{propertyId:secondId,type:'شقة',code:'B-201',floor:'الثاني',area:90});
    const secondUnitId=(await state()).units[1].id;
    await page.locator('[data-page="inspections"]').click();
    await page.locator('[data-do="add"][data-kind="inspections"]').click();
    await page.locator('#editForm [name="propertyId"]').selectOption(String(secondId));
    assert.deepEqual(await page.locator('#editForm [name="unitId"] option').evaluateAll(options=>options.map(x=>x.value)),[String(secondUnitId)]);
    await page.locator('#modal [data-do="close"]').click();

    await page.locator('[data-page="reports"]').click();
    await page.locator('#propertyFilter').selectOption(String(secondId));
    const secondReport = await page.locator('#content').innerText();
    assert.ok(secondReport.includes('عقار منفصل'));
    const visibleProperties = await page.locator('#content .record').allTextContents();
    assert.ok(!visibleProperties.some(text => text.includes('عمارة الاختبار')), 'Property filter leaked first property into report rows');
    assert.ok(!secondReport.includes('1,200'), 'Property filter leaked financial totals');
    assert.ok(!secondReport.includes('40.25'), 'Property filter leaked receipt');

    await page.reload({waitUntil:'domcontentloaded'});
    db = await state();
    assert.equal(db.properties.length,2);
    assert.equal(db.contracts.length,1);
    assert.equal(db.payments.length,1);
    assert.deepEqual(pageErrors, []);
    console.log('PASS: property, unit, tenant, contract, partial receipt, expense, financial reports, property filtering, reload persistence');
  } finally {
    await browser.close();
  }
})().catch(error => {console.error(error);process.exitCode=1});
