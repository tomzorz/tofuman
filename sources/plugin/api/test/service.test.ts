// SPDX-License-Identifier: GPL-2.0-or-later
//
// The service against the real shim, the webgui source, and the Docker of the test host
// (REQ-TST-6). Run it through sources/plugin/ci/test.sh, which provides all three.

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { beforeEach, test } from 'node:test';

import { GraphQLError } from 'graphql';

import { DEFAULT_SHIM, TofumanService } from '../src/service.js';
import { type ConfigEntry, type Definition, TofumanConfigType, TofumanOperationState } from '../src/shapes.js';
import type { Caller } from '../src/shim.js';

const KEY: Caller = { id: '11111111-1111-4111-8111-111111111111', name: 'tests', admin: false };
const STRANGER: Caller = { id: '22222222-2222-4222-8222-222222222222', name: 'stranger', admin: false };
const NETWORK = process.env['TOFUMAN_TEST_NETWORK'] ?? 'bridge';

function service(): TofumanService {
  return new TofumanService(DEFAULT_SHIM);
}

function entry(type: TofumanConfigType, target: string, value: string, mode = ''): ConfigEntry {
  return { type, name: target, target, value, default: '', mode, description: '', display: 'always', required: false, mask: false };
}

function definition(overrides: Partial<Definition> = {}): Definition {
  return {
    name: 'tofumantest-api',
    repository: 'busybox:latest',
    network: 'bridge',
    ipAddresses: [],
    macAddress: '',
    autostart: false,
    privileged: false,
    cpuset: '',
    shell: 'sh',
    extraParams: ['--hostname', 'alpha'],
    postArgs: ['sleep', '3600'],
    webUi: '',
    icon: '',
    overview: '',
    category: '',
    support: '',
    project: '',
    readMe: '',
    templateUrl: '',
    registry: '',
    donateText: '',
    donateLink: '',
    requires: '',
    configEntries: [entry(TofumanConfigType.PATH, '/config', '/mnt/tofumantest/appdata/api', 'rw'), entry(TofumanConfigType.VARIABLE, 'TZ', 'Etc/UTC')],
    ...overrides,
  };
}

function refusalWith(needle: string): (error: unknown) => boolean {
  return (error) => {
    assert.ok(error instanceof GraphQLError, `not a GraphQL error: ${String(error)}`);
    assert.equal(error.extensions['code'], 'TOFUMAN_REFUSED');
    assert.ok(error.message.includes(needle), `the refusal does not mention '${needle}': ${error.message}`);
    return true;
  };
}

beforeEach(() => {
  execFileSync('sh', ['-c', 'docker ps -aq --filter name=tofumantest-api | xargs -r docker rm -f >/dev/null; rm -f /boot/config/plugins/dockerMan/templates-user/my-tofumantest-api*.xml']);
  const data = mkdtempSync(join(tmpdir(), 'tofumantest-api-'));
  process.env['TOFUMAN_DATA_DIR'] = data;
  process.env['TOFUMAN_LOCK'] = join(data, 'lock');
  writeFileSync(join(data, 'policy.json'), JSON.stringify({
    version: 1,
    keyAllowlist: [KEY.id],
    bindRoots: ['/mnt/tofumantest/'],
    networks: ['bridge', NETWORK],
    extraParamFlags: ['--hostname', '--runtime'],
    exceptions: {},
  }));
});

test('a mutation queues an operation, and the operation creates the container', async () => {
  const tofuman = service();
  const queued = await tofuman.create(definition(), KEY);
  assert.equal(queued.state, TofumanOperationState.QUEUED);
  await tofuman.idle();
  const done = await tofuman.operation(queued.id, KEY);
  assert.equal(done.state, TofumanOperationState.SUCCEEDED, done.error ?? '');
  assert.ok(done.container !== null);
  assert.deepEqual(done.container.definition, definition());
  assert.deepEqual(await tofuman.get(done.container.id, null, KEY), done.container);
  assert.deepEqual(await tofuman.get(null, 'tofumantest-api', KEY), done.container);
  assert.deepEqual(await tofuman.list(KEY), [done.container]);
});

test('update and delete run as operations too', async () => {
  const tofuman = service();
  await tofuman.create(definition(), KEY);
  await tofuman.idle();
  const created = await tofuman.get(null, 'tofumantest-api', KEY);
  assert.ok(created !== null);
  const updating = await tofuman.update(created.id, definition({ extraParams: ['--hostname', 'beta'] }), KEY);
  await tofuman.idle();
  assert.deepEqual((await tofuman.operation(updating.id, KEY)).container?.definition.extraParams, ['--hostname', 'beta']);
  const deleting = await tofuman.delete(created.id, KEY);
  await tofuman.idle();
  const deleted = await tofuman.operation(deleting.id, KEY);
  assert.equal(deleted.state, TofumanOperationState.SUCCEEDED, deleted.error ?? '');
  assert.equal(deleted.container, null);
  assert.equal(await tofuman.get(created.id, null, KEY), null);
});

test('a refusal comes back from the mutation itself and names each failed check', async () => {
  const tofuman = service();
  await assert.rejects(tofuman.create(definition({ privileged: true, network: 'host' }), KEY), (error: unknown) => {
    refusalWith('privileged needs')(error);
    assert.ok(error instanceof GraphQLError);
    const errors = error.extensions['errors'];
    assert.ok(Array.isArray(errors) && errors.length === 2, `expected 2 errors, got ${JSON.stringify(errors)}`);
    return true;
  });
});

test('a failed operation reports its step', async () => {
  const tofuman = service();
  const queued = await tofuman.create(definition({ extraParams: ['--runtime', 'tofumantest-no-such-runtime'] }), KEY);
  await tofuman.idle();
  const failed = await tofuman.operation(queued.id, KEY);
  assert.equal(failed.state, TofumanOperationState.FAILED);
  assert.equal(failed.step, 'create');
  assert.ok(failed.error?.includes('runtime'), `the error does not name the runtime: ${failed.error}`);
});

test('the key allowlist guards reads and operations as well', async () => {
  const tofuman = service();
  const queued = await tofuman.create(definition(), KEY);
  await tofuman.idle();
  await assert.rejects(tofuman.list(STRANGER), refusalWith('not on the key allowlist'));
  await assert.rejects(tofuman.operation(queued.id, STRANGER), refusalWith('not on the key allowlist'));
  await assert.rejects(tofuman.create(definition({ name: 'tofumantest-api2' }), STRANGER), refusalWith('not on the key allowlist'));
  assert.equal((await tofuman.list({ ...STRANGER, admin: true })).length, 1, 'an administrator was refused');
});

test('an unknown operation is an error that names it', async () => {
  await assert.rejects(service().operation('no-such-operation', KEY), (error: unknown) => {
    assert.ok(error instanceof GraphQLError && error.extensions['code'] === 'TOFUMAN_UNKNOWN_OPERATION' && error.message.includes('no-such-operation'));
    return true;
  });
});
