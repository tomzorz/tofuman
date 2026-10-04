// SPDX-License-Identifier: GPL-2.0-or-later
//
// The resolvers: thin on purpose. Every handler carries the guard of REQ-AUTH-1, because
// unraid-api 4.37.4 and later deny a handler without permission metadata, and unraid-api runs
// its guards on field resolvers too.

import { Args, Context, ID, Int, Mutation, Query, ResolveField, Resolver } from '@nestjs/graphql';
import { AuthAction, Resource, UsePermissions } from '@unraid/shared/use-permissions.directive.js';

import { callerFrom } from './caller.js';
import { TofumanCheck, TofumanContainer, TofumanDefinitionInput, TofumanMutation, TofumanOperation, TofumanQuery } from './model.js';
import { START_CHECK_DEFAULT, TofumanService } from './service.js';
import { parseDefinition } from './shapes.js';

/** DOCKER:CREATE_ANY, which no official handler of unraid-api uses (spec section 8). */
const PERMISSION = { action: AuthAction.CREATE_ANY, resource: Resource.DOCKER };

@Resolver()
export class TofumanRootResolver {
  @Query(() => TofumanQuery)
  @UsePermissions(PERMISSION)
  tofuman(): TofumanQuery {
    return new TofumanQuery();
  }

  @Mutation(() => TofumanMutation, { name: 'tofuman' })
  @UsePermissions(PERMISSION)
  tofumanMutation(): TofumanMutation {
    return new TofumanMutation();
  }
}

@Resolver(() => TofumanQuery)
export class TofumanQueryResolver {
  constructor(private readonly service: TofumanService) {}

  @ResolveField(() => TofumanContainer, { nullable: true, description: 'Exactly one of id or name.' })
  @UsePermissions(PERMISSION)
  container(
    @Args('id', { type: () => ID, nullable: true }) id: string | null | undefined,
    @Args('name', { type: () => String, nullable: true }) name: string | null | undefined,
    @Context() context: unknown,
  ): Promise<TofumanContainer | null> {
    return this.service.get(id ?? null, name ?? null, callerFrom(context));
  }

  @ResolveField(() => [TofumanContainer])
  @UsePermissions(PERMISSION)
  containers(@Context() context: unknown): Promise<TofumanContainer[]> {
    return this.service.list(callerFrom(context));
  }

  @ResolveField(() => TofumanOperation)
  @UsePermissions(PERMISSION)
  operation(@Args('id', { type: () => ID }) id: string, @Context() context: unknown): Promise<TofumanOperation> {
    return this.service.operation(id, callerFrom(context));
  }

  // The definition arrives as `unknown`; see TofumanMutationResolver.
  @ResolveField(() => TofumanCheck, {
    description: 'The checks of a mutation, without the mutation: a definition alone checks createContainer, an id and a definition check updateContainer, and an id alone checks deleteContainer.',
  })
  @UsePermissions(PERMISSION)
  check(
    @Args('id', { type: () => ID, nullable: true }) id: string | null | undefined,
    @Args('definition', { type: () => TofumanDefinitionInput, nullable: true }) definition: unknown,
    @Context() context: unknown,
  ): Promise<TofumanCheck> {
    return this.service.check(id ?? null, definition === null || definition === undefined ? null : parseDefinition(definition), callerFrom(context));
  }
}

// The definition arrives as `unknown`, not as TofumanDefinitionInput. unraid-api runs a global
// ValidationPipe with whitelist and forbidNonWhitelisted, which rejects every field of an input
// class that has no class-validator decorators, and leaves arguments that are not classes alone.
// The shim validates the definition (spec section 6).
@Resolver(() => TofumanMutation)
export class TofumanMutationResolver {
  constructor(private readonly service: TofumanService) {}

  @ResolveField(() => TofumanOperation)
  @UsePermissions(PERMISSION)
  createContainer(
    @Args('definition', { type: () => TofumanDefinitionInput }) definition: unknown,
    @Args('startCheckSeconds', { type: () => Int, defaultValue: START_CHECK_DEFAULT }) startCheckSeconds: number,
    @Context() context: unknown,
  ): Promise<TofumanOperation> {
    return this.service.create(parseDefinition(definition), startCheckSeconds, callerFrom(context));
  }

  @ResolveField(() => TofumanOperation)
  @UsePermissions(PERMISSION)
  updateContainer(
    @Args('id', { type: () => ID }) id: string,
    @Args('definition', { type: () => TofumanDefinitionInput }) definition: unknown,
    @Args('startCheckSeconds', { type: () => Int, defaultValue: START_CHECK_DEFAULT }) startCheckSeconds: number,
    @Context() context: unknown,
  ): Promise<TofumanOperation> {
    return this.service.update(id, parseDefinition(definition), startCheckSeconds, callerFrom(context));
  }

  @ResolveField(() => TofumanOperation)
  @UsePermissions(PERMISSION)
  deleteContainer(@Args('id', { type: () => ID }) id: string, @Context() context: unknown): Promise<TofumanOperation> {
    return this.service.delete(id, callerFrom(context));
  }
}
