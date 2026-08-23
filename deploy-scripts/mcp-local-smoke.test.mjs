import assert from 'node:assert/strict';
import { chmodSync, mkdtempSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import test from 'node:test';

const script = fileURLToPath(new URL('./mcp-local-smoke.mjs', import.meta.url));

function createFixture(t) {
  const root = mkdtempSync(join(tmpdir(), 'geoflow-mcp-smoke-test-'));
  const bin = join(root, 'bin');
  const scratch = join(root, 'tmp');
  const log = join(root, 'docker.jsonl');
  const docker = join(bin, 'docker');
  mkdirSync(bin);
  mkdirSync(scratch);
  writeFileSync(docker, `#!/usr/bin/env node
import { appendFileSync } from 'node:fs';
import readline from 'node:readline';

const [command, ...args] = process.argv.slice(2);
appendFileSync(process.env.FAKE_DOCKER_LOG, JSON.stringify({ command, args, pid: process.pid }) + '\\n');

if (command === 'rm') process.exit(0);
if (command !== 'run') process.exit(2);
if (process.env.FAKE_DOCKER_FAIL === '1') {
  console.error('simulated docker run failure');
  process.exit(125);
}
if (process.env.FAKE_DOCKER_HOLD === '1') {
  process.on('SIGTERM', () => process.exit(0));
  setInterval(() => {}, 1000);
} else {
  readline.createInterface({ input: process.stdin }).on('line', (line) => {
    const message = JSON.parse(line);
    if (message.id === undefined) return;
    const result = message.method === 'initialize'
      ? { protocolVersion: '2025-06-18', capabilities: {}, serverInfo: { name: 'fake', version: '1' } }
      : message.method === 'tools/list'
        ? { tools: [{ name: 'fake' }] }
        : { content: [{ type: 'text', text: 'ok' }], isError: false };
    process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id: message.id, result }) + '\\n');
  });
}
`);
  chmodSync(docker, 0o755);
  t.after(() => rmSync(root, { recursive: true, force: true }));
  return { bin, log, root, scratch };
}

function startSmoke(fixture, extraEnv = {}) {
  const child = spawn(process.execPath, [script], {
    env: {
      ...process.env,
      ...extraEnv,
      PATH: `${fixture.bin}:${process.env.PATH}`,
      TMPDIR: fixture.scratch,
      FAKE_DOCKER_LOG: fixture.log,
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let stdout = '';
  let stderr = '';
  child.stdout.on('data', (chunk) => { stdout += chunk; });
  child.stderr.on('data', (chunk) => { stderr += chunk; });
  const completed = new Promise((resolve) => {
    child.once('close', (code, signal) => resolve({ code, signal, stdout, stderr }));
  });
  return { child, completed };
}

function dockerLog(fixture) {
  return readFileSync(fixture.log, 'utf8')
    .trim()
    .split('\n')
    .filter(Boolean)
    .map((line) => JSON.parse(line));
}

async function waitForRun(fixture) {
  const deadline = Date.now() + 5000;
  while (Date.now() < deadline) {
    try {
      if (dockerLog(fixture).some(({ command }) => command === 'run')) return;
    } catch {
      // The fake Docker process has not created its log yet.
    }
    await new Promise((resolve) => setTimeout(resolve, 20));
  }
  assert.fail('fake Docker did not start');
}

test('completes all pinned MCP smoke calls and cleans temporary state', async (t) => {
  const fixture = createFixture(t);
  const { completed } = startSmoke(fixture);
  const result = await completed;

  assert.equal(result.code, 0, result.stderr);
  const responses = result.stdout.trim().split('\n').map((line) => JSON.parse(line));
  assert.deepEqual(responses.map(({ name }) => name), [
    'fetch',
    'filesystem',
    'memory',
    'sequentialthinking',
    'time',
  ]);
  assert.ok(responses.every(({ ok }) => ok === true));
  const runs = dockerLog(fixture).filter(({ command }) => command === 'run');
  const removals = dockerLog(fixture).filter(({ command }) => command === 'rm');
  assert.equal(runs.length, 5);
  assert.equal(removals.length, 5);
  assert.ok(runs.every(({ args }) => args.some((arg) => arg.includes('@sha256:'))));
  assert.deepEqual(readdirSync(fixture.scratch), []);
});

test('reports an immediate Docker failure and cleans temporary state', async (t) => {
  const fixture = createFixture(t);
  const { completed } = startSmoke(fixture, { FAKE_DOCKER_FAIL: '1' });
  const result = await completed;

  assert.equal(result.code, 1);
  assert.match(result.stderr, /fetch exited \(125\).*simulated docker run failure/s);
  assert.equal(dockerLog(fixture).filter(({ command }) => command === 'rm').length, 1);
  assert.deepEqual(readdirSync(fixture.scratch), []);
});

test('SIGTERM removes the active container and temporary state', async (t) => {
  const fixture = createFixture(t);
  const { child, completed } = startSmoke(fixture, { FAKE_DOCKER_HOLD: '1' });
  await waitForRun(fixture);
  child.kill('SIGTERM');
  const result = await completed;

  assert.equal(result.code, 143);
  assert.equal(dockerLog(fixture).filter(({ command }) => command === 'rm').length, 1);
  assert.deepEqual(readdirSync(fixture.scratch), []);
});
