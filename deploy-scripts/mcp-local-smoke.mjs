#!/usr/bin/env node

import { mkdirSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';

const root = process.env.MCP_LOCAL_ROOT ?? `/tmp/geoflow-mcp-local-${process.pid}`;
const fetchUrl = process.env.MCP_LOCAL_FETCH_URL ?? 'http://127.0.0.1:18080/';
const memoryPath = `${root}/memory`;
const filesystemPath = `${root}/filesystem`;
mkdirSync(memoryPath, { recursive: true });
mkdirSync(filesystemPath, { recursive: true });

const definitions = {
  fetch: ['--network', 'host', 'mcp/fetch'],
  filesystem: ['--mount', `type=bind,src=${filesystemPath},dst=/projects`, 'mcp/filesystem', '/projects'],
  memory: ['--mount', `type=bind,src=${memoryPath},dst=/data`, '-e', 'MEMORY_FILE_PATH=/data/memory.jsonl', 'mcp/memory'],
  sequentialthinking: ['mcp/sequentialthinking'],
  time: ['mcp/time', '--local-timezone', 'Asia/Shanghai'],
};

const calls = {
  fetch: ['fetch', { url: fetchUrl, max_length: 500 }],
  filesystem: ['list_directory', { path: '/projects' }],
  memory: ['create_entities', { entities: [{ name: 'geo-local-smoke', entityType: 'test', observations: ['MCP local smoke'] }] }],
  sequentialthinking: ['sequentialthinking', { thought: 'MCP local smoke', nextThoughtNeeded: false, thoughtNumber: 1, totalThoughts: 1 }],
  time: ['get_current_time', { timezone: 'Asia/Shanghai' }],
};

function fail(message) {
  throw new Error(message);
}

function start(name) {
  const container = `geoflow-mcp-${name}-${randomUUID().slice(0, 8)}`;
  const args = ['run', '--name', container, '--rm', '-i', ...definitions[name]];
  const child = spawn('docker', args, { stdio: ['pipe', 'pipe', 'pipe'] });
  let buffer = '';
  const pending = new Map();
  const stderr = [];
  child.stderr.on('data', (chunk) => stderr.push(chunk.toString()));
  child.stdout.on('data', (chunk) => {
    buffer += chunk.toString();
    while (buffer.includes('\n')) {
      const index = buffer.indexOf('\n');
      const line = buffer.slice(0, index).trim();
      buffer = buffer.slice(index + 1);
      if (!line) continue;
      try {
        const message = JSON.parse(line);
        const waiter = pending.get(message.id);
        if (waiter) {
          clearTimeout(waiter.timer);
          pending.delete(message.id);
          waiter.resolve(message);
        }
      } catch {
        // Ignore non-protocol output; diagnostics remain available in stderr.
      }
    }
  });
  const request = (method, params = {}) => new Promise((resolve, reject) => {
    const id = Math.floor(Math.random() * 1e9);
    const timer = setTimeout(() => {
      pending.delete(id);
      reject(new Error(`${name} timeout: ${method}`));
    }, 30000);
    pending.set(id, { resolve, timer });
    child.stdin.write(`${JSON.stringify({ jsonrpc: '2.0', id, method, params })}\n`);
  });
  const notify = (method, params = {}) => child.stdin.write(`${JSON.stringify({ jsonrpc: '2.0', method, params })}\n`);
  const cleanup = () => {
    child.kill('SIGTERM');
    spawnSync('docker', ['rm', '-f', container], { stdio: 'ignore' });
  };
  return { child, request, notify, cleanup, stderr, container };
}

async function smoke(name) {
  const server = start(name);
  try {
    const initialize = await server.request('initialize', {
      protocolVersion: '2025-06-18',
      capabilities: {},
      clientInfo: { name: 'geoflow-local-smoke', version: '1.0.0' },
    });
    server.notify('notifications/initialized');
    const tools = await server.request('tools/list');
    const [toolName, arguments_] = calls[name];
    const result = await server.request('tools/call', { name: toolName, arguments: arguments_ });
    if (initialize.error || tools.error || result.error || result.result?.isError) {
      fail(`${name} returned an MCP error`);
    }
    console.log(JSON.stringify({
      name,
      protocolVersion: initialize.result?.protocolVersion,
      toolCount: tools.result?.tools?.length ?? 0,
      tool: toolName,
      ok: true,
    }));
  } finally {
    server.cleanup();
  }
}

for (const name of Object.keys(definitions)) {
  await smoke(name);
}
