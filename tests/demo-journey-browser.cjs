'use strict';
// End-to-end browser validation for synthetic rent contracts, receipts and JSON restore.
const assert=require('node:assert/strict');
const {chromium}=require('playwright');
const url='http://127.0.0.1:8765/';
const key='aqari-pro-gh-v1';
const seed={
  owners:[{id:1,name:'مالك تجريبي',phone:'0500000000',fee:0}],
  properties:[{id:1,ownerId:1,name:'مبنى الاختبار',city:'الرياض',district:'اختبار',type:'سكني'}],
  units:[{id:1,propertyId:1,code:'101',type:'شقة',floor:'الأول',area:100}],
  tenants:[{id:1,name:'مستأجر تجريبي',phone:'0500000000'}],
  contracts:[],dues:[],payments:[],expenses:[],vendors:[],tickets:[],
  documents:[],inspections:[],tasks:[],payouts:[],audit:[],ejarLogs:[]
};
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const ctx=await browser.newContext({acceptDownloads:true});
  await ctx.addInitScript(({key,data})=>{
    if(!localStorage.getItem('aqari-e2e-started')) {
      localStorage.setItem(key,JSON.stringify(data));
      localStorage.setItem('aqari-e2e-started','yes');
    }
  },{key,data:seed});
  const page=await ctx.newPage();
  page.on('pageerror',e=>{console.error('Browser JS error',e.message)});
  const state=()=>page.evaluate(key=>JSON.parse(localStorage.getItem(key)),key);
  await page.goto(url,{waitUntil:'domcontentloaded'});

  await page.locator('[data-page="contracts"]').click();
  await page.locator('[data-do="add"][data-kind="contracts"]').click();
  const form=page.locator('#editForm');
  await form.locator('[name="unitId"]').selectOption('1');
  await form.locator('[name="tenantId"]').selectOption('1');
  await form.locator('[name="start"]').fill('2027-01-31');
  await form.locator('[name="end"]').fill('2027-12-31');
  await form.locator('[name="total"]').fill('1200.01');
  await form.locator('[name="installments"]').fill('12');
  await form.locator('[name="deposit"]').fill('100');
  await form.locator('button[type="submit"]').click();
  await form.waitFor({state:'detached'});
  let db=await state();
  assert.equal(db.contracts.length,1);
  assert.equal(db.dues.length,12);
  assert.equal(db.dues[1].date,'2027-02-28');
  assert.equal(db.dues.reduce((sum,d)=>sum+Math.round(d.amount*100),0),120001);
  assert.equal(new Set(db.dues.map(d=>d.id)).size,12);
  const dueId=db.dues[0].id;

  await page.locator('[data-page="dues"]').click();
  const firstDue=page.locator('.record[data-attach-entity="dues"]').first();
  await firstDue.locator('[data-do="pay"]').click();
  const paymentForm=page.locator('#editForm');
  await paymentForm.locator('[name="amount"]').fill('50.01');
  await paymentForm.locator('[name="date"]').fill('2027-02-01');
  await paymentForm.locator('[name="method"]').selectOption('bank_transfer');
  await paymentForm.locator('[name="reference"]').fill('E2E-RENT');
  await paymentForm.locator('button[type="submit"]').click();
  await paymentForm.waitFor({state:'detached'});
  db=await state();
  assert.equal(db.payments.length,1);
  assert.equal(db.payments[0].dueId,dueId);
  assert.equal(Math.round(db.payments[0].amount*100),5001);

  // The overpayment attempt must be rejected, with no new payment recorded.
  await page.locator('.record[data-attach-entity="dues"]').first().locator('[data-do="pay"]').click();
  await page.locator('#editForm [name="amount"]').fill('100.00');
  await page.locator('#editForm [name="reference"]').fill('E2E-OVERPAY');
  const rejectedDialogPromise=new Promise(resolve=>{
    page.once('dialog',async dialog=>{
      const message=dialog.message();
      await dialog.accept();
      resolve(message);
    });
  });
  await page.locator('#editForm button[type="submit"]').click();
  assert.match(await Promise.race([rejectedDialogPromise,new Promise((_,reject)=>setTimeout(()=>reject(Error('لم تظهر رسالة رفض التحصيل الزائد خلال 10 ثوانٍ')),10000))]),/المتبقي/);
  page.on('dialog',dialog=>dialog.accept());
  assert.equal((await state()).payments.length,1);
  await page.locator('[data-do="close"]').click();

  // Restore a valid earlier JSON snapshot from a local browser file.
  await page.locator('[data-page="settings"]').click();
  const restored={...JSON.parse(JSON.stringify(db)),payments:[]};
  await page.locator('#restoreBackupInput').setInputFiles({
    name:'demo-restore.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(restored))
  });
  await page.waitForFunction(key=>{
    const x=JSON.parse(localStorage.getItem(key));
    return x && x.contracts.length===1 && x.payments.length===0;
  },key);
  assert.equal((await state()).payments.length,0);
  await page.reload({waitUntil:'domcontentloaded'});
  assert.equal((await state()).payments.length,0);

  // Invalid foreign-key import is rejected and existing state preserved.
  await page.locator('[data-page="settings"]').click();
  const broken=JSON.parse(JSON.stringify(restored));
  broken.dues[0].contractId=999999;
  await page.locator('#restoreBackupInput').setInputFiles({
    name:'broken-demo.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(broken))
  });
  await page.waitForFunction(()=>document.querySelector('[data-page="settings"].active')!==null);
  assert.equal((await state()).dues[0].contractId,db.contracts[0].id);
  assert.equal((await state()).payments.length,0);
  console.log('PASS: browser contract, exact installments, partial rent receipt, overpayment rejection, valid restore, corrupt restore rejection');
 }finally{await browser.close()}
})().catch(err=>{console.error(err);process.exitCode=1});
