/* Browser E2E: actual HTTPS proxy + Laravel/Sanctum + PostgreSQL + staging UI.
 * Only synthetic CI data are used. Requires the loopback-only CI Caddy listener.
 */
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const fs = require('node:fs/promises');
const {createHmac} = require('node:crypto');

function sixDigitTotp(secret, step) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const character of secret) {
    const n = alphabet.indexOf(character);
    if (n < 0) throw new Error('Invalid synthetic TOTP setup key');
    bits += n.toString(2).padStart(5, '0');
  }
  const key = Buffer.from(
    bits.match(/.{8}/g).map(chunk => parseInt(chunk, 2))
  );
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const digest = createHmac('sha1', key).update(counter).digest();
  const offset = digest[19] & 15;
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1000000)
    .padStart(6, '0');
}


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

    // A reversed receipt remains auditable but no longer counts as collected.
    await page.locator('#paymentList li').first().getByRole('button', {name: 'عكس السند'}).click();
    await page.locator('#reversePaymentForm input[name=reason]').fill('CI reversal of synthetic receipt');
    await page.locator('#reversePaymentForm button[type=submit]').click();
    await retryStatus(page, 'تم عكس السند');
    assert.match(await page.locator('#paymentList').innerText(), /معكوس/);
    const reversedReport = await page.evaluate(async () => {
      const response = await fetch('/api/v1/reports/receivables', {
        credentials: 'include', headers: {'Accept': 'application/json'}
      });
      return {status: response.status, body: await response.json()};
    });
    assert.equal(reversedReport.status, 200);
    assert.equal(reversedReport.body.amounts.collected, '0.00');
    assert.equal(reversedReport.body.amounts.outstanding, '1200.00');

    // The test scanner is installed only inside the disposable CI container.
    // Production file scanning still requires real ClamAV signatures and service.
    await page.locator('#attachmentType').selectOption('property');
    await page.locator('#attachmentParent').selectOption({label: 'E2E Building — Riyadh'});
    const syntheticPng = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZsAAAAASUVORK5CYII=',
      'base64'
    );
    await page.locator('#attachmentFile').setInputFiles({
      name: 'synthetic-document.png',
      mimeType: 'image/png',
      buffer: syntheticPng
    });
    await page.locator('#attachmentForm button[type=submit]').click();
    await retryStatus(page, 'تم رفع المرفق');
    assert.match(await page.locator('#attachmentList').innerText(), /synthetic-document\.png/);

    const downloadLink = page.locator('#attachmentList a').first();
    const downloadUrl = await downloadLink.getAttribute('href');
    assert.match(downloadUrl, /^\/api\/v1\/attachments\/\d+\/download$/);
    const fileId = Number(downloadUrl.match(/attachments\/(\d+)/)[1]);
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      downloadLink.click(),
    ]);
    assert.equal(download.suggestedFilename(), 'synthetic-document.png');
    assert.deepEqual(await fs.readFile(await download.path()), syntheticPng);

    const audit = await page.evaluate(async id => {
      const response = await fetch('/api/v1/attachments/' + id + '/events', {
        credentials: 'include', headers: {'Accept': 'application/json'}
      });
      return {status: response.status, events: (await response.json()).data};
    }, fileId);
    assert.equal(audit.status, 200);
    assert.ok(audit.events.some(e => e.action === 'uploaded'));
    assert.ok(audit.events.some(e => e.action === 'download_requested'));

    // Owner self-service: changes are scoped to the current account.
    await page.locator('#accountForm input[name=name]').fill('Updated E2E Owner');
    await page.locator('#accountForm input[name=phone]').fill('0501234567');
    await page.locator('#accountForm button[type=submit]').click();
    await retryStatus(page, 'تم حفظ بيانات حساب المالك');
    assert.match(await page.locator('#ownerLabel').innerText(), /Updated E2E Owner/);
    assert.equal(await page.locator('#accountEmail').inputValue(), 'owner.e2e@example.test');

    // Verify two real browser devices have independent session cookies.
    const secondContext = await browser.newContext({
      ignoreHTTPSErrors: true,
      viewport: {width: 900, height: 700},
      locale: 'ar-SA',
    });
    try {
      const remote = await secondContext.newPage();
      await remote.goto(origin + '/staging/', {waitUntil: 'domcontentloaded'});
      await remote.locator('#loginForm input[name=email]').fill('owner.e2e@example.test');
      await remote.locator('#loginForm input[name=password]').fill(password);
      await remote.locator('#loginForm button[type=submit]').click();
      await retryStatus(remote, 'متصل ببيئة');

      await page.locator('#refreshSessions').click();
      await retryStatus(page, 'تم تحديث قائمة الأجهزة');
      assert.ok(await page.locator('#activeSessions li').count() >= 2,
        'Both current and remote browser sessions should appear');
      assert.match(await page.locator('#loginActivity').innerText(), /دخول ناجح/);
      assert.doesNotMatch(await page.locator('#activeSessions').innerText(),
        /session-id|session_token|payload/i);

      await page.locator('#revokeOthersForm input[name=current_password]').fill('incorrect');
      const rejectedRevoke = page.waitForResponse(response =>
        response.url().includes('/account/sessions/revoke-others') &&
        response.request().method() === 'POST');
      await page.locator('#revokeOthersForm button[type=submit]').click();
      assert.equal((await rejectedRevoke).status(), 422,
        'Wrong current password must never revoke a session');
      await page.waitForFunction(() =>
        !document.querySelector('#revokeOthersForm button[type=submit]').disabled);
      const beforeRevoke = await remote.evaluate(async () => {
        const res = await fetch('/api/v1/me', {
          credentials: 'include', headers: {'Accept': 'application/json'},
        });
        return res.status;
      });
      assert.equal(beforeRevoke, 200,
        'Incorrect password must leave the other browser signed in');

      await page.locator('#revokeOthersForm input[name=current_password]').fill(password);
      await page.locator('#revokeOthersForm button[type=submit]').click();
      await retryStatus(page, 'تم تسجيل خروج الأجهزة الأخرى');
      const afterRevoke = await remote.evaluate(async () => {
        const res = await fetch('/api/v1/me', {
          credentials: 'include', headers: {'Accept': 'application/json'},
        });
        return res.status;
      });
      assert.equal(afterRevoke, 401, 'Remote browser session must be revoked');
      assert.equal(await page.locator('#workspace').isVisible(), true,
        'The current browser session should remain signed in');
      assert.match(await page.locator('#loginActivity').innerText(),
        /إنهاء الجلسات الأخرى/);
    } finally {
      await secondContext.close();
    }

    // Password rotation must invalidate the browser session immediately.
    await page.locator('#changePasswordForm input[name=current_password]').fill(password);
    await page.locator('#changePasswordForm input[name=password]').fill('RotatedPass!2026');
    await page.locator('#changePasswordForm input[name=password_confirmation]').fill('RotatedPass!2026');
    await page.locator('#changePasswordForm button[type=submit]').click();
    await retryStatus(page, 'تم تغيير كلمة المرور');
    assert.equal(await page.locator('#workspace').isVisible(), false);
    const afterLogout = await page.evaluate(async () => {
      const response = await fetch('/api/v1/properties', {
        credentials: 'include', headers: {'Accept': 'application/json'}
      });
      return response.status;
    });
    assert.equal(afterLogout, 401, 'Logout must invalidate authenticated API access');
    const documentAfterLogout = await page.evaluate(async id => {
      const response = await fetch('/api/v1/attachments/' + id + '/download', {
        credentials: 'include', headers: {'Accept': 'application/json'}
      });
      return response.status;
    }, fileId);
    assert.equal(documentAfterLogout, 401, 'Private files must be inaccessible after password rotation');

    // Verify the old password is rejected and the new password is accepted.
    await page.locator('#loginForm input[name=email]').fill('owner.e2e@example.test');
    await page.locator('#loginForm input[name=password]').fill(password);
    await page.locator('#loginForm button[type=submit]').click();
    await retryStatus(page, 'بيانات تسجيل الدخول غير صحيحة');
    await page.locator('#loginForm input[name=email]').fill('owner.e2e@example.test');
    await page.locator('#loginForm input[name=password]').fill('RotatedPass!2026');
    await page.locator('#loginForm button[type=submit]').click();
    await retryStatus(page, 'متصل ببيئة');
    // Enroll authenticator MFA in the owner's real browser session.
    await page.locator('#mfaSetupForm input[name=current_password]').fill('RotatedPass!2026');
    await page.locator('#mfaSetupForm button[type=submit]').click();
    await retryStatus(page, 'أضف المفتاح إلى تطبيق المصادقة');
    const setupSecret = await page.locator('#mfaSecret').inputValue();
    assert.match(setupSecret, /^[A-Z2-7]{32}$/);
    const setupStep = Math.floor(Date.now() / 30000);
    await page.locator('#mfaConfirmForm input[name=code]').fill(sixDigitTotp(setupSecret, setupStep));
    await page.locator('#mfaConfirmForm button[type=submit]').click();
    await retryStatus(page, 'تم تفعيل المصادقة الثنائية');
    assert.match(await page.locator('#mfaStatus').innerText(), /مفعّلة/);
    assert.equal(await page.locator('#mfaSecret').inputValue(), '',
      'Authenticator secret must be erased from the form after activation');

    await page.locator('#logout').click();
    await retryStatus(page, 'تم تسجيل الخروج');

    await page.locator('#loginForm input[name=email]').fill('owner.e2e@example.test');
    await page.locator('#loginForm input[name=password]').fill('RotatedPass!2026');
    await page.locator('#loginForm button[type=submit]').click();
    await retryStatus(page, 'أدخل رمز المصادقة الثنائية لإكمال تسجيل الدخول');
    assert.equal(await page.locator('#mfaLoginPanel').isVisible(), true);
    const beforeSecondFactor = await page.evaluate(async () => {
      const res = await fetch('/api/v1/properties', {
        credentials: 'include', headers: {'Accept': 'application/json'},
      });
      return res.status;
    });
    assert.equal(beforeSecondFactor, 401, 'MFA owner must not access portfolio with password alone');

    // Advance to an unused time step. One adjacent step is accepted by the
    // server to allow for legitimate authenticator clock differences.
    const challengeStep = Math.max(setupStep + 1, Math.floor(Date.now() / 30000));
    await page.locator('#mfaLoginForm input[name=code]').fill(sixDigitTotp(setupSecret, challengeStep));
    const challengeResponse = page.waitForResponse(response =>
      response.url().endsWith('/login/mfa') && response.request().method() === 'POST');
    await page.locator('#mfaLoginForm button[type=submit]').click();
    const confirmedResponse = await challengeResponse;
    assert.equal(confirmedResponse.status(), 200,
      'TOTP challenge failed with HTTP ' + confirmedResponse.status() +
      ': ' + JSON.stringify(await confirmedResponse.json()));
    await retryStatus(page, 'اكتملت المصادقة الثنائية');
    assert.equal(await page.locator('#workspace').isVisible(), true);

    await page.locator('#logout').click();
    await retryStatus(page, 'تم تسجيل الخروج');
    assert.deepEqual(pageErrors, [], 'No browser exceptions or server 5xx responses');
    console.log('Browser E2E passed: login, CSRF, tenant isolation, property, unit, tenant, 12 dues, payment, reversal, receivables, private PNG upload/download, audit, owner profile, password rotation, verified TOTP enrollment and MFA challenge before portfolio access.');
  } finally {
    await browser.close();
  }
})().catch(error => {console.error(error); process.exitCode = 1;});
