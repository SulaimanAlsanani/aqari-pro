'use strict';

// Called before Playwright initializes or launches a browser.
function resolveStagingOrigin(env) {
  const raw = env.STAGING_BASE_URL;
  const expected = env.STAGING_ALLOWED_HOST;
  if (!raw || !expected || typeof raw !== 'string' || typeof expected !== 'string') {
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

/**
 * Select exactly one kind of browser test before Playwright starts.
 * Default is strictly read-only even when write-specific secrets happen to
 * remain in the environment. Write tests require a separate explicit mode.
 */
function resolveAcceptanceMode(env) {
  const mode = env.STAGING_ACCEPTANCE_MODE || 'read';
  if (!['read', 'live', 'write'].includes(mode)) {
    throw Error('Unknown staging acceptance mode.');
  }
  if (mode === 'live' || mode === 'write') {
    if (!env.STAGING_TEST_OWNER_EMAIL || !env.STAGING_TEST_OWNER_PASSWORD) {
      throw Error('Live staging owner tests require explicit synthetic owner credentials.');
    }
  }
  if (mode === 'write') {
    if (env.STAGING_E2E_WRITE_CONFIRMATION !== 'ALLOW_ONE_TIME_SYNTHETIC_WRITES' ||
        !env.STAGING_TEST_OWNER_EMAIL.toLowerCase().startsWith('aqari-e2e-')) {
      throw Error('Write acceptance requires explicit one-time confirmation and a dedicated E2E owner.');
    }
  }
  return mode;
}

module.exports = {resolveStagingOrigin, resolveAcceptanceMode};
