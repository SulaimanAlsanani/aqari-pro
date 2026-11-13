'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const path = require('node:path');

const runner = path.join(__dirname, '..', 'scripts', 'run-acceptance.cjs');
const env = {
  ...process.env,
  STAGING_BASE_URL: 'https://staging.example.invalid',
  STAGING_ALLOWED_HOST: 'staging.example.invalid',
};

function attempt(...args) {
  return spawnSync(process.execPath, [runner, ...args], {
    env, encoding: 'utf8', timeout: 5000,
  });
}

test('unknown acceptance modes are rejected before launching Playwright', () => {
  const response = attempt('production');
  assert.equal(response.status, 2);
  assert.match(response.stderr, /Choose exactly one acceptance mode/);
});

test('arbitrary CLI arguments cannot override the selected test family', () => {
  for (const args of [
    ['read', 'tests/owner-write.spec.cjs'],
    ['read', '--config=unsafe.config.cjs'],
    ['read', '--grep=financial'],
    ['read', '--list', 'tests/owner-write.spec.cjs'],
    ['write', '--project=another-browser'],
  ]) {
    const response = attempt(...args);
    assert.equal(response.status, 2, 'unexpected runner result for ' + args.join(' '));
    assert.match(response.stderr, /only supported extra argument is --list/);
  }
});

test('a staging target allowlist mismatch blocks before Playwright is imported', () => {
  const response = spawnSync(process.execPath, [runner, 'read', '--list'], {
    env: {...env, STAGING_ALLOWED_HOST: 'staging.different.invalid'},
    encoding: 'utf8', timeout: 5000,
  });
  assert.equal(response.status, 2);
  assert.match(response.stderr, /Staging acceptance was blocked/);
});
