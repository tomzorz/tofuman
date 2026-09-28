// SPDX-License-Identifier: GPL-2.0-or-later
//
// The schema that unraid-api would build from the decorated classes, held to spec section 15.

import 'reflect-metadata';

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { NestFactory } from '@nestjs/core';
import { GraphQLSchemaBuilderModule, GraphQLSchemaFactory } from '@nestjs/graphql';
import { UsePermissionsDirective } from '@unraid/shared/use-permissions.directive.js';
import { buildSchema, type GraphQLSchema, isObjectType, printType } from 'graphql';

import { TofumanMutationResolver, TofumanQueryResolver, TofumanRootResolver } from '../src/resolvers.js';

// build/test/schema.test.js, five levels below the repository root
const SPEC = new URL('../../../../../docs/SPEC.md', import.meta.url);

function specSchema(): GraphQLSchema {
  const block = /```graphql\n([\s\S]*?)```/.exec(readFileSync(SPEC, 'utf8'));
  assert.ok(block?.[1] !== undefined, 'the spec has no graphql block');
  return buildSchema(`type Query { unused: Boolean }\ntype Mutation { unused: Boolean }\n${block[1]}`);
}

async function builtSchema(): Promise<GraphQLSchema> {
  const app = await NestFactory.createApplicationContext(GraphQLSchemaBuilderModule, { logger: false });
  try {
    return await app.get(GraphQLSchemaFactory).create([TofumanRootResolver, TofumanQueryResolver, TofumanMutationResolver], { directives: [UsePermissionsDirective] });
  } finally {
    await app.close();
  }
}

function tofumanTypes(schema: GraphQLSchema): string[] {
  return Object.keys(schema.getTypeMap()).filter((name) => name.startsWith('Tofuman')).sort();
}

function rootField(schema: GraphQLSchema, root: 'Query' | 'Mutation'): string {
  const type = root === 'Query' ? schema.getQueryType() : schema.getMutationType();
  assert.ok(type !== null && type !== undefined && isObjectType(type), `no ${root} type`);
  return String(type.getFields()['tofuman']?.type);
}

test('the schema matches section 15 of the spec', async () => {
  const spec = specSchema();
  const built = await builtSchema();
  assert.deepEqual(tofumanTypes(built), tofumanTypes(spec));
  for (const name of tofumanTypes(spec)) {
    const expected = spec.getType(name);
    const actual = built.getType(name);
    assert.ok(expected !== undefined && actual !== undefined, name);
    assert.equal(printType(actual), printType(expected), `the type ${name}`);
  }
  assert.equal(rootField(built, 'Query'), rootField(spec, 'Query'));
  assert.equal(rootField(built, 'Mutation'), rootField(spec, 'Mutation'));
});
