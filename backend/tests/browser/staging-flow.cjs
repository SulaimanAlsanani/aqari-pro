/* Browser E2E: actual HTTPS proxy + Laravel/Sanctum + PostgreSQL + staging UI.
 * Only synthetic CI data are used. Requires the loopback-only CI Caddy listener.
 */
const assert = require('node:assert/strict');
const {chromium} = require('playwright');

const origin = 'https://staging.example.test:18443';
const password = process.env.AQARI_E2E_PASSWORD;
if (!password || password.length < 12) throw new Error('Missing synthetic E2E credentials');

function retryStatus(page, expected, timeout = 25000) {
  return page.waitForFunction(value =>
    document.querySelector('#status')?.textContent?.includes(value), expected, {timeout});
}

(async () => {
  const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: {width: 1365, height: 900},
    locale: 'ar-SA',
  });
  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(error.message));
  page.on('response', response => {
    if (response.status() >= 500) pageErrors.push('HTTP '+ response.status() + ' ' + response.url());
  });

  try {
    await page.goto(origin + '/staging/', {waitUntil: 'domcontentloaded'});
    await page.locator('#loginForm').waitFor({state: 'visible'});
    const guest = await page.evaluate(async () => {
      const result = await fetch('/api/v1/properties', {
        credentials: 'include', headers: {'Accept': 'application/json'}
      });
      return result.status;
    });
    assert.equal(guest, 401, 'Guest must be denied before logging in');

    await page.locator('#loginForm input[name=email]').fill('owner.e2e@example.test');
    await page.locator('#loginForm input[name=password]').fill(password);
    await page.locator('#loginForm button[type=submit]').click();
    await retryStatus(page, 'متصل ببيئة');
    assert.equal(await page.locator('#workspace').isVisible(), true);
    assert.match(await page.locator('#ownerLabel').innerText(), /owner\.e2e@example\.test/);

    const isolation = await page.evaluate(async () => {
      const hidden = await fetch('/api/v1/properties/1', {
        credentials: 'include', headers: {'Accept': 'application/json'}
      });
      const csrfMissing = await fetch('/api/v1/properties', {
        method: 'POST', credentials: 'include',
        headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
        body: JSON.stringify({name: 'FORGED', city: 'Riyadh'})
      });
      return {hidden: hidden.status, csrfMissing: csrfMissing.status};
    });
    assert.equal(isolation.hidden, 404, 'Owner must not read another portfolio');
    assert.equal(isolation.csrfMissing, 419, 'Stateful API must require CSRF on writes');
    assert.equal(await page.locator('#propertyList').innerText(), '');

    await page.locator('#propertyForm input[name=name]').fill('E2E Building');
    await page.locator('#propertyForm input[name=city]').fill('Riyadh');
    await page.locator('#propertyForm input[name=district]').fill('Al Olaya');
    await page.locator('#propertyForm button[type=submit]').click();
    await retryStatus(page, 'تم حفظ العقار');
    assert.match(await page.locator('#propertyList').innerText(), /E2E Building/);

    await page.locator('#unitForm input[name=code]').fill('A-101');
    await page.locator('#unitForm input[name=type]').fill('apartment');
    await page.locator('#unitForm button[type=submit]').click();
    await retryStatus(page, 'تم حفظ الوحدة');
    assert.match(await page.locator('#unitList').innerText(), /A-101/);

    await page.locator('#tenantForm input[name=name]').fill('E2E Tenant');
    await page.locator('#tenantForm input[name=phone]').fill('0500000000');
    await page.locator('#tenantForm button[type=submit]').click();
    await retryStatus(page, 'تم حفظ المستأجر');
    assert.match(await page.locator('#tenantList').innerText(), /E2E Tenant/);

    await page.locator('#contractProperty').selectOption({label: 'E2E Building — Riyadh'});
    await page.waitForFunction(() =>
      Array.from(document.querySelector('#contractUnit').options)
        .some(option => option.textContent === 'A-101'));
    await page.locator('#contractUnit').selectOption({label: 'A-101'});
    await page.locator('#contractTenant').selectOption({label: 'E2E Tenant'});
    await page.locator('#contractForm input[name=start_date]').fill('2026-11-01');
    await page.locator('#contractForm input[name=end_date]').fill('2027-10-31');
    await page.locator('#contractForm input[name=total]').fill('1200.00');
    await page.locator('#contractForm input[name=installments]').fill('12');
    await page.locator('#contractForm button[type=submit]').click();
    await retryStatus(page, 'تم إنشاء مسودة العقد');
    assert.equal(await page.locator('#dueList li').count(), 12);

    await page.locator('#dueList li').first().getByRole('button', {name: 'تسجيل تحصيل'}).click();
    await page.locator('#postPaymentForm input[name=amount]').fill('40.00');
    await page.locator('#postPaymentForm input[name=received_at]').fill('2026-11-11');
    await page.locator('#postPaymentForm input[name=reference]').fill('E2E-RECEIPT-001');
    await page.locator('#postPaymentForm button[type=submit]').click();
    await retryStatus(page, 'تم تسجيل التحصيل');
    assert.match(await page.locator('#paymentList').innerText(), /40\.00/);
    assert.match(await page.locator('#receivablesSummary').innerText(), /40\.00/);

    await page.locator('#logout').click();
    await retryStatus(page, 'تم تسجيل الخروج');
    assert.equal(await page.locator('#workspace').isVisible(), false);
    const afterLogout = await page.evaluate(async () => {
      const response = await fetch('/api/v1/properties', {
        credentials: 'include', headers: {'Accept': 'application/json'}
      });
      return response.status;
    });
    assert.equal(afterLogout, 401, 'Logout must invalidate authenticated API access');
    assert.deepEqual(pageErrors, [], 'No browser exceptions or server 5xx responses');
    console.log('Browser E2E passed: login, CSRF, owner isolation, property, unit, tenant, contract, 12 dues, payment, report, logout.');
  } finally {
    await browser.close();
  }
})().catch(error => {console.error(error); process.exitCode = 1;});
