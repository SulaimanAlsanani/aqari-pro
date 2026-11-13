const {defineConfig, devices} = require('@playwright/test');

function stagingOrigin() {
  const raw = process.env.STAGING_BASE_URL;
  const expected = process.env.STAGING_ALLOWED_HOST;
  if (!raw || !expected) {
    throw Error('STAGING_BASE_URL and STAGING_ALLOWED_HOST are both required.');
  }
  let parsed;
  try {parsed = new URL(raw);} catch {throw Error('Invalid staging URL.');}
  const hostname = parsed.hostname.toLowerCase();
  if (parsed.protocol !== 'https:' ||
      parsed.username || parsed.password || parsed.search || parsed.hash ||
      parsed.pathname.replace(/\/+$/, '') !== '' ||
      hostname !== expected.toLowerCase() ||
      !/(^|[.-])stag(e|ing)([.-]|$)/i.test(hostname) ||
      hostname === 'localhost' || hostname.endsWith('.localhost') ||
      /^\d+\.\d+\.\d+\.\d+$/.test(hostname) || hostname.includes(':')) {
    throw Error('Browser acceptance requires an explicitly allowlisted HTTPS staging hostname.');
  }
  return parsed.origin;
}

module.exports = defineConfig({
  testDir: './tests',
  timeout: 30000,
  expect: {timeout: 8000},
  retries: 0,
  workers: 1,
  reporter: [['list']],
  forbidOnly: true,
  use: {
    baseURL: stagingOrigin(),
    browserName: 'chromium',
    ...devices['Desktop Chrome'],
    trace: 'off',
    screenshot: 'off',
    video: 'off',
    ignoreHTTPSErrors: false,
  },
});
