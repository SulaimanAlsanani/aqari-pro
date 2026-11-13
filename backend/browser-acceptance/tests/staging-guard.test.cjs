'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {resolveStagingOrigin, resolveAcceptanceMode} = require('../staging-guard.cjs');

test('only exact explicitly approved staging host over HTTPS is accepted', () => {
  const env = {
    STAGING_BASE_URL: 'https://staging.example.test/',
    STAGING_ALLOWED_HOST: 'staging.example.test',
  };
  assert.equal(resolveStagingOrigin(env), 'https://staging.example.test');
});

test('missing host allowlist is blocked before browser startup', () => {
  assert.throws(() => resolveStagingOrigin({
    STAGING_BASE_URL: 'https://staging.example.test',
  }));
});

test('production, localhost, HTTP, URL credentials, and query parameters are rejected', () => {
  const blocked = [
    'https://example.test',
    'https://production.example.test',
    'http://staging.example.test',
    'https://localhost',
    'https://127.0.0.1',
    'https://user:password@staging.example.test',
    'https://staging.example.test/?return=prod',
    'https://staging.example.test/app',
  ];
  for (const candidate of blocked) {
    const hostname = new URL(candidate).hostname;
    assert.throws(() => resolveStagingOrigin({
      STAGING_BASE_URL: candidate,
      STAGING_ALLOWED_HOST: hostname,
    }), candidate);
  }
});

test('allowlist mismatch prevents switching to another staging domain', () => {
  assert.throws(() => resolveStagingOrigin({
    STAGING_BASE_URL: 'https://staging-other.example.test',
    STAGING_ALLOWED_HOST: 'staging.example.test',
  }));
});

const owner = {
  STAGING_TEST_OWNER_EMAIL: 'aqari-e2e-test@example.test',
  STAGING_TEST_OWNER_PASSWORD: 'test-only-secret',
};
const confirmed = 'ALLOW_ONE_TIME_SYNTHETIC_WRITES';

test('normal browser acceptance remains read-only even with write confirmation present', () => {
  assert.equal(resolveAcceptanceMode({
    ...owner,
    STAGING_E2E_WRITE_CONFIRMATION: confirmed,
  }), 'read');
  assert.equal(resolveAcceptanceMode({
    ...owner,
    STAGING_ACCEPTANCE_MODE: 'read',
    STAGING_E2E_WRITE_CONFIRMATION: confirmed,
  }), 'read');
});

test('real owner browser acceptance requires a synthetic owner account', () => {
  assert.throws(() => resolveAcceptanceMode({STAGING_ACCEPTANCE_MODE: 'live'}));
  assert.equal(resolveAcceptanceMode({
    ...owner,
    STAGING_ACCEPTANCE_MODE: 'live',
  }), 'live');
});

test('write acceptance requires the separate write mode AND a one-time confirmation', () => {
  assert.throws(() => resolveAcceptanceMode({
    ...owner,
    STAGING_ACCEPTANCE_MODE: 'write',
  }));
  assert.throws(() => resolveAcceptanceMode({
    STAGING_ACCEPTANCE_MODE: 'write',
    STAGING_E2E_WRITE_CONFIRMATION: confirmed,
  }));
  assert.throws(() => resolveAcceptanceMode({
    ...owner,
    STAGING_TEST_OWNER_EMAIL: 'owner@example.test',
    STAGING_ACCEPTANCE_MODE: 'write',
    STAGING_E2E_WRITE_CONFIRMATION: confirmed,
  }));
  assert.equal(resolveAcceptanceMode({
    ...owner,
    STAGING_ACCEPTANCE_MODE: 'write',
    STAGING_E2E_WRITE_CONFIRMATION: confirmed,
  }), 'write');
});

test('unknown staging acceptance modes fail closed', () => {
  assert.throws(() => resolveAcceptanceMode({
    ...owner,
    STAGING_ACCEPTANCE_MODE: 'production',
  }));
});
