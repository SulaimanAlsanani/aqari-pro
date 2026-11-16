'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const app = fs.readFileSync(path.join(root, 'app.js'), 'utf8');
const workflow = fs.readFileSync(path.join(root, '.github/workflows/deploy.yml'), 'utf8');

test('mobile navigation remains accessible and keyboard-reachable', () => {
  assert.match(html, /<nav class="navlist" id="nav" aria-label="أقسام النظام"><\/nav>/);
  assert.match(html, /<a class="skip-link" href="#content">/);
  assert.match(html, /<main id="content" tabindex="-1"><\/main>/);
  assert.match(app, /aria-current="page"/);
  assert.match(app, /function renderNav\(\)/);
});

test('small screens can navigate and scroll long data tables', () => {
  assert.match(html, /\.tablewrap\{[^}]*overflow-x:auto/);
  assert.match(html, /@media\(max-width:740px\)/);
  assert.match(html, /\.navbtn\{min-height:44px/);
  assert.match(html, /@media\(max-width:400px\)/);
  assert.match(html, /\.modal\{padding:16px;max-height:calc\(100dvh - 26px\)/);
});

test('Pages deploy writes a normal newline-terminated release marker', () => {
  const release = workflow.split('\n').find(line => line.includes('> dist/release.txt'));
  assert.ok(release, 'missing Pages release marker');
  const expected = "printf '%s" + String.fromCharCode(92) + "n'";
  const invalid = "printf '%s" + String.fromCharCode(92, 92) + "n'";
  assert.ok(release.includes(expected), 'release marker must use a single shell escape');
  assert.ok(!release.includes(invalid), 'double-escaped newline breaks release SHA comparisons');
  assert.match(workflow, /deployed_sha/);
  assert.match(workflow, /Verify deployed public demo over HTTPS/);
});

test('frontend retains the existing local-storage namespace', () => {
  assert.match(app, /KEY='aqari-pro-gh-v1'/);
});
