const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

const sandbox = {};
vm.runInNewContext(fs.readFileSync('backend/frontend/api-session.js', 'utf8'), sandbox);
const create = sandbox.AqariApiSession.create;

test('multipart private uploads use cookies, CSRF and browser-generated boundaries', async () => {
  const calls = [];
  const file = {name: 'private-deed.pdf'};
  const api = create({
    csrfToken: () => 'csrf%2Fvalue',
    formDataFactory: () => ({
      fields: [],
      append(name, value) { this.fields.push([name, value]); }
    }),
    fetch: async (url, options) => {
      calls.push({url, options});
      return {ok: true, status: 201, json: async () => ({data: {id: 1}})};
    }
  });

  await api.uploadPropertyAttachment(12, file);
  await api.uploadContractAttachment(7, file);
  assert.deepEqual(calls.map(call => call.url), [
    '/api/v1/properties/12/attachments',
    '/api/v1/contracts/7/attachments'
  ]);
  assert.ok(calls.every(call => call.options.method === 'POST'));
  assert.ok(calls.every(call => call.options.credentials === 'include'));
  assert.ok(calls.every(call => call.options.headers['X-XSRF-TOKEN'] === 'csrf/value'));
  assert.ok(calls.every(call => !('Content-Type' in call.options.headers)));
  assert.ok(calls.every(call => call.options.body.fields.length === 1));
  assert.ok(calls.every(call => call.options.body.fields[0][1] === file));
  assert.ok(calls.every(call => call.options.body.fields[0][0] === 'file'));
  assert.equal(api.attachmentDownloadUrl(17), '/api/v1/attachments/17/download');
});

test('attachment lists use parent-scoped authorized endpoints', async () => {
  const paths = [];
  const api = create({fetch: async (url, opts) => {
    paths.push([url, opts.credentials]);
    return {ok: true, json: async () => ({data: [], last_page: 1})};
  }});
  await api.propertyAttachments(12, 3);
  await api.contractAttachments(8, 2);
  assert.deepEqual(paths, [
    ['/api/v1/properties/12/attachments?page=3', 'include'],
    ['/api/v1/contracts/8/attachments?page=2', 'include']
  ]);
});

test('upload errors preserve validation failures instead of exposing storage paths', async () => {
  const api = create({
    formDataFactory: () => ({append() {}}),
    fetch: async () => ({ok: false, status: 422, json: async () =>
      ({message: 'Invalid upload', errors: {file: ['Disallowed type']}})})
  });
  await assert.rejects(api.uploadPropertyAttachment(2, {name: 'unsafe.svg'}), e =>
    e.status === 422 && e.errors.file[0] === 'Disallowed type');
});
