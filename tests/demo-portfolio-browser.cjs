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

    await page.locator('[data-page="reports"]').click();
    await page.locator('#propertyFilter').selectOption(String(propertyId));
    const reports = await page.locator('#content').innerText();
    assert.ok(reports.includes('1,200'), 'Rent dues total missing');
    assert.ok(reports.includes('40.25'), 'Receipt missing');
    assert.ok(reports.includes('10.25'), 'Expense missing');
    assert.ok(reports.includes('30'), 'Net cash flow missing');

    // A different property must not show the first property's financial results.
    await add('properties',{name:'عقار منفصل',city:'جدة',district:'الروضة',type:'سكني'});
    db = await state();
    assert.equal(db.properties.length,2);
    const secondId = db.properties[1].id;
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
