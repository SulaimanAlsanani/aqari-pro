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
    ['العقارات', 'لوحة أملاكي'], // title is actually the section label (checked below)
    ['الوحدات', 'الوحدات'],
    ['المستأجرون', 'المستأجرون'],
    ['العقود', 'العقود'],
    ['الأقساط', 'الأقساط'],
    ['المقبوضات', 'المقبوضات'],
    ['المرفقات', 'المرفقات'],
    ['التقارير المالية', 'التقارير المالية'],
  ];
  for (const [label, expectedTitle] of navigation) {
    const button = page.locator('#nav button').filter({hasText: label}).first();
    await button.click();
    const title = label === 'العقارات' ? 'العقارات' : expectedTitle;
    await expect(page.locator('#sectionTitle')).toHaveText(title);
    await expect(page.locator('#view')).not.toContainText('جاري تحميل البيانات', {timeout: 15000});
    await expect(page.locator('#notice')).not.toHaveClass(/error/);
    await expect(page.locator('#view')).not.toBeEmpty();
  }

  const logoutResponse = page.waitForResponse(r =>
    r.url().startsWith(baseURL + '/api/logout') && r.request().method() === 'POST',
    {timeout: 15000}
  );
  await page.locator('#logout').click();
  expect((await logoutResponse).status()).toBe(200);
  await expect(page.locator('#workspace')).toBeHidden();
  await expect(page.locator('#loginPanel')).toBeVisible();
});
