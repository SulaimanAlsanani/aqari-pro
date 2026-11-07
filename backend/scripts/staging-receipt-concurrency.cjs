'use strict';
// Staging-only concurrency test. Creates synthetic owner records and receipts.
// Requires Node 20+; run against Apache/MySQL (not the single-threaded artisan serve).
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');

const base = process.env.AQARI_CONCURRENCY_URL || '';
const email = process.env.AQARI_CONCURRENCY_EMAIL || '';
const password = process.env.AQARI_CONCURRENCY_PASSWORD || '';
const acknowledge = process.env.AQARI_CONCURRENCY_WRITES === 'staging-only';
const concurrency = Number(process.env.AQARI_CONCURRENCY_REQUESTS || 8);

function preflight() {
  assert.ok(acknowledge, 'Set AQARI_CONCURRENCY_WRITES=staging-only to authorize synthetic writes');
  assert.ok(email && password, 'Set AQARI_CONCURRENCY_EMAIL and AQARI_CONCURRENCY_PASSWORD');
  assert.ok(Number.isInteger(concurrency) && concurrency >= 2 && concurrency <= 20, 'Concurrency must be 2..20');
  const url = new URL(base);
  const local = ['localhost','127.0.0.1','::1'].includes(url.hostname);
  assert.ok(local || url.protocol === 'https:', 'Remote staging requires HTTPS');
  assert.ok(local || process.env.AQARI_CONCURRENCY_REMOTE === 'staging-confirmed',
    'Remote testing requires AQARI_CONCURRENCY_REMOTE=staging-confirmed');
  assert.ok(!/(prod|production|live)/i.test(url.hostname), 'Refusing a production-like hostname');
  return url.origin;
}

async function run() {
  const host = preflight();
  let token;
  async function request(path, options = {}) {
    const response = await fetch(host + '/api' + path, {
      method: options.method || 'GET',
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: 'Bearer ' + token } : {}),
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        ...(options.headers || {}),
      },
      body: options.body ? JSON.stringify(options.body) : undefined,
      signal: AbortSignal.timeout(30000),
    });
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch { data = { message: text.slice(0, 300) }; }
    return { status: response.status, data };
  }
  async function created(path, body) {
    const r = await request(path, { method: 'POST', body });
    assert.equal(r.status, 201, JSON.stringify({ path, status: r.status, error: r.data }));
    return r.data;
  }
  const login = await request('/login', { method: 'POST', body: { email, password } });
  assert.equal(login.status, 200, 'Staging owner login failed');
  token = login.data.token;
  assert.ok(token, 'Missing access token');
  const suffix = randomUUID().slice(0, 8);
  try {
    const property = await created('/properties', {
      name: 'STAGING CONCURRENCY ' + suffix, city: 'Riyadh', type: 'residential'
    });
    const tenant = await created('/tenants', { name: 'Synthetic tenant ' + suffix, phone: '0500000000' });
    async function leaseFixture(code) {
      const unit = await created('/properties/' + property.id + '/units', { code, type: 'apartment' });
      return created('/leases', {
        unit_id: unit.id, tenant_id: tenant.id,
        starts_on: '2026-01-01', ends_on: '2026-12-31',
        total_amount: 100, installments: 1
      });
    }
    const one = await leaseFixture('R-' + suffix + '-1');
    const two = await leaseFixture('R-' + suffix + '-2');
    const payment = { amount: 100, paid_on: '2026-02-01', method: 'cash', reference: 'load-test-' + suffix };
    const firstKey = 'concur-' + suffix + '-same';
    const same = await Promise.all(Array.from({ length: concurrency },
      () => request('/leases/' + one.id + '/payments', {
        method: 'POST', body: payment, headers: { 'Idempotency-Key': firstKey }
      })));
    assert.equal(same.filter(x => x.status === 201).length, 1,
      'Exactly one request must create a receipt; results=' + JSON.stringify(same.map(x=>x.status)));
    assert.equal(same.filter(x => x.status === 200).length, concurrency - 1,
      'All replays must return 200; results=' + JSON.stringify(same.map(x=>x.status)));
    assert.equal(new Set(same.map(x => x.data.id)).size, 1, 'Same idempotency key must return same receipt');

    const distinct = await Promise.all(Array.from({ length: concurrency }, (_, i) =>
      request('/leases/' + two.id + '/payments', {
        method: 'POST', body: payment,
        headers: { 'Idempotency-Key': 'concur-' + suffix + '-' + i }
      })));
    assert.equal(distinct.filter(x => x.status === 201).length, 1,
      'Exactly one payment should fit the lease balance; statuses=' + JSON.stringify(distinct.map(x=>x.status)));
    assert.equal(distinct.filter(x => x.status === 422).length, concurrency - 1,
      'Excess parallel receipts must be rejected; statuses=' + JSON.stringify(distinct.map(x=>x.status)));
    // Competing lease creations must not reserve the same unit for overlapping dates.
    const contestedUnit = await created('/properties/' + property.id + '/units', {
      code: 'R-' + suffix + '-overlap', type: 'apartment'
    });
    const leasePayload = {
      unit_id: contestedUnit.id, tenant_id: tenant.id,
      starts_on: '2026-01-01', ends_on: '2026-12-31',
      total_amount: 1200, installments: 12
    };
    const overlapping = await Promise.all(Array.from({ length: concurrency },
      () => request('/leases', { method: 'POST', body: leasePayload })));
    assert.equal(overlapping.filter(x => x.status === 201).length, 1,
      'Overlapping concurrent leases must create exactly one contract; statuses=' +
      JSON.stringify(overlapping.map(x => x.status)));
    assert.equal(overlapping.filter(x => x.status === 422).length, concurrency - 1,
      'Other overlapping leases must be rejected; statuses=' +
      JSON.stringify(overlapping.map(x => x.status)));
    console.log('PASS staging concurrent receipts and overlapping leases (' +
      concurrency + ' requests/group); property=' + property.id);
  } finally {
    if (token) await request('/logout', { method: 'POST' }).catch(() => {});
  }
}
run().catch(e => { console.error('FAIL:', e); process.exitCode = 1; });
