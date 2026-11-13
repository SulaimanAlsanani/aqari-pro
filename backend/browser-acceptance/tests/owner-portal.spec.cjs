const {test, expect} = require('@playwright/test');

// Browser checks are read-only except logging in/out with a dedicated,
// non-production staging test owner. Never create financial or tenant records.
const email = process.env.STAGING_TEST_OWNER_EMAIL;
const password = process.env.STAGING_TEST_OWNER_PASSWORD;

test.beforeEach(async ({page, baseURL}) => {
  const origin = new URL(baseURL).origin;
  // Refuse any browser requests to third parties (including Google Fonts),
  // analytics, external auth gateways, and unintended API destinations.
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.origin !== origin) {
      await route.abort();
      return;
    }
    await route.continue();
  });
});

test('guest sees login but cannot use owner workspace', async ({page, baseURL}) => {
  const response = await page.goto('/app/index.html', {waitUntil: 'domcontentloaded'});
  expect(response?.status()).toBe(200);
  expect(new URL(page.url()).origin).toBe(new URL(baseURL).origin);
  await expect(page.locator('#loginPanel')).toBeVisible();
  await expect(page.locator('#workspace')).toBeHidden();
  await expect(page.locator('#password')).toHaveAttribute('type', 'password');
  await expect(page.locator('#logout')).toBeHidden();
});

test('staging test owner can view portfolio, reports, and log out', async ({page, baseURL}) => {
  test.skip(!email || !password, 'Dedicated staging owner test credentials are required.');
  const response = await page.goto('/app/index.html', {waitUntil: 'domcontentloaded'});
  expect(response?.status()).toBe(200);
  expect(new URL(page.url()).origin).toBe(new URL(baseURL).origin);

  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  const loginResponse = page.waitForResponse(r =>
    r.url().startsWith(baseURL + '/api/login') && r.request().method() === 'POST',
    {timeout: 15000}
  );
  await page.locator('#loginForm button[type="submit"]').click();
  const login = await loginResponse;
  if (login.status() === 428) {
    throw Error('Use a dedicated staging-only test owner without MFA; do not automate TOTP or recovery secrets.');
  }
  expect(login.status()).toBe(200);

  await expect(page.locator('#workspace')).toBeVisible();
  await expect(page.locator('#sectionTitle')).toHaveText('لوحة أملاكي');
  await expect(page.locator('#view .metric').first()).toBeVisible();
  await expect(page.locator('#notice')).not.toHaveClass(/error/);

  const navigation = [
    ['العقارات', '/api/portfolio?'],
    ['الوحدات', '/api/units?'],
    ['المستأجرون', '/api/tenants?'],
    ['العقود', '/api/leases?'],
    ['الأقساط', '/api/dues?'],
    ['المقبوضات', '/api/payments?'],
    ['المرفقات', '/api/documents?'],
    ['التقارير المالية', '/api/reports/cashflow?'],
  ];
  for (const [label, endpoint] of navigation) {
    // The actual endpoint is explicitly checked below; a 200 response
    // confirms the view fetched its own owner-scoped resource.
    const expectedResponse = page.waitForResponse(r =>
      r.url().startsWith(baseURL + endpoint) && r.request().method() === 'GET',
      {timeout: 15000}
    );
    await page.locator('#nav button').filter({hasText: label}).first().click();
    const result = await expectedResponse;
    expect(result.status()).toBe(200);
    await expect(page.locator('#sectionTitle')).toHaveText(label);
    await expect(page.locator('#notice')).not.toHaveClass(/error/);
    if (label === 'التقارير المالية') {
      await expect(page.locator('#view .metrics .metric').first()).toBeVisible();
    } else {
      await expect(page.locator('#view .table-wrap table')).toBeVisible();
    }
  }

  // Regression: a delayed response for a previous section must never
  // replace the data table of the owner's current selection.
  await page.route(url => url.origin === baseURL && url.pathname === '/api/units', async route => {
    await new Promise(resolve => setTimeout(resolve, 800));
    await route.continue();
  });
  const delayedUnits = page.waitForResponse(r =>
    r.url().startsWith(baseURL + '/api/units?') && r.request().method() === 'GET',
    {timeout: 15000}
  );
  const tenantsResponse = page.waitForResponse(r =>
    r.url().startsWith(baseURL + '/api/tenants?') && r.request().method() === 'GET',
    {timeout: 15000}
  );
  await page.locator('#nav button').filter({hasText:'الوحدات'}).first().click();
  await page.locator('#nav button').filter({hasText:'المستأجرون'}).first().click();
  expect((await tenantsResponse).status()).toBe(200);
  expect((await delayedUnits).status()).toBe(200);
  await expect(page.locator('#sectionTitle')).toHaveText('المستأجرون');
  await expect(page.locator('#view thead')).toContainText('المستأجر');
  await page.waitForTimeout(100);
  await expect(page.locator('#view thead')).toContainText('المستأجر');
  await expect(page.locator('#notice')).not.toHaveClass(/error/);

  const logoutResponse = page.waitForResponse(r =>
    r.url().startsWith(baseURL + '/api/logout') && r.request().method() === 'POST',
    {timeout: 15000}
  );
  await page.locator('#logout').click();
  expect((await logoutResponse).status()).toBe(200);
  await expect(page.locator('#workspace')).toBeHidden();
  await expect(page.locator('#loginPanel')).toBeVisible();
});
