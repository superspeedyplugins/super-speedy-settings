#!/usr/bin/env node
/**
 * Site-wide MCP stdio bridge for Super Speedy WordPress abilities.
 *
 * Requires Node 18+, WordPress 6.9+ and a WordPress Application Password.
 * It talks only to the core Abilities REST API. No MCP Adapter or AI Engine
 * plugin is required on the WordPress site.
 *
 * Environment:
 *   WP_URL                  https://example.com
 *   WP_USER                 WordPress username
 *   WP_APP_PASSWORD         WordPress Application Password
 *   SSP_MCP_NAMESPACES      optional comma-separated namespace allow-list
 */

const DEFAULT_NAMESPACES = [
  'ssp',
  'auto-infinite-scroll',
  'delayed-image-import',
  'external-images',
  'fullscreen-typeform',
  'spro',
  'ssap-addon-country-based-prices',
  'super-speedy-ajax-prices',
  'super-speedy-archives',
  'super-speedy-chat',
  'super-speedy-coming-soon',
  'super-speedy-comments',
  'super-speedy-emails',
  'ssf',
  'ssi',
  'super-speedy-performance',
  'sss',
  'super-speedy-sitemaps',
];

const WP_URL = (process.env.WP_URL || '').replace(/\/$/, '');
const WP_USER = process.env.WP_USER || '';
const WP_APP_PASSWORD = process.env.WP_APP_PASSWORD || '';
const namespaceSetting = process.env.SSP_MCP_NAMESPACES || DEFAULT_NAMESPACES.join(',');
const NAMESPACES = namespaceSetting.split(',').map((value) => value.trim()).filter(Boolean);
const EXPOSE_ALL_PUBLIC = NAMESPACES.includes('*');

if (!WP_URL || !WP_USER || !WP_APP_PASSWORD) {
  process.stderr.write('super-speedy-abilities-mcp-bridge: set WP_URL, WP_USER and WP_APP_PASSWORD\n');
  process.exit(1);
}

const AUTH = 'Basic ' + Buffer.from(`${WP_USER}:${WP_APP_PASSWORD}`).toString('base64');
let restBase = null;
let abilityCache = null;

class WordPressError extends Error {
  constructor(code, message, data, status) {
    super(message);
    this.name = 'WordPressError';
    this.code = code || 'wordpress_error';
    this.data = data || null;
    this.status = status || 500;
  }
}

async function resolveRestBase() {
  if (restBase) return restBase;
  const pretty = `${WP_URL}/wp-json/wp-abilities/v1/abilities`;
  try {
    const response = await fetch(pretty, { headers: { Authorization: AUTH, Accept: 'application/json' } });
    if (response.ok && (response.headers.get('content-type') || '').includes('json')) {
      restBase = { url: (path) => `${WP_URL}/wp-json${path}` };
      return restBase;
    }
  } catch {
    // Try the plain-permalink form below.
  }
  restBase = { url: (path) => `${WP_URL}/index.php?rest_route=${encodeURIComponent(path)}` };
  return restBase;
}

function withQuery(url, params) {
  return url + (url.includes('?') ? '&' : '?') + new URLSearchParams(params).toString();
}

async function wpRequest(path, { method = 'GET', query = null, body = null } = {}) {
  const base = await resolveRestBase();
  let url = base.url(path);
  if (query) url = withQuery(url, query);
  const response = await fetch(url, {
    method,
    headers: {
      Authorization: AUTH,
      Accept: 'application/json',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await response.text();
  let json;
  try {
    json = JSON.parse(text);
  } catch {
    throw new WordPressError('non_json_response', `Non-JSON response from ${path}`, { preview: text.slice(0, 200) }, response.status);
  }
  if (!response.ok) {
    throw new WordPressError(json && json.code, json && json.message ? json.message : `HTTP ${response.status}`, json && json.data, response.status);
  }
  return { json, response };
}

async function wpFetch(path, options = {}) {
  return (await wpRequest(path, options)).json;
}

function namespaceAllowed(name) {
  if (EXPOSE_ALL_PUBLIC) return true;
  return NAMESPACES.some((namespace) => name.startsWith(namespace + '/'));
}

async function loadAbilities() {
  if (abilityCache) return abilityCache;
  const manifest = await wpFetch('/wp-abilities/v1/abilities/ssp/list-permitted-abilities/run');
  const permitted = new Set(Array.isArray(manifest.abilities) ? manifest.abilities : []);
  const abilities = [];
  let page = 1;
  let totalPages = 1;
  do {
    const result = await wpRequest('/wp-abilities/v1/abilities', {
      query: { per_page: '100', page: String(page) },
    });
    const rows = Array.isArray(result.json) ? result.json : Object.values(result.json);
    abilities.push(...rows);
    totalPages = Math.max(1, Number(result.response.headers.get('x-wp-totalpages') || 1));
    page++;
  } while (page <= totalPages);
  abilityCache = abilities.filter((ability) =>
    ability && typeof ability.name === 'string' && permitted.has(ability.name) && namespaceAllowed(ability.name)
  );

  const names = new Set();
  for (const ability of abilityCache) {
    const name = toolName(ability);
    if (names.has(name)) throw new Error(`Duplicate MCP tool name after normalisation: ${name}`);
    names.add(name);
  }
  return abilityCache;
}

const toolName = (ability) => ability.name.replaceAll('/', '-');
const findAbility = async (name) => (await loadAbilities()).find((ability) => toolName(ability) === name);

function phpQueryEntries(value, prefix, output) {
  if (value === null || value === undefined) return output;
  if (Array.isArray(value)) {
    value.forEach((child, index) => phpQueryEntries(child, `${prefix}[${index}]`, output));
  } else if (typeof value === 'object') {
    for (const [key, child] of Object.entries(value)) phpQueryEntries(child, `${prefix}[${key}]`, output);
  } else {
    output[prefix] = typeof value === 'boolean' ? (value ? '1' : '0') : String(value);
  }
  return output;
}

async function callAbility(ability, args) {
  const readonly = !!(ability.meta && ability.meta.annotations && ability.meta.annotations.readonly);
  const path = `/wp-abilities/v1/abilities/${ability.name}/run`;
  if (!readonly) return wpFetch(path, { method: 'POST', body: { input: args || {} } });
  const hasArgs = args && typeof args === 'object' && Object.keys(args).length > 0;
  return wpFetch(path, { query: hasArgs ? phpQueryEntries(args, 'input', {}) : null });
}

function toolAnnotations(ability) {
  const annotations = ability.meta && ability.meta.annotations ? ability.meta.annotations : {};
  return {
    readOnlyHint: !!annotations.readonly,
    destructiveHint: !!annotations.destructive,
    idempotentHint: !!annotations.idempotent,
    openWorldHint: false,
  };
}

const reply = (id, result) =>
  process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id, result }) + '\n');
const replyError = (id, code, message, data) =>
  process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id, error: { code, message, ...(data ? { data } : {}) } }) + '\n');

async function handle(message) {
  const { id, method, params } = message;
  const notification = id === undefined || id === null;
  try {
    switch (method) {
      case 'initialize':
        return reply(id, {
          protocolVersion: (params && params.protocolVersion) || '2025-06-18',
          capabilities: { tools: { listChanged: false } },
          serverInfo: { name: 'super-speedy-abilities-bridge', version: '1.0.0' },
        });
      case 'notifications/initialized':
      case 'notifications/cancelled':
        return;
      case 'ping':
        return reply(id, {});
      case 'tools/list': {
        const abilities = await loadAbilities();
        return reply(id, {
          tools: abilities.map((ability) => ({
            name: toolName(ability),
            description: [ability.label, ability.description].filter(Boolean).join(': '),
            inputSchema: ability.input_schema && ability.input_schema.type
              ? ability.input_schema
              : { type: 'object', properties: {} },
            annotations: toolAnnotations(ability),
          })),
        });
      }
      case 'tools/call': {
        const ability = await findAbility(params && params.name);
        if (!ability) return replyError(id, -32602, `Unknown tool: ${params && params.name}`);
        try {
          const result = await callAbility(ability, params && params.arguments);
          return reply(id, {
            content: [{ type: 'text', text: JSON.stringify(result, null, 2) }],
            structuredContent: result,
            isError: false,
          });
        } catch (error) {
          const structured = {
            code: error.code || 'bridge_call_failed',
            message: String(error.message || error),
            ...(error.data ? { data: error.data } : {}),
          };
          return reply(id, {
            content: [{ type: 'text', text: `${structured.code}: ${structured.message}` }],
            structuredContent: structured,
            isError: true,
          });
        }
      }
      default:
        if (!notification) replyError(id, -32601, `Method not found: ${method}`);
    }
  } catch (error) {
    if (!notification) replyError(id, -32603, String(error.message || error));
    else process.stderr.write(`bridge error: ${error.message || error}\n`);
  }
}

let buffer = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => {
  buffer += chunk;
  let newline;
  while ((newline = buffer.indexOf('\n')) !== -1) {
    const line = buffer.slice(0, newline).trim();
    buffer = buffer.slice(newline + 1);
    if (!line) continue;
    let message;
    try {
      message = JSON.parse(line);
    } catch {
      continue;
    }
    handle(message);
  }
});
process.stdin.on('end', () => {});
