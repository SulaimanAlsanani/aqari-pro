'use strict';
// Read-only API benchmark for an isolated staging account. Node 20+ required.
// This is not intended for production load testing.
const assert = require('node:assert/strict');
const { performance } = require('node:perf_hooks');

const base = process.env.AQARI_PERF_URL || '';
const email = process.env.AQARI_PERF_EMAIL || '';
const password = process.env.AQARI_PERF_PASSWORD || '';
const count = Number(process.env.AQARI_PERF_REQUESTS || 40);
const parallel = Number(process.env.AQARI_PERF_PARALLEL || 4);
const maxP95 = Number(process.env.AQARI_PERF_P95_MS || 2000);
const paths = ['/overview', '/portfolio', '/units', '/leases', '/payments', '/documents'];

function preflight() {
  assert.equal(process.env.AQARI_PERF_ACK, 'staging-only',
    'Set AQARI_PERF_ACK=staging-only to authorize staging traffic');
  assert.ok(email && password, 'Provide a dedicated synthetic staging owner account');
  assert.ok(Number.isInteger(count) && count >= 6 && count <= 300,
    'AQARI_PERF_REQUESTS must be 6..300');
  assert.ok(Number.isInteger(parallel) && parallel >= 1 && parallel <= 10,
    'AQARI_PERF_PARALLEL must be 1..10');
  assert.ok(Number.isFinite(maxP95) && maxP95 > 0, 'Invalid p95 threshold');
  const url = new URL(base);
  assert.ok(['http:','https:'].includes(url.protocol), 'Invalid protocol');
  const local = ['localhost','127.0.0.1','::1'].includes(url.hostname);
  assert.ok(local || url.protocol === 'https:', 'Non-local targets require HTTPS');
  assert.ok(local || process.env.AQARI_PERF_REMOTE === 'staging-confirmed',
    'Remote host must be confirmed as staging');
  assert.ok(!/(prod|production|live)/i.test(url.hostname),
    'Production-like hostnames are forbidden');
  return url.origin;
}

async function run() {
  const host = preflight();
  async function call(path, method, token, body) {
    const start = performance.now();
    const response = await fetch(host + '/api' + path, {
      method, headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: 'Bearer ' + token } : {}),
        ...(body ? { 'Content-Type': 'application/json' } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
      signal: AbortSignal.timeout(15000),
    });
    await response.arrayBuffer();
    return { status: response.status, ms: performance.now() - start };
  }
  const loginResponse = await fetch(host + '/api/login', {
    method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email, password }), signal: AbortSignal.timeout(15000),
  });
  assert.equal(loginResponse.status, 200, 'Staging authentication failed');
  const login = await loginResponse.json();
  const token = login.token;
  assert.ok(token, 'Missing staging token');
  try {
    // Verify endpoints one by one before sending any parallel workload.
    for (const path of paths) {
      const r = await call(path, 'GET', token);
      assert.equal(r.status, 200, 'Preflight failed: ' + path + ' HTTP ' + r.status);
    }

    const samples = [];
    let next = 0;
    await Promise.all(Array.from({ length: parallel }, async () => {
      while (next < count) {
        const index = next++;
        const path = paths[index % paths.length];
        const result = await call(path, 'GET', token);
        samples.push({ path, ...result });
      }
    }));
    assert.equal(samples.length, count);
    const errors = samples.filter(x => x.status !== 200);
    const sorted = samples.map(x => x.ms).sort((a, b) => a - b);
    const percentile = p => sorted[Math.ceil(p * sorted.length) - 1];
    const p50 = percentile(.5), p95 = percentile(.95), p99 = percentile(.99);
    console.log(JSON.stringify({
      mode: 'staging-read-only', total: samples.length, parallel, errors: errors.length,
      p50_ms: +p50.toFixed(1), p95_ms: +p95.toFixed(1), p99_ms: +p99.toFixed(1),
      max_ms: +sorted[sorted.length - 1].toFixed(1),
      endpoints: paths,
    }, null, 2));
    assert.equal(errors.length, 0, 'Staging API returned non-200 responses during read test');
    assert.ok(p95 <= maxP95, 'p95 latency exceeded ' + maxP95 + 'ms');
  } finally {
    await fetch(host + '/api/logout', {
      method: 'POST', headers: { Authorization: 'Bearer ' + token },
      signal: AbortSignal.timeout(5000),
    }).catch(() => {});
  }
}
run().catch(e => { console.error('FAIL:', e.message); process.exitCode = 1; });
