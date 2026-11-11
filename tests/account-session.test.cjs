const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

const sandbox = {};
vm.runInNewContext(fs.readFileSync('backend/frontend/api-session.js', 'utf8'), sandbox);
const make = sandbox.AqariApiSession.create;

test('owner profile and password rotation use authenticated, CSRF-protected API', async () => {
  const calls = [];
  const client = make({
    csrfToken: () => 'csrf%2Fsecret',
    fetch: async (path, options) => {
      calls.push({path, options});
      return {ok: true, status: 200, json: async () => ({data: {name:'Owner'}})};
    }
  });
  await client.account();
  await client.updateAccount({name: 'Owner',phone: '0500000000'});
  await client.changePassword({current_password:'OldPass',password:'NewPass',
    password_confirmation:'NewPass'});
  assert.deepEqual(calls.map(x => [x.path,x.options.method]),[
    ['/api/v1/account','GET'],
    ['/api/v1/account','PATCH'],
    ['/api/v1/account/change-password','POST']
  ]);
  assert.ok(calls.every(x=>x.options.credentials === 'include'));
  assert.ok(calls.slice(1).every(x=>x.options.headers['X-XSRF-TOKEN']==='csrf/secret'));
});

test('forgotten-password and one-time reset request CSRF cookie without exposing email in URL',async()=>{
  const calls=[];
  const client=make({csrfToken:()=> 'token',fetch:async(path,options)=>{
    calls.push({path,options});
    return {ok:true,status:200,json:async()=>({message:'accepted'})};
  }});
  await client.requestPasswordReset('owner@example.test');
  await client.resetPassword({
    token:'private-reset-token',email:'owner@example.test',
    password:'NewSecurePass!2026',password_confirmation:'NewSecurePass!2026'
  });
  assert.deepEqual(calls.map(c=>[c.path,c.options.method]),[
    ['/sanctum/csrf-cookie','GET'],
    ['/forgot-password','POST'],
    ['/sanctum/csrf-cookie','GET'],
    ['/reset-password','POST']
  ]);
  assert.ok(calls.every(c=>!c.path.includes('owner@example.test')));
  assert.ok(calls.every(c=>c.options.credentials==='include'));
  assert.ok(calls.filter(c=>c.options.method==='POST')
    .every(c=>c.options.headers['X-XSRF-TOKEN']==='token'));
  assert.equal(JSON.parse(calls[3].options.body).token,'private-reset-token');
});
