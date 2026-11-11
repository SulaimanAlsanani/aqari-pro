const test = require('node:test');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const fs = require('node:fs');

test('real S3 recovery verifier is valid Bash and rejects missing isolated settings', () => {
  const script='backend/scripts/verify-real-s3-recovery.sh';
  assert.equal(fs.existsSync(script),true);
  const syntax=spawnSync('bash',['-n',script],{encoding:'utf8'});
  assert.equal(syntax.status,0,syntax.stderr);
  const run=spawnSync('bash',[script],{
    encoding:'utf8',
    env:{PATH:process.env.PATH||'/usr/bin:/bin'},
  });
  assert.notEqual(run.status,0,'Recovery must refuse to run without an isolated environment');
  assert.match(run.stderr,/Missing recovery setting/);
});

test('real verifier cannot restore into arbitrary production DB names', () => {
  const script=fs.readFileSync('backend/scripts/verify-real-s3-recovery.sh','utf8');
  assert.match(script,/aqari_restore_/);
  assert.match(script,/pg-restore-verify\.sh/);
  assert.match(script,/verify-attachment-export/);
  assert.match(script,/ciphertext_sha256/);
  assert.match(script,/get-object/);
  assert.match(script,/sha256sum/);
  assert.match(script,/age -d/);
});
