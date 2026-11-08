'use strict';
// Isolated public-demo browser acceptance: owner -> property -> unit -> tenant
// -> contract -> receipt -> expense -> filtered financial reports.
const assert = require('node:assert/strict');
const {readFile}=require('node:fs/promises');
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
    const context = await browser.newContext({acceptDownloads:true});
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
    const filteredDownloadEvent=page.waitForEvent('download');
    await page.locator('[data-do="expenseCsv"]').click();
    const filteredDownload=await filteredDownloadEvent;
    assert.equal(filteredDownload.suggestedFilename(),'aqari-expenses.csv');
    const filteredCsv=await readFile(await filteredDownload.path(),'utf8');
    assert.ok(filteredCsv.startsWith('\uFEFF'));
    assert.ok(filteredCsv.includes('عمارة الاختبار'));
    assert.ok(filteredCsv.includes('INV-2027-002'));
    assert.equal(filteredCsv.trimEnd().split('\r\n').length,2,'Invoice filter must export exactly one row');
    await page.locator('#findText').fill('صيانة تجريبية');
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),1);
    await page.locator('#expenseInvoiceFilter').fill('INV-NOT-FOUND');
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),0);
    const zeroEvent=page.waitForEvent('download');
    await page.locator('[data-do="expenseCsv"]').click();
    const zeroFile=await zeroEvent;
    const zeroCsv=await readFile(await zeroFile.path(),'utf8');
    assert.equal(zeroCsv.trimEnd().split('\r\n').length,1,'Empty filtered result must contain only headers');
    await page.locator('#expenseInvoiceFilter').fill('');
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),2);
    await page.locator('#findText').fill('');
    const allEvent=page.waitForEvent('download');
    await page.locator('[data-do="expenseCsv"]').click();
    const allFile=await allEvent;
    const allCsv=await readFile(await allFile.path(),'utf8');
    assert.equal(allCsv.trimEnd().split('\r\n').length,3,'Cleared filters must export both expenses');
    const ownerSummary=page.locator('#expenseGroupedSummary');
    assert.equal(await ownerSummary.locator('[data-expense-property]').count(),1);
    assert.match(await ownerSummary.innerText(),/عمارة الاختبار/);
    assert.match(await ownerSummary.locator('#expenseGroupedTotal').innerText(),/20.5/);
    const groupedDownloadEvent=page.waitForEvent('download');
    await page.locator('[data-do="expenseSummaryCsv"]').click();
    const groupedDownload=await groupedDownloadEvent;
    assert.equal(groupedDownload.suggestedFilename(),'aqari-expenses-by-property.csv');
    const groupedCsv=await readFile(await groupedDownload.path(),'utf8');
    assert.ok(groupedCsv.startsWith('\uFEFF'));
    assert.ok(groupedCsv.includes('"عمارة الاختبار","2","20.50"'));
    assert.ok(groupedCsv.includes('"الإجمالي الكلي","2","20.50"'));

    // Date and amount ranges apply equally to screen, cent-based total and CSV.
    await page.locator('#expenseFrom').fill('2027-02-04');
    await page.locator('#expenseFrom').dispatchEvent('change');
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),0);
    assert.match(await page.locator('#expenseFilteredSummary').innerText(),/0.*0/);
    await page.locator('#expenseFrom').fill('2027-02-03');
    await page.locator('#expenseFrom').dispatchEvent('change');
    await page.locator('#expenseTo').fill('2027-02-03');
    await page.locator('#expenseTo').dispatchEvent('change');
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),2);
    assert.ok((await page.locator('#expenseFilteredSummary').innerText()).includes('20.5'));

    await page.locator('#expenseMin').fill('10.26');
    await page.locator('#expenseMin').dispatchEvent('change');
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),0);
    await page.locator('#expenseMin').fill('10.25');
    await page.locator('#expenseMin').dispatchEvent('change');
    await page.locator('#expenseMax').fill('10.25');
    await page.locator('#expenseMax').dispatchEvent('change');
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),2);
    await page.locator('#expenseInvoiceFilter').fill('INV-2027-002');
    assert.equal(await page.locator('.record[data-attach-entity="expenses"]').count(),1);
    assert.ok((await page.locator('#expenseFilteredSummary').innerText()).includes('10.25'));
    const boundedEvent=page.waitForEvent('download');
    await page.locator('[data-do="expenseCsv"]').click();
    const boundedFile=await boundedEvent;
    const boundedCsv=await readFile(await boundedFile.path(),'utf8');
    assert.equal(boundedCsv.trimEnd().split('\r\n').length,2);
    assert.ok(boundedCsv.includes('INV-2027-002'));
    assert.equal(await page.locator('#expenseGroupedSummary [data-expense-property]').count(),1);
    assert.match(await page.locator('#expenseGroupedTotal').innerText(),/10.25/);
    const oneGroupEvent=page.waitForEvent('download');
    await page.locator('[data-do="expenseSummaryCsv"]').click();
    const oneGroupFile=await oneGroupEvent;
    const oneGroupCsv=await readFile(await oneGroupFile.path(),'utf8');
    assert.ok(oneGroupCsv.includes('"الإجمالي الكلي","1","10.25"'));

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
    await add('expenses',{propertyId:secondId,category:'خدمات',description:'فاتورة ثانية',amount:'8.50',date:'2027-02-05'});
    await page.locator('[data-page="reports"]').click();
    await page.locator('#propertyFilter').selectOption('all');
    assert.equal(await page.locator('#cashComparisonPanel [data-cash-property]').count(),2);
    // Aging uses actual payments through the cutoff, not later receipts or future dues.
    await page.locator('#agingAsOf').fill('2027-02-03');
    await page.locator('#agingAsOf').dispatchEvent('change');
    assert.equal(await page.locator('#agingReportPanel [data-aging-property]').count(),2);
    assert.ok((await page.locator('#agingReportTotal').innerText()).includes('59.75'));
    const agingExportEvent=page.waitForEvent('download');
    await page.locator('[data-do="agingCsv"]').click();
    const agingFile=await agingExportEvent;
    assert.equal(agingFile.suggestedFilename(),'aqari-aging-report.csv');
    const agingCsv=await readFile(await agingFile.path(),'utf8');
    assert.ok(agingCsv.startsWith('\uFEFF'));
    assert.ok(agingCsv.includes('"عمارة الاختبار","59.75","0.00","0.00","0.00","59.75"'));
    assert.ok(agingCsv.includes('"عقار منفصل","0.00","0.00","0.00","0.00","0.00"'));
    assert.equal(await page.locator('#agingDetails [data-aging-detail]').count(),1);
    const agingDetailText=await page.locator('#agingDetails [data-aging-detail]').first().innerText();
    assert.ok(agingDetailText.includes('A-101'));
    assert.ok(agingDetailText.includes('مستأجر الاختبار'));
    assert.ok(agingDetailText.includes('2027-01-31'));
    assert.ok(agingDetailText.includes('59.75'));
    const detailDownloadEvent=page.waitForEvent('download');
    await page.locator('[data-do="agingDetailsCsv"]').click();
    const detailFile=await detailDownloadEvent;
    assert.equal(detailFile.suggestedFilename(),'aqari-aging-installments.csv');
    const detailCsv=await readFile(await detailFile.path(),'utf8');
    assert.ok(detailCsv.startsWith('\uFEFF'));
    assert.ok(detailCsv.includes('مستأجر الاختبار'));
    assert.ok(detailCsv.includes('A-101'));
    assert.ok(detailCsv.includes('"59.75"'));
    assert.equal(detailCsv.trimEnd().split('\r\n').length,2);
    // Tenant, unit and aging-category filters must affect both displayed detail and CSV.
    await page.locator('#agingTenantFilter').fill('not a tenant');
    assert.equal(await page.locator('#agingDetails [data-aging-detail]').count(),0);
    assert.ok((await page.locator('#agingFilteredTotal').innerText()).includes('0'));
    const noneEvent=page.waitForEvent('download');
    await page.locator('[data-do="agingDetailsCsv"]').click();
    const noneFile=await noneEvent;
    const noneCsv=await readFile(await noneFile.path(),'utf8');
    assert.equal(noneCsv.trimEnd().split('\r\n').length,1);
    await page.locator('#agingTenantFilter').fill('مستأجر الاختبار');
    await page.locator('#agingUnitFilter').fill('a-101');
    assert.equal(await page.locator('#agingDetails [data-aging-detail]').count(),1);
    assert.ok((await page.locator('#agingFilteredTotal').innerText()).includes('59.75'));
    await page.locator('#agingBucketFilter').selectOption('days31to60');
    assert.equal(await page.locator('#agingDetails [data-aging-detail]').count(),0);
    await page.locator('#agingBucketFilter').selectOption('days1to30');
    assert.equal(await page.locator('#agingDetails [data-aging-detail]').count(),1);
    const matchingEvent=page.waitForEvent('download');
    await page.locator('[data-do="agingDetailsCsv"]').click();
    const matchingFile=await matchingEvent;
    const matchingCsv=await readFile(await matchingFile.path(),'utf8');
    assert.equal(matchingCsv.trimEnd().split('\r\n').length,2);
    assert.ok(matchingCsv.includes('مستأجر الاختبار'));
    await page.locator('#cashFrom').fill('2027-02-01');
    await page.locator('#cashFrom').dispatchEvent('change');
    await page.locator('#cashTo').fill('2027-02-04');
    await page.locator('#cashTo').dispatchEvent('change');
    const compared=await page.locator('#cashComparisonPanel').innerText();
    assert.ok(compared.includes('عمارة الاختبار'));
    assert.ok(compared.includes('عقار منفصل'));
    assert.ok(compared.includes('40.25'));
    assert.ok(compared.includes('20.5'));
    assert.ok(!compared.includes('8.5'), 'Expense after selected period leaked into comparison');
    assert.ok((await page.locator('#cashComparisonTotal').innerText()).includes('19.75'));
    assert.equal(await page.locator('#propertyKpiPanel [data-kpi-property]').count(),2);
    const kpiAll=await page.locator('#propertyKpiPanel').innerText();
    assert.ok(kpiAll.includes('عمارة الاختبار'));
    assert.ok(kpiAll.includes('عقار منفصل'));
    assert.ok(kpiAll.includes('نسبة التحصيل'));
    assert.ok(kpiAll.includes('الرصيد غير المحصل'));
    const comparisonDownloadEvent=page.waitForEvent('download');
    await page.locator('[data-do="cashFlowCsv"]').click();
    const comparisonDownload=await comparisonDownloadEvent;
    assert.equal(comparisonDownload.suggestedFilename(),'aqari-cash-flow-comparison.csv');
    const comparisonCsv=await readFile(await comparisonDownload.path(),'utf8');
    assert.ok(comparisonCsv.startsWith('\uFEFF'));
    assert.ok(comparisonCsv.includes('"عمارة الاختبار","40.25","20.50","19.75"'));
    assert.ok(comparisonCsv.includes('"عقار منفصل","0.00","0.00","0.00"'));
    await page.locator('#propertyFilter').selectOption(String(secondId));
    assert.equal(await page.locator('#cashComparisonPanel [data-cash-property]').count(),1);
    assert.equal(await page.locator('#propertyKpiPanel [data-kpi-property]').count(),1);
    assert.equal(await page.locator('#agingReportPanel [data-aging-property]').count(),1);
    assert.equal(await page.locator('#agingDetails [data-aging-detail]').count(),0);
    assert.ok(!(await page.locator('#agingReportPanel').innerText()).includes('عمارة الاختبار'));
    assert.ok(!(await page.locator('#propertyKpiPanel').innerText()).includes('عمارة الاختبار'));
    assert.ok(!(await page.locator('#cashComparisonPanel').innerText()).includes('عمارة الاختبار'));
    await page.locator('#cashTo').fill('2027-02-05');
    await page.locator('#cashTo').dispatchEvent('change');
    assert.ok((await page.locator('#cashComparisonTotal').innerText()).includes('8.5'));
    const negativeDownloadEvent=page.waitForEvent('download');
    await page.locator('[data-do="cashFlowCsv"]').click();
    const negativeDownload=await negativeDownloadEvent;
    const negativeCsv=await readFile(await negativeDownload.path(),'utf8');
    assert.ok(negativeCsv.includes('"عقار منفصل","0.00","8.50","-8.50"'));

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
