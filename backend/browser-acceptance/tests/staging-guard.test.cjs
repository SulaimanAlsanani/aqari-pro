'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {resolveStagingOrigin} = require('../staging-guard.cjs');

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
