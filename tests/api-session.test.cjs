const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
const sandbox={};
vm.runInNewContext(fs.readFileSync('backend/frontend/api-session.js','utf8'),sandbox);
const {create}=sandbox.AqariApiSession;
const json={ok:true,status:200,json:async()=>({ok:true})};
test('login obtains CSRF cookie before sending credentials without persisting tokens',async()=>{
 const calls=[];
 const client=create({fetch:async(...args)=>{calls.push(args);return json;},csrfToken:()=>'encoded%2Fcsrf'});
 await client.login('owner@example.test','testing-secret');
 assert.equal(calls.length,2);
 assert.equal(calls[0][0],'/sanctum/csrf-cookie');
 assert.equal(calls[1][0],'/login');
 assert.equal(calls[1][1].credentials,'include');
 assert.equal(calls[1][1].headers['X-XSRF-TOKEN'],'encoded/csrf');
 assert.deepEqual(JSON.parse(calls[1][1].body),{email:'owner@example.test',password:'testing-secret'});
 assert.equal(calls[1][1].headers.Authorization,undefined);
});
test('user profile and property requests are credentialed and scoped',async()=>{
 const calls=[];
 const client=create({fetch:async(...args)=>{calls.push(args);return json;}});
 await client.me();await client.properties();await client.tenants();await client.receivables('5');await client.logout();
 assert.deepEqual(calls.map(c=>c[0]),['/api/v1/me','/api/v1/properties?page=1','/api/v1/tenants?page=1','/api/v1/reports/receivables?property_id=5','/logout']);
 assert.equal(calls.every(c=>c[1].credentials==='include'),true);
});
test('API failures preserve HTTP status and validation errors',async()=>{
 const client=create({fetch:async()=>({ok:false,status:422,json:async()=>({message:'Invalid request',errors:{email:['Invalid']}})})});
 await assert.rejects(client.me(),e=>e.status===422&&e.errors.email[0]==='Invalid');
});

test('staging property, unit, tenant writes use expected endpoints and CSRF',async()=>{
 const calls=[];
 const client=create({fetch:async(...args)=>{calls.push(args);return json;},csrfToken:()=>'csrf%2Ftoken'});
 await client.createProperty({name:'Building',city:'Riyadh',owner_id:999});
 await client.createUnit(6,{code:'101',property_id:999});
 await client.createTenant({name:'Tenant',owner_id:999});
 await client.updateProperty(6,{name:'Changed'});
 await client.updateUnit(6,10,{code:'102'});
 await client.updateTenant(8,{name:'Changed'});
 await client.units(6);
 assert.deepEqual(calls.map(c=>[c[0],c[1].method]),[
  ['/api/v1/properties','POST'],
  ['/api/v1/properties/6/units','POST'],
  ['/api/v1/tenants','POST'],
  ['/api/v1/properties/6','PATCH'],
  ['/api/v1/properties/6/units/10','PATCH'],
  ['/api/v1/tenants/8','PATCH'],
  ['/api/v1/properties/6/units?page=1','GET']
 ]);
 assert.equal(calls.every(c=>c[1].credentials==='include'),true);
 assert.equal(calls.slice(0,6).every(c=>c[1].headers['X-XSRF-TOKEN']==='csrf/token'),true);
});

test('pagination requests retain the exact resource and encode requested page',async()=>{
 const calls=[];
 const client=create({fetch:async(...args)=>{calls.push(args);return json;}});
 await client.properties(3);await client.units(12,4);await client.tenants(2);
 assert.deepEqual(calls.map(x=>x[0]),[
  '/api/v1/properties?page=3',
  '/api/v1/properties/12/units?page=4',
  '/api/v1/tenants?page=2'
 ]);
});
