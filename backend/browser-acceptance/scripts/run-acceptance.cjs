'use strict';
const {spawnSync} = require('node:child_process');
const {resolveStagingOrigin, resolveAcceptanceMode} = require('../staging-guard.cjs');

const requested = process.argv[2];
const flags = process.argv.slice(3);
if (!['read', 'live', 'write'].includes(requested)) {
  console.error('Choose exactly one acceptance mode: read, live, or write.');
  process.exit(2);
}
// Only allow the non-executing --list flag. No arbitrary test path, grep,
// shard, browser override, config or CLI options can be injected here.
if (flags.length > 1 || (flags.length === 1 && flags[0] !== '--list')) {
  console.error('The only supported extra argument is --list (offline discovery).');
  process.exit(2);
}

const env = {...process.env, STAGING_ACCEPTANCE_MODE: requested};
try {
  resolveStagingOrigin(env);
  resolveAcceptanceMode(env);
} catch (error) {
  // Fail without printing credentials or connection strings.
  console.error('Staging acceptance was blocked: ' + error.message);
  process.exit(2);
}

// Run only an installed local Playwright CLI; never download a runner here.
let cli;
try {
  cli = require.resolve('@playwright/test/cli');
} catch {
  console.error('Install the pinned @playwright/test dependency before running browser checks.');
  process.exit(2);
}
const result = spawnSync(process.execPath, [cli, 'test', ...flags], {
  cwd: require('node:path').resolve(__dirname, '..'),
  env,
  stdio: 'inherit',
});
if (result.error) {
  console.error('Unable to launch local Playwright.');
  process.exit(2);
}
process.exit(result.status ?? 2);
