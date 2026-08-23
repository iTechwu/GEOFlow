#!/usr/bin/env node

import { existsSync, mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { randomUUID } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';

const ownsRoot = !process.env.MCP_LOCAL_ROOT;
const root = process.env.MCP_LOCAL_ROOT ?? mkdtempSync(`${tmpdir()}/geoflow-mcp-local-`);
const fetchUrl = process.env.MCP_LOCAL_FETCH_URL ?? 'http://127.0.0.1:18080/';
const memoryPath = `${root}/memory`;
const filesystemPath = `${root}/filesystem`;
mkdirSync(memoryPath, { recursive: true });
mkdirSync(filesystemPath, { recursive: true });

const definitions = {
  fetch: ['--network', 'host', process.env.MCP_FETCH_IMAGE ?? 'mcp/fetch@sha256:1a7a0996a565a0b8ca5c41b42830d4e5f334d33f851596bbd9debb2beedb22d3'],
  filesystem: ['--mount', `type=bind,src=${filesystemPath},dst=/projects`, process.env.MCP_FILESYSTEM_IMAGE ?? 'mcp/filesystem@sha256:35fcf0217ca0d5bf7b0a5bd68fb3b89e08174676c0e0b4f431604512cf7b3f67', '/projects'],
  memory: ['--mount', `type=bind,src=${memoryPath},dst=/data`, '-e', 'MEMORY_FILE_PATH=/data/memory.jsonl', process.env.MCP_MEMORY_IMAGE ?? 'mcp/memory@sha256:db0c2db07a44b6797eba7a832b1bda142ffc899588aae82c92780cbb2252407f',],
  sequentialthinking: [process.env.MCP_SEQUENTIALTHINKING_IMAGE ?? 'mcp/sequentialthinking@sha256:cd3174b2ecf37738654cf7671fb1b719a225c40a78274817da00c4241f465e5f'],
  time: [process.env.MCP_TIME_IMAGE ?? 'mcp/time@sha256:9c46a918633fb474bf8035e3ee90ebac6bcf2b18ccb00679ac4c179cba0ebfcf', '--local-timezone', 'Asia/Shanghai'],
};

const calls = {
  fetch: ['fetch', { url: fetchUrl, max_length: 500 }],
  filesystem: ['list_directory', { path: '/projects' }],
  memory: ['create_entities', { entities: [{ name: 'geo-local-smoke', entityType: 'test', observations: ['MCP local smoke'] }] }],
  sequentialthinking: ['sequentialthinking', { thought: 'MCP local smoke', nextThoughtNeeded: false, thoughtNumber: 1, totalThoughts: 1 }],
  time: ['get_current_time', { timezone: 'Asia/Shanghai' }],
};

const activeServers = new Set();
let shuttingDown = false;

function cleanupAll() {
  if (shuttingDown) return;
  shuttingDown = true;
  for (const server of activeServers) server.cleanup();
  if (ownsRoot && existsSync(root)) rmSync(root, { recursive: true, force: true });
}

process.once('SIGINT', () => { cleanupAll(); process.exit(130); });
process.once('SIGTERM', () => { cleanupAll(); process.exit(143); });
process.once('exit', cleanupAll);

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
  let terminalError = null;
  let closing = false;
  const rejectPending = (error) => {
    terminalError ??= error;
    for (const waiter of pending.values()) {
      clearTimeout(waiter.timer);
      waiter.reject(terminalError);
    }
    pending.clear();
  };
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
    if (terminalError) {
      reject(terminalError);
      return;
    }
    const id = Math.floor(Math.random() * 1e9);
    const timer = setTimeout(() => {
      pending.delete(id);
      reject(new Error(`${name} timeout: ${method}`));
    }, 30000);
    pending.set(id, { resolve, reject, timer });
    child.stdin.write(`${JSON.stringify({ jsonrpc: '2.0', id, method, params })}\n`, (error) => {
      if (!error) return;
      const waiter = pending.get(id);
      if (!waiter) return;
      clearTimeout(waiter.timer);
      pending.delete(id);
      waiter.reject(new Error(`${name} request failed: ${error.message}`));
    });
  });
  const notify = (method, params = {}) => child.stdin.write(`${JSON.stringify({ jsonrpc: '2.0', method, params })}\n`);
  const cleanup = () => {
    closing = true;
    child.kill('SIGTERM');
    spawnSync('docker', ['rm', '-f', container], { stdio: 'ignore' });
    activeServers.delete(server);
  };
  const server = { child, request, notify, cleanup, stderr, container };
  child.once('error', (error) => {
    rejectPending(new Error(`${name} docker process failed: ${error.message}`));
  });
  child.stdin.on('error', (error) => rejectPending(new Error(`${name} stdin failed: ${error.message}`)));
  child.once('exit', (code, signal) => {
    if (shuttingDown || closing) return;
    const detail = server.stderr.join('').trim().split('\n').slice(-3).join(' ');
    rejectPending(new Error(`${name} exited (${code ?? signal})${detail ? `: ${detail}` : ''}`));
  });
  activeServers.add(server);
  return server;
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

try {
  for (const name of Object.keys(definitions)) await smoke(name);
} finally {
  cleanupAll();
}
