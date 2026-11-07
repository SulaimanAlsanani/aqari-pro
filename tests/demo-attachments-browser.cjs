'use strict';
// Browser acceptance test for the GitHub Pages-only demo (synthetic data only).
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
  try {
    const context = await browser.newContext({ acceptDownloads: true });
    const page = await context.newPage();
    page.on('pageerror', error => { console.error('PAGE ERROR:', error.message); });
    await page.goto('http://127.0.0.1:8765/', { waitUntil: 'domcontentloaded' });

    await page.locator('[data-page="properties"]').click();
    const record = page.locator('.record[data-attach-entity="properties"]').first();
    const button = record.locator('[data-attachment-open]');
    await button.waitFor();
    await button.click();
    const panel = page.locator('#attachmentPanel [role="dialog"]');
    await panel.waitFor();

    const file = { name: 'sample-attachment.txt', mimeType: 'text/plain', buffer: Buffer.from('Aqari test attachment only') };
    await page.locator('#attachmentInput').setInputFiles(file);
    await panel.waitFor({ state: 'detached' });
    await page.locator('#attachmentToast').getByText('تم إرفاق الملف').waitFor();
    await assert.equal(await button.innerText(), '📎 مرفق (1)');

    // IndexedDB persists after reload and the badge is reconstructed.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.locator('[data-page="properties"]').click();
    const persisted = page.locator('.record[data-attach-entity="properties"]').first().locator('[data-attachment-open]');
    await page.waitForFunction(() => {
      const b=document.querySelector('.record[data-attach-entity="properties"] [data-attachment-open]');
      return b && b.textContent.includes('(1)');
    });
    await persisted.click();
    await page.locator('#attachmentList .attachment-item').getByText(file.name).waitFor();

    const downloadPromise = page.waitForEvent('download');
    await page.locator('#attachmentList [data-attachment-download]').click();
    const download = await downloadPromise;
    assert.equal(download.suggestedFilename(), file.name);

    // Preview a permitted image with a genuine browser popup and revoke no data.
    await page.locator('#attachmentInput').setInputFiles({
      name: 'preview.png', mimeType: 'image/png',
      buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO9nGZcAAAAASUVORK5CYII=', 'base64')
    });
    await page.locator('#attachmentPanel [role="dialog"]').waitFor({state:'detached'});
    await page.waitForFunction(() => {
      const b=document.querySelector('.record[data-attach-entity="properties"] [data-attachment-open]');
      return b && b.textContent.includes('(2)');
    });
    await persisted.click();
    await page.locator('#attachmentList .attachment-item').getByText('preview.png').waitFor();
    const previewPromise = context.waitForEvent('page');
    await page.locator('#attachmentList [data-attachment-preview]').click();
    const preview = await previewPromise;
    await preview.waitForLoadState('domcontentloaded');
    assert.match(preview.url(), /^blob:/);
    await preview.close();

    // Invalid file extension stays visible with an error, not a success toast.
    await page.locator('#attachmentInput').setInputFiles({name: 'malware.exe', mimeType: 'application/octet-stream', buffer: Buffer.from('not actually malware')});
    await page.locator('#attachmentFeedback').getByText('نوع ملف غير مدعوم').waitFor();
    assert.equal(await page.locator('#attachmentPanel [role="dialog"]').count(), 1);
    assert.equal(await page.locator('#attachmentList .attachment-item').count(), 2);

    // Delete requires explicit confirmation, then the badge resets.
    page.on('dialog', dialog => dialog.accept());
    await page.locator('#attachmentList [data-attachment-delete]').first().click();
    await page.locator('#attachmentList .attachment-item').first().waitFor();
    await page.locator('#attachmentList [data-attachment-delete]').first().click();
    await page.locator('#attachmentList').getByText('لا توجد مرفقات').waitFor();
    await page.waitForFunction(() => {
      const b=document.querySelector('.record[data-attach-entity="properties"] [data-attachment-open]');
      return b && b.textContent==='📎 المرفقات';
    });
    await page.locator('[data-attachment-close]').click();
    assert.equal(await page.locator('#attachmentPanel [role="dialog"]').count(), 0);
    console.log('PASS: upload, modal close, saved-count, reload, download, image preview, invalid-file error, delete and count reset');
  } finally {
    await browser.close();
  }
})().catch(err => { console.error(err); process.exitCode=1; });
