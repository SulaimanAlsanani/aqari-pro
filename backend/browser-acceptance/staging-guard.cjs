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

module.exports = {resolveStagingOrigin};
