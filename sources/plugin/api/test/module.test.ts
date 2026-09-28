// SPDX-License-Identifier: GPL-2.0-or-later
//
// The entry point the way unraid-api's plugin loader uses it: the exports it validates, and the
// module started in a NestJS application context.

import 'reflect-metadata';

import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';

import { NestFactory } from '@nestjs/core';

import { adapter, ApiModule } from '../src/index.js';
import { isRecord } from '../src/shapes.js';

test('the exports pass the checks of the plugin loader (plugin.interface.ts)', () => {
  assert.equal(adapter, 'nestjs');
  assert.ok(ApiModule.toString().startsWith('class'), 'the loader takes ApiModule only if it prints as a class');
});

test('the module starts and records the load (REQ-PKG-15)', async () => {
  const file = join(mkdtempSync(join(tmpdir(), 'tofumantest-module-')), 'tofuman-api.json');
  process.env['TOFUMAN_LOADED_FILE'] = file;
  const app = await NestFactory.createApplicationContext(ApiModule, { logger: false });
  await app.close();
  const record: unknown = JSON.parse(readFileSync(file, 'utf8'));
  assert.ok(isRecord(record), 'the record is not an object');
  assert.equal(record['pid'], process.pid);
  assert.equal(typeof record['version'], 'string');
  assert.equal(typeof record['loadedAt'], 'string');
});
