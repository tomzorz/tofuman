// SPDX-License-Identifier: GPL-2.0-or-later
//
// The test server of REQ-TST-7: the schema that the API module builds, served over HTTP, with the
// real service and the real shim behind it. The provider tests run against it. It stands in for
// unraid-api only as far as the provider can tell: POST /graphql, and one API key in the header
// x-api-key. POST /person/adopt-from-template?name=... does what a person in the webgui does in
// step 11 of the end-to-end procedure, for the acceptance test of the e2e tool. Start it through
// sources/plugin/ci/test.sh --server, which provides the shim, the webgui source, and Docker.

import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { graphql, GraphQLError, type GraphQLNamedType, isObjectType } from 'graphql';

import { DEFAULT_SHIM, TofumanService } from '../src/service.js';
import { isRecord, parseDefinition } from '../src/shapes.js';
import type { Caller } from '../src/shim.js';
import { moduleSchema } from './schema.js';

const PORT = 8931;
const PERSON = '/usr/local/emhttp/plugins/tofuman/tests/fixtures/person.php';
const API_KEY = process.env['TOFUMAN_TEST_API_KEY'] ?? '';
const CALLER: Caller = { id: '11111111-1111-4111-8111-111111111111', name: 'provider-tests', admin: false, providerVersion: null };

if (API_KEY === '') {
  throw new Error('the test server needs TOFUMAN_TEST_API_KEY');
}

// The policy of the provider tests: their caller on the key allowlist, bind mounts under
// /mnt/tofumantest/, and the test network next to bridge. The notifications of failed
// operations go to the recorder of the shim tests, not into the webgui's notify script.
const data = mkdtempSync(join(tmpdir(), 'tofumantest-server-'));
process.env['TOFUMAN_DATA_DIR'] = data;
process.env['TOFUMAN_LOCK'] = join(data, 'lock');
process.env['TOFUMAN_RUN_DIR'] = data;
process.env['TOFUMAN_NOTIFY'] = '/usr/local/emhttp/plugins/tofuman/tests/fixtures/notify.php';
process.env['TOFUMAN_NOTIFY_LOG'] = join(data, 'notifications.log');
writeFileSync(join(data, 'policy.json'), JSON.stringify({
  version: 1,
  keyAllowlist: [CALLER.id],
  bindRoots: ['/mnt/tofumantest/'],
  networks: ['bridge', process.env['TOFUMAN_TEST_NETWORK'] ?? 'bridge'],
  extraParamFlags: ['--hostname'],
  exceptions: {},
}));

interface Context {
  caller: Caller;
}

function text(args: Record<string, unknown>, key: string): string {
  const value = args[key];
  if (typeof value !== 'string') {
    throw new GraphQLError(`the argument ${key} is not a string`);
  }
  return value;
}

function optionalText(args: Record<string, unknown>, key: string): string | null {
  const value = args[key];
  return typeof value === 'string' ? value : null;
}

/** The schema gives startCheckSeconds its default, so it always arrives as a number. */
function integer(args: Record<string, unknown>, key: string): number {
  const value = args[key];
  if (typeof value !== 'number') {
    throw new GraphQLError(`the argument ${key} is not a number`);
  }
  return value;
}

type Resolver = (source: unknown, args: Record<string, unknown>, context: Context) => unknown;

/** Puts resolvers on the fields of one type, the way NestJS puts the module's resolvers there. */
function resolve(type: GraphQLNamedType | null | undefined, resolvers: Record<string, Resolver>): void {
  if (!isObjectType(type)) {
    throw new Error(`the schema lacks a type that the test server resolves: ${String(type)}`);
  }
  const fields = type.getFields();
  for (const [name, resolver] of Object.entries(resolvers)) {
    const field = fields[name];
    if (field === undefined) {
      throw new Error(`the type ${type.name} has no field ${name}`);
    }
    field.resolve = resolver;
  }
}

const service = new TofumanService(DEFAULT_SHIM);
const schema = await moduleSchema();
resolve(schema.getQueryType(), { tofuman: () => ({}) });
resolve(schema.getMutationType(), { tofuman: () => ({}) });
resolve(schema.getType('TofumanQuery'), {
  container: (_source, args, context) => service.get(optionalText(args, 'id'), optionalText(args, 'name'), context.caller),
  containers: (_source, _args, context) => service.list(context.caller),
  operation: (_source, args, context) => service.operation(text(args, 'id'), context.caller),
  check: (_source, args, context) => {
    const definition = args['definition'];
    return service.check(optionalText(args, 'id'), definition === null || definition === undefined ? null : parseDefinition(definition), context.caller);
  },
});
resolve(schema.getType('TofumanMutation'), {
  createContainer: (_source, args, context) => service.create(parseDefinition(args['definition']), integer(args, 'startCheckSeconds'), context.caller),
  updateContainer: (_source, args, context) => service.update(text(args, 'id'), parseDefinition(args['definition']), integer(args, 'startCheckSeconds'), context.caller),
  deleteContainer: (_source, args, context) => service.delete(text(args, 'id'), context.caller),
});

function send(response: ServerResponse, status: number, body: unknown): void {
  response.writeHead(status, { 'content-type': 'application/json' });
  response.end(JSON.stringify(body));
}

function readBody(request: IncomingMessage): Promise<string> {
  return new Promise((resolve, reject) => {
    let body = '';
    request.setEncoding('utf8');
    request.on('data', (chunk: string) => {
      body += chunk;
    });
    request.on('end', () => resolve(body));
    request.on('error', reject);
  });
}

/**
 * REQ-TST-12: what the person in the webgui does in step 11 of the end-to-end procedure, for the
 * acceptance test of the e2e tool: a hand-made container from the adoption test template, adopted.
 */
function adoptFromTemplate(name: string): Promise<{ ok: boolean; output: string }> {
  return new Promise((resolve) => {
    const child = spawn('php', [PERSON, name], { stdio: ['ignore', 'pipe', 'pipe'] });
    let output = '';
    child.stdout.setEncoding('utf8').on('data', (chunk: string) => (output += chunk));
    child.stderr.setEncoding('utf8').on('data', (chunk: string) => (output += chunk));
    child.on('close', (code) => resolve({ ok: code === 0, output }));
  });
}

async function handle(request: IncomingMessage, response: ServerResponse): Promise<void> {
  if (request.method === 'GET' && request.url === '/health') {
    send(response, 200, { ok: true });
    return;
  }
  const person = request.method === 'POST' && request.url?.startsWith('/person/adopt-from-template?name=');
  if (!person && (request.method !== 'POST' || request.url !== '/graphql')) {
    send(response, 404, { errors: [{ message: `the test server has no route for ${request.method} ${request.url}` }] });
    return;
  }
  if (request.headers['x-api-key'] !== API_KEY) {
    send(response, 401, { errors: [{ message: 'API key validation failed', extensions: { code: 'UNAUTHENTICATED' } }] });
    return;
  }
  if (person) {
    const name = new URL(request.url ?? '', 'http://test').searchParams.get('name') ?? '';
    const result = await adoptFromTemplate(name);
    send(response, result.ok ? 200 : 500, result);
    return;
  }
  let payload: unknown;
  try {
    payload = JSON.parse(await readBody(request));
  } catch {
    send(response, 400, { errors: [{ message: 'the request body is not JSON' }] });
    return;
  }
  if (!isRecord(payload) || typeof payload['query'] !== 'string') {
    send(response, 400, { errors: [{ message: 'the request body has no query' }] });
    return;
  }
  const variables = payload['variables'];
  const operationName = payload['operationName'];
  const version = request.headers['x-tofuman-provider'];
  const result = await graphql({
    schema,
    source: payload['query'],
    contextValue: { caller: { ...CALLER, providerVersion: typeof version === 'string' ? version : null } },
    variableValues: isRecord(variables) ? variables : null,
    operationName: typeof operationName === 'string' ? operationName : null,
  });
  send(response, 200, result);
}

createServer((request, response) => {
  handle(request, response).catch((error: unknown) => send(response, 500, { errors: [{ message: String(error) }] }));
}).listen(PORT, () => {
  console.log(`tofuman test server on port ${PORT}`);
});
