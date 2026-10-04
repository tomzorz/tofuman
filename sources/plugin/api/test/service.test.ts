// SPDX-License-Identifier: GPL-2.0-or-later
//
// The service against the real shim, the webgui source, and the Docker of the test host
// (REQ-TST-6). Run it through sources/plugin/ci/test.sh, which provides all three.

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { beforeEach, test } from 'node:test';

import { GraphQLError } from 'graphql';

import { DEFAULT_SHIM, TofumanService } from '../src/service.js';
import { type ConfigEntry, type Definition, isRecord, TofumanConfigType, TofumanOperationState } from '../src/shapes.js';
import type { Caller } from '../src/shim.js';

const KEY: Caller = { id: '11111111-1111-4111-8111-111111111111', name: 'tests', admin: false, providerVersion: null };
const STRANGER: Caller = { id: '22222222-2222-4222-8222-222222222222', name: 'stranger', admin: false, providerVersion: null };
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
  process.env['TOFUMAN_RUN_DIR'] = data;
  process.env['TOFUMAN_NOTIFY'] = '/usr/local/emhttp/plugins/tofuman/tests/fixtures/notify.php';
  process.env['TOFUMAN_NOTIFY_LOG'] = join(data, 'notifications.log');
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
  const queued = await tofuman.create(definition(), 10, KEY);
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
  await tofuman.create(definition(), 10, KEY);
  await tofuman.idle();
  const created = await tofuman.get(null, 'tofumantest-api', KEY);
  assert.ok(created !== null);
  const updating = await tofuman.update(created.id, definition({ extraParams: ['--hostname', 'beta'] }), 10, KEY);
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
  await assert.rejects(tofuman.create(definition({ privileged: true, network: 'host' }), 10, KEY), (error: unknown) => {
    refusalWith('privileged needs')(error);
    assert.ok(error instanceof GraphQLError);
    const errors = error.extensions['errors'];
    assert.ok(Array.isArray(errors) && errors.length === 2, `expected 2 errors, got ${JSON.stringify(errors)}`);
    return true;
  });
});

test('a failed operation reports its step', async () => {
  const tofuman = service();
  const queued = await tofuman.create(definition({ extraParams: ['--runtime', 'tofumantest-no-such-runtime'] }), 10, KEY);
  await tofuman.idle();
  const failed = await tofuman.operation(queued.id, KEY);
  assert.equal(failed.state, TofumanOperationState.FAILED);
  assert.equal(failed.step, 'create');
  assert.ok(failed.error?.includes('runtime'), `the error does not name the runtime: ${failed.error}`);
});

test('the key allowlist guards reads and operations as well', async () => {
  const tofuman = service();
  const queued = await tofuman.create(definition(), 10, KEY);
  await tofuman.idle();
  await assert.rejects(tofuman.list(STRANGER), refusalWith('not on the key allowlist'));
  await assert.rejects(tofuman.operation(queued.id, STRANGER), refusalWith('not on the key allowlist'));
  await assert.rejects(tofuman.create(definition({ name: 'tofumantest-api2' }), 10, STRANGER), refusalWith('not on the key allowlist'));
  assert.equal((await tofuman.list({ ...STRANGER, admin: true })).length, 1, 'an administrator was refused');
});

test('the query check answers with the failed checks and counts the policy gaps (REQ-MUT-25)', async () => {
  const tofuman = service();
  assert.deepEqual(await tofuman.check(null, definition(), KEY), { failedChecks: [], policyGaps: 0 });
  const refused = await tofuman.check(null, definition({ privileged: true, extraParams: ['--cap-add', 'NET_ADMIN'] }), KEY);
  assert.equal(refused.policyGaps, 1, JSON.stringify(refused));
  assert.ok(refused.failedChecks.some((check) => check.includes('--cap-add is never allowed')), JSON.stringify(refused));
  await assert.rejects(tofuman.check(null, definition(), STRANGER), refusalWith('not on the key allowlist'));
  assert.ok(!existsSync(join(process.env['TOFUMAN_DATA_DIR'] ?? '', 'audit.jsonl')), 'the query check wrote to the audit log');
});

test('a refusal counts its policy gaps (REQ-PRV-18)', async () => {
  await assert.rejects(service().create(definition({ network: 'host' }), 10, KEY), (error: unknown) => {
    assert.ok(error instanceof GraphQLError && error.extensions['policyGaps'] === 1, `no count of the policy gaps: ${JSON.stringify(error)}`);
    return true;
  });
});

test('a start check outside 0 to 600 seconds is refused and audited (REQ-MUT-16)', async () => {
  const tofuman = service();
  await assert.rejects(tofuman.create(definition(), 601, KEY), refusalWith('startCheckSeconds must be a whole number from 0 to 600'));
  await assert.rejects(tofuman.create(definition(), 1.5, KEY), refusalWith('startCheckSeconds'));
  const audit = readFileSync(join(process.env['TOFUMAN_DATA_DIR'] ?? '', 'audit.jsonl'), 'utf8').trim().split('\n');
  assert.equal(audit.length, 2);
  assert.ok(audit.every((line) => line.includes('"result":"refused"')));
});

test('the operation of a mutation is the line in the operation log (REQ-MUT-20)', async () => {
  const tofuman = service();
  const queued = await tofuman.create(definition(), 10, { ...KEY, providerVersion: '0.2.0' });
  await tofuman.idle();
  const lines = readFileSync(join(process.env['TOFUMAN_DATA_DIR'] ?? '', 'operations.jsonl'), 'utf8').trim().split('\n').map((line): unknown => JSON.parse(line));
  assert.equal(lines.length, 1);
  const line = lines[0];
  assert.ok(isRecord(line), 'the line is not an object');
  assert.equal(line['id'], queued.id);
  assert.equal(line['providerVersion'], '0.2.0');
  assert.equal(line['result'], 'succeeded');
  assert.match(String(line['queuedAt']), /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
});

test('an unknown operation is an error that names it', async () => {
  await assert.rejects(service().operation('no-such-operation', KEY), (error: unknown) => {
    assert.ok(error instanceof GraphQLError && error.extensions['code'] === 'TOFUMAN_UNKNOWN_OPERATION' && error.message.includes('no-such-operation'));
    return true;
  });
});
