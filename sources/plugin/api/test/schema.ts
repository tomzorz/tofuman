// SPDX-License-Identifier: GPL-2.0-or-later
//
// The schema that unraid-api builds from the decorated classes of the API module, built the same
// way outside unraid-api. The schema test holds it to the spec; the test server serves it.

import 'reflect-metadata';

import { NestFactory } from '@nestjs/core';
import { GraphQLSchemaBuilderModule, GraphQLSchemaFactory } from '@nestjs/graphql';
import { UsePermissionsDirective } from '@unraid/shared/use-permissions.directive.js';
import type { GraphQLSchema } from 'graphql';

import { TofumanMutationResolver, TofumanQueryResolver, TofumanRootResolver } from '../src/resolvers.js';

export async function moduleSchema(): Promise<GraphQLSchema> {
  const app = await NestFactory.createApplicationContext(GraphQLSchemaBuilderModule, { logger: false });
  try {
    return await app.get(GraphQLSchemaFactory).create([TofumanRootResolver, TofumanQueryResolver, TofumanMutationResolver], { directives: [UsePermissionsDirective] });
  } finally {
    await app.close();
  }
}
