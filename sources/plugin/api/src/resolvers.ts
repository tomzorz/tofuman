// SPDX-License-Identifier: GPL-2.0-or-later
//
// The resolvers: thin on purpose. Every handler carries the guard of REQ-AUTH-1, because
// unraid-api 4.37.4 and later deny a handler without permission metadata, and unraid-api runs
// its guards on field resolvers too.

import { Args, Context, ID, Mutation, Query, ResolveField, Resolver } from '@nestjs/graphql';
import { AuthAction, Resource, UsePermissions } from '@unraid/shared/use-permissions.directive.js';

import { callerFrom } from './caller.js';
import { TofumanContainer, TofumanDefinitionInput, TofumanMutation, TofumanOperation, TofumanQuery } from './model.js';
import { TofumanService } from './service.js';

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
}

@Resolver(() => TofumanMutation)
export class TofumanMutationResolver {
  constructor(private readonly service: TofumanService) {}

  @ResolveField(() => TofumanOperation)
  @UsePermissions(PERMISSION)
  createContainer(@Args('definition', { type: () => TofumanDefinitionInput }) definition: TofumanDefinitionInput, @Context() context: unknown): Promise<TofumanOperation> {
    return this.service.create(definition, callerFrom(context));
  }

  @ResolveField(() => TofumanOperation)
  @UsePermissions(PERMISSION)
  updateContainer(
    @Args('id', { type: () => ID }) id: string,
    @Args('definition', { type: () => TofumanDefinitionInput }) definition: TofumanDefinitionInput,
    @Context() context: unknown,
  ): Promise<TofumanOperation> {
    return this.service.update(id, definition, callerFrom(context));
  }

  @ResolveField(() => TofumanOperation)
  @UsePermissions(PERMISSION)
  deleteContainer(@Args('id', { type: () => ID }) id: string, @Context() context: unknown): Promise<TofumanOperation> {
    return this.service.delete(id, callerFrom(context));
  }
}
