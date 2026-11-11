const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

const context = {};
vm.runInNewContext(fs.readFileSync('backend/frontend/finance-staging.js', 'utf8'), context);
vm.runInNewContext(fs.readFileSync('backend/frontend/api-session.js', 'utf8'), context);
const finance = context.AqariFinanceStaging;
const makeClient = context.AqariApiSession.create;

test('contract due balance uses integer halalas and ignores reversed receipts', () => {
  const due = {
    amount: '100.01',
    payments: [
      {amount: '40.01', reversed_at: null},
      {amount: '60.00', reversed_at: null},
      {amount: '8.99', reversed_at: '2026-11-11T08:00:00Z'}
    ]
  };
  assert.equal(finance.remaining(due), 0);
  due.payments[0].reversed_at = '2026-11-11T09:00:00Z';
  assert.equal(finance.remaining(due), 4001);
  assert.equal(finance.money(4001), '40.01');
  assert.equal(finance.money(1), '0.01');
  assert.equal(finance.money(0), '0.00');
});

test('financial parsing rejects invalid precision and negative inputs', () => {
  for (const input of ['0.001', '-1', '1e2', 'NaN', '12,50', '']) {
    assert.throws(() => finance.cents(input));
  }
  assert.equal(finance.cents('100.01'), 10001);
});

test('financial API uses authenticated endpoints and CSRF for all writes', async () => {
  const requests = [];
  const response = {ok: true, status: 200, json: async () => ({data: []})};
  const api = makeClient({
    csrfToken: () => 'staging%2Fcsrf',
    fetch: async (url, options) => {
      requests.push({url, options});
      return response;
    }
  });

  await api.contracts(2);
  await api.contract(7);
  await api.createContract({
    unit_id: 2, tenant_id: 3, start_date: '2026-01-01',
    end_date: '2026-12-31', total: '1200.00', installments: 12
  });
  await api.payments(4);
  await api.payment(8);
  await api.postPayment(5, {amount: '50.00', method: 'bank', received_at: '2026-01-03'});
  await api.reversePayment(8, 'Duplicate bank transfer');
  await api.receivables(12);

  assert.deepEqual(requests.map(r => [r.url, r.options.method]), [
    ['/api/v1/contracts?page=2', 'GET'],
    ['/api/v1/contracts/7', 'GET'],
    ['/api/v1/contracts', 'POST'],
    ['/api/v1/payments?page=4', 'GET'],
    ['/api/v1/payments/8', 'GET'],
    ['/api/v1/dues/5/payments', 'POST'],
    ['/api/v1/payments/8/reverse', 'POST'],
    ['/api/v1/reports/receivables?property_id=12', 'GET']
  ]);
  assert.ok(requests.every(r => r.options.credentials === 'include'));
  const writes = requests.filter(r => r.options.method === 'POST');
  assert.ok(writes.every(r => r.options.headers['X-XSRF-TOKEN'] === 'staging/csrf'));
  assert.equal(JSON.parse(writes.at(-1).options.body).reason, 'Duplicate bank transfer');
  assert.ok(requests.every(r => r.options.headers.Authorization === undefined));
});

test('receipt reversals propagate authentication and validation errors', async () => {
  const api = makeClient({
    fetch: async () => ({
      ok: false, status: 422,
      json: async () => ({message: 'Validation failed', errors: {reason: ['Reason required']}})
    })
  });
  await assert.rejects(api.reversePayment(4, ''), err =>
    err.status === 422 && err.errors.reason[0] === 'Reason required');
});
