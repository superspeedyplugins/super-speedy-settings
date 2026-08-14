#!/usr/bin/env node

import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { fileURLToPath } from 'node:url';

const bridge = fileURLToPath(new URL('./super-speedy-abilities-mcp-bridge.mjs', import.meta.url));
const requests = [];

const abilities = [
  {
    name: 'ssp/list-plugins',
    label: 'List plugins',
    description: 'List the range.',
    input_schema: { type: 'object', properties: { slug: { type: 'string' } } },
    meta: { annotations: { readonly: true, destructive: false, idempotent: true }, mcp: { public: true } },
  },
  {
    name: 'sss/set-weights',
    label: 'Set weights',
    description: 'Update search weights.',
    input_schema: { type: 'object', properties: { title: { type: 'number' } } },
    meta: { annotations: { readonly: false, destructive: false, idempotent: true }, mcp: { public: true } },
  },
  {
    name: 'unrelated/public-tool',
    label: 'Unrelated',
    input_schema: { type: 'object' },
    meta: { annotations: { readonly: true }, mcp: { public: true } },
  },
  {
    name: 'ssp/private-tool',
    label: 'Private',
    input_schema: { type: 'object' },
    meta: { annotations: { readonly: true }, mcp: { public: false } },
  },
];

const server = createServer(async (request, response) => {
  const url = new URL(request.url, 'http://127.0.0.1');
  response.setHeader('Content-Type', 'application/json');

  // Force the bridge to prove its plain-permalink fallback.
  if (url.pathname.startsWith('/wp-json/')) {
    response.statusCode = 404;
    response.end(JSON.stringify({ code: 'rest_no_route', message: 'Use rest_route.' }));
    return;
  }

  const route = url.searchParams.get('rest_route');
  requests.push({ method: request.method, route, query: Object.fromEntries(url.searchParams) });
  if (route === '/wp-abilities/v1/abilities/ssp/list-permitted-abilities/run') {
    response.end(JSON.stringify({
      total: 2,
      abilities: ['ssp/list-plugins', 'sss/set-weights'],
    }));
    return;
  }
  if (route === '/wp-abilities/v1/abilities') {
    response.setHeader('X-WP-TotalPages', '1');
    response.end(JSON.stringify(abilities));
    return;
  }
  if (route === '/wp-abilities/v1/abilities/ssp/list-plugins/run') {
    response.end(JSON.stringify({ method: request.method, slug: url.searchParams.get('input[slug]') }));
    return;
  }
  if (route === '/wp-abilities/v1/abilities/sss/set-weights/run') {
    let body = '';
    for await (const chunk of request) body += chunk;
    response.end(JSON.stringify({ method: request.method, body: JSON.parse(body) }));
    return;
  }
  response.statusCode = 404;
  response.end(JSON.stringify({ code: 'rest_no_route', message: 'Unknown route.' }));
});

await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const address = server.address();
const child = spawn(process.execPath, [bridge], {
  env: {
    ...process.env,
    WP_URL: `http://127.0.0.1:${address.port}`,
    WP_USER: 'agent',
    WP_APP_PASSWORD: 'test-password',
  },
  stdio: ['pipe', 'pipe', 'pipe'],
});

let nextId = 1;
let output = '';
const pending = new Map();
child.stdout.setEncoding('utf8');
child.stdout.on('data', (chunk) => {
  output += chunk;
  let newline;
  while ((newline = output.indexOf('\n')) !== -1) {
    const line = output.slice(0, newline).trim();
    output = output.slice(newline + 1);
    if (!line) continue;
    const message = JSON.parse(line);
    const waiter = pending.get(message.id);
    if (waiter) {
      pending.delete(message.id);
      waiter.resolve(message);
    }
  }
});

function rpc(method, params = {}) {
  const id = nextId++;
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      pending.delete(id);
      reject(new Error(`Timed out waiting for ${method}`));
    }, 3000);
    pending.set(id, {
      resolve: (message) => {
        clearTimeout(timeout);
        resolve(message);
      },
    });
    child.stdin.write(JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n');
  });
}

try {
  const initialised = await rpc('initialize', { protocolVersion: '2025-06-18' });
  assert.equal(initialised.result.serverInfo.name, 'super-speedy-abilities-bridge');

  const listed = await rpc('tools/list');
  assert.deepEqual(listed.result.tools.map((tool) => tool.name), ['ssp-list-plugins', 'sss-set-weights']);
  assert.deepEqual(listed.result.tools[0].annotations, {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  });

  const read = await rpc('tools/call', { name: 'ssp-list-plugins', arguments: { slug: 'super-speedy-search' } });
  assert.equal(read.result.structuredContent.method, 'GET');
  assert.equal(read.result.structuredContent.slug, 'super-speedy-search');

  const write = await rpc('tools/call', { name: 'sss-set-weights', arguments: { title: 2.5 } });
  assert.equal(write.result.structuredContent.method, 'POST');
  assert.deepEqual(write.result.structuredContent.body, { input: { title: 2.5 } });
  assert.ok(requests.some((request) => request.route === '/wp-abilities/v1/abilities'));

  process.stdout.write('Shared MCP bridge tests passed.\n');
} finally {
  child.kill();
  server.close();
}
