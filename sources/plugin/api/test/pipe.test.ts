// SPDX-License-Identifier: GPL-2.0-or-later
//
// unraid-api's global ValidationPipe, with the options of its main.ts (lines 78-88 at v4.35.1),
// over the arguments of every handler of the API module. The pipe rejects every field of an
// input class that has no class-validator decorators: on a server, every mutation once failed
// with "Bad Request Exception" before it reached the shim.

import 'reflect-metadata';

import assert from 'node:assert/strict';
import { test } from 'node:test';

import { type Type, ValidationPipe } from '@nestjs/common';

import { TofumanDefinitionInput } from '../src/model.js';
import { TofumanMutationResolver, TofumanQueryResolver, TofumanRootResolver } from '../src/resolvers.js';

const pipe = new ValidationPipe({ transform: true, whitelist: true, forbidNonWhitelisted: true, transformOptions: { enableImplicitConversion: true } });

const definition = {
  name: 'example', repository: 'busybox:latest', network: 'bridge', ipAddresses: [], macAddress: '', autostart: false, privileged: false,
  cpuset: '', shell: 'sh', extraParams: [], postArgs: [], webUi: '', icon: '', overview: '', category: '', support: '', project: '',
  readMe: '', templateUrl: '', registry: '', donateText: '', donateLink: '', requires: '',
  configEntries: [{ type: 'VARIABLE', name: 'TZ', target: 'TZ', value: 'Etc/UTC', default: '', mode: '', description: '', display: 'always', required: false, mask: false }],
};

function isType(value: unknown): value is Type<unknown> {
  return typeof value === 'function';
}

test('the pipe passes every argument of every handler', async () => {
  for (const resolver of [TofumanRootResolver, TofumanQueryResolver, TofumanMutationResolver]) {
    for (const method of Object.getOwnPropertyNames(resolver.prototype).filter((name) => name !== 'constructor')) {
      const types: unknown = Reflect.getMetadata('design:paramtypes', resolver.prototype, method);
      assert.ok(Array.isArray(types), `${resolver.name}.${method} has no parameter types`);
      const list: unknown[] = types;
      for (const [index, metatype] of list.entries()) {
        assert.ok(isType(metatype), `argument ${index} of ${resolver.name}.${method} has no type`);
        const value = metatype === String ? 'example' : metatype === Number ? 10 : definition;
        await assert.doesNotReject(pipe.transform(value, { type: 'body', metatype, data: undefined }), `the pipe rejects argument ${index} of ${resolver.name}.${method}`);
      }
    }
  }
});

test('the pipe would reject the input class itself, which is why no handler takes it', async () => {
  await assert.rejects(pipe.transform(definition, { type: 'body', metatype: TofumanDefinitionInput, data: undefined }), /Bad Request/);
});
