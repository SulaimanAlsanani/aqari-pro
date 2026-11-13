const {defineConfig, devices} = require('@playwright/test');

const {resolveStagingOrigin, resolveAcceptanceMode} = require('./staging-guard.cjs');

const acceptanceMode = resolveAcceptanceMode(process.env);

module.exports = defineConfig({
  testDir: './tests',
  // Never include the destructive synthetic data flow in normal npm test.
  testMatch: acceptanceMode === 'write' ? '**/owner-write.spec.cjs' : '**/owner-portal.spec.cjs',
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
