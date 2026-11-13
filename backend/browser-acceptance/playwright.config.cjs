const {defineConfig, devices} = require('@playwright/test');

const {resolveStagingOrigin} = require('./staging-guard.cjs');

module.exports = defineConfig({
  testDir: './tests',
  timeout: 30000,
  expect: {timeout: 8000},
  retries: 0,
  workers: 1,
  reporter: [['list']],
  forbidOnly: true,
  use: {
    baseURL: resolveStagingOrigin(process.env),
    browserName: 'chromium',
    ...devices['Desktop Chrome'],
    trace: 'off',
    screenshot: 'off',
    video: 'off',
    ignoreHTTPSErrors: false,
  },
});
