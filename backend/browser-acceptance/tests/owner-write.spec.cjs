'use strict';
const {test, expect} = require('@playwright/test');

const email = process.env.STAGING_TEST_OWNER_EMAIL;
const password = process.env.STAGING_TEST_OWNER_PASSWORD;
const writeEnabled = process.env.STAGING_E2E_WRITE_CONFIRMATION === 'ALLOW_ONE_TIME_SYNTHETIC_WRITES';

test('one-time synthetic owner journey: property → unit → tenant → lease → receipt → document', async ({page, baseURL}) => {
  test.setTimeout(150000);
  test.skip(!writeEnabled || !email || !password,
    'Requires explicit one-time write confirmation and dedicated staging test owner secrets.');

  const origin = new URL(baseURL).origin;
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.origin !== origin) return route.abort();
    // Fail closed if UI unexpectedly makes other state-changing requests.
    const method = route.request().method();
    if (!['GET','HEAD','OPTIONS'].includes(method)) {
      const path = url.pathname;
      const permitted = method === 'POST' && (
        path === '/api/login' || path === '/api/logout' ||
        path === '/api/properties' || path === '/api/tenants' ||
        path === '/api/leases' || path === '/api/documents' ||
        /^\/api\/properties\/[0-9]+\/units$/.test(path) ||
        /^\/api\/leases\/[0-9]+\/payments$/.test(path)
      );
      if (!permitted) return route.abort();
    }
    return route.continue();
  });

  const landing = await page.goto('/app/index.html', {waitUntil:'domcontentloaded'});
  expect(landing?.status()).toBe(200);
  await expect(page.locator('#loginPanel')).toBeVisible();
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  const loginPending = page.waitForResponse(r =>
    r.url() === origin + '/api/login' && r.request().method() === 'POST',
    {timeout: 15000}
  );
  await page.locator('#loginForm button[type="submit"]').click();
  const login = await loginPending;
  expect(login.status(), 'Only a dedicated staging test account without MFA may be used').toBe(200);
  const identity = await login.json();

  // A server-side gate is mandatory: the browser's URL allowlist alone
  // cannot prove this is not a production database behind a staging hostname.
  const gate = await page.request.get(origin + '/api/staging/e2e-readiness', {
    headers: {Authorization: 'Bearer ' + identity.token, Accept: 'application/json'},
    maxRedirects: 0,
  });
  expect(gate.status(), 'Server denied synthetic staging writes; NO records were created').toBe(200);
  const readiness = await gate.json();
  expect(readiness).toMatchObject({
    allowed: true, environment: 'staging', owner_id: identity.user.id,
  });
  expect(email.toLowerCase().startsWith('aqari-e2e-')).toBe(true);
  await expect(page.locator('#workspace')).toBeVisible();

  const navigate = async (label, path) => {
    const pending = page.waitForResponse(r => r.request().method() === 'GET' &&
      r.url().startsWith(origin + path), {timeout: 15000});
    await page.locator('#nav button').filter({hasText: label}).first().click();
    expect((await pending).status()).toBe(200);
    await expect(page.locator('#sectionTitle')).toHaveText(label);
    await expect(page.locator('#view .table-wrap table')).toBeVisible();
  };

  const create = async (route, values, file = null) => {
    const form = page.locator('#view form');
    for (const [name, value] of Object.entries(values)) {
      const input = form.locator('[name="' + name + '"]');
      if (await input.evaluate(el => el.tagName === 'SELECT')) {
        await input.selectOption(String(value));
      } else {
        await input.fill(String(value));
      }
    }
    if (file) await form.locator('input[type="file"]').setInputFiles(file);
    const pending = page.waitForResponse(r =>
      r.request().method() === 'POST' && r.url() === origin + '/api' + route,
      {timeout: 20000});
    await form.locator('button[type="submit"]').click();
    const response = await pending;
    expect(response.status(), 'Failed to create synthetic test record').toBe(201);
    const result = await response.json();
    expect(Number.isInteger(result.id) && result.id > 0).toBe(true);
    // The UI submits asynchronously and reloads the owner-scoped list.
    // Wait for that refresh before navigating to the next section.
    await expect(page.locator('#view form')).toHaveCount(0, {timeout:15000});
    await expect(page.locator('#notice')).not.toHaveClass(/error/);
    return result.id;
  };

  const startForm = async () => {
    await page.locator('#view button.primary').filter({hasText: 'إضافة سجل'}).click();
    await expect(page.locator('#view form')).toBeVisible();
  };

  await navigate('العقارات', '/api/portfolio?');
  await startForm();
  const propertyId = await create('/properties', {
    name:'E2E Synthetic Building', city:'Riyadh', type:'residential',
  });

  await navigate('الوحدات', '/api/units?');
  await startForm();
  const unitId = await create('/properties/' + propertyId + '/units', {
    property_id:propertyId, code:'E2E-101', type:'apartment',
  });

  await navigate('المستأجرون', '/api/tenants?');
  await startForm();
  const tenantId = await create('/tenants', {
    name:'E2E Synthetic Tenant', phone:'0500000000',
  });

  await navigate('العقود', '/api/leases?');
  await startForm();
  const leaseId = await create('/leases', {
    unit_id:unitId, tenant_id:tenantId, starts_on:'2027-01-01',
    ends_on:'2027-12-31', total_amount:'1200', deposit:'0', installments:'2',
  });

  await navigate('الأقساط', '/api/dues?');
  await expect(page.locator('#view tbody tr')).toHaveCount(2);
  await expect(page.locator('#view')).toContainText('600.00');

  await navigate('المقبوضات', '/api/payments?');
  await startForm();
  await create('/leases/' + leaseId + '/payments', {
    lease_id:leaseId, amount:'300', paid_on:'2027-01-10',
    method:'bank_transfer', reference:'E2E-SYNTHETIC-RECEIPT',
  });

  await navigate('الأقساط', '/api/dues?');
  await expect(page.locator('#view')).toContainText('300.00');

  await navigate('المرفقات', '/api/documents?');
  await startForm();
  const documentId = await create('/documents', {
    entity_type:'lease', entity_id:leaseId,
  }, {
    name:'aqari-e2e-proof.txt',
    mimeType:'text/plain',
    buffer:Buffer.from('Synthetic Aqari E2E staging record; no customer data.\n'),
  });
  await expect(page.locator('#view')).toContainText('aqari-e2e-proof.txt');

  const downloadPending = page.waitForEvent('download', {timeout: 15000});
  const documentRow = page.locator('#view tbody tr').filter({hasText:'aqari-e2e-proof.txt'});
  await documentRow.getByRole('button', {name:'تنزيل'}).click();
  const download = await downloadPending;
  expect(download.suggestedFilename()).toBe('aqari-e2e-proof.txt');

  // No delete/cleanup against a potentially shared environment. Because the
  // server gate requires an empty test owner, this test is intentionally
  // one-time-per-dedicated-account; provision another isolated owner to rerun.
  const logoutPending = page.waitForResponse(r =>
    r.url() === origin + '/api/logout' && r.request().method() === 'POST',
    {timeout: 15000});
  await page.locator('#logout').click();
  expect((await logoutPending).status()).toBe(200);
  await expect(page.locator('#loginPanel')).toBeVisible();
  expect(documentId).toBeGreaterThan(0);
});
