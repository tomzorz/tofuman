// SPDX-License-Identifier: GPL-2.0-or-later
//
// The GraphQL types of spec section 15, code first, because unraid-api builds its schema from
// the decorated classes of every module it loads. test/schema.test.ts holds these to the spec.

import { Field, ID, InputType, ObjectType, registerEnumType } from '@nestjs/graphql';

import { TofumanConfigType, TofumanOperationState } from './shapes.js';

registerEnumType(TofumanConfigType, { name: 'TofumanConfigType' });
registerEnumType(TofumanOperationState, { name: 'TofumanOperationState' });

@ObjectType()
export class TofumanConfigEntry {
  @Field(() => TofumanConfigType) type!: TofumanConfigType;
  @Field() name!: string;
  @Field() target!: string;
  @Field() value!: string;
  @Field() default!: string;
  @Field() mode!: string;
  @Field() description!: string;
  @Field() display!: string;
  @Field() required!: boolean;
  @Field() mask!: boolean;
}

@InputType()
export class TofumanConfigEntryInput {
  @Field(() => TofumanConfigType) type!: TofumanConfigType;
  @Field() name!: string;
  @Field() target!: string;
  @Field() value!: string;
  @Field() default!: string;
  @Field() mode!: string;
  @Field() description!: string;
  @Field() display!: string;
  @Field() required!: boolean;
  @Field() mask!: boolean;
}

@ObjectType()
export class TofumanDefinition {
  @Field() name!: string;
  @Field() repository!: string;
  @Field() network!: string;
  @Field(() => [String]) ipAddresses!: string[];
  @Field() macAddress!: string;
  @Field() autostart!: boolean;
  @Field() privileged!: boolean;
  @Field() cpuset!: string;
  @Field() shell!: string;
  @Field(() => [String]) extraParams!: string[];
  @Field(() => [String]) postArgs!: string[];
  @Field() webUi!: string;
  @Field() icon!: string;
  @Field() overview!: string;
  @Field() category!: string;
  @Field() support!: string;
  @Field() project!: string;
  @Field() readMe!: string;
  @Field() templateUrl!: string;
  @Field() registry!: string;
  @Field() donateText!: string;
  @Field() donateLink!: string;
  @Field() requires!: string;
  @Field(() => [TofumanConfigEntry], { description: 'Without the marker.' }) configEntries!: TofumanConfigEntry[];
}

@InputType()
export class TofumanDefinitionInput {
  @Field() name!: string;
  @Field() repository!: string;
  @Field() network!: string;
  @Field(() => [String]) ipAddresses!: string[];
  @Field() macAddress!: string;
  @Field() autostart!: boolean;
  @Field() privileged!: boolean;
  @Field() cpuset!: string;
  @Field() shell!: string;
  @Field(() => [String]) extraParams!: string[];
  @Field(() => [String]) postArgs!: string[];
  @Field() webUi!: string;
  @Field() icon!: string;
  @Field() overview!: string;
  @Field() category!: string;
  @Field() support!: string;
  @Field() project!: string;
  @Field() readMe!: string;
  @Field() templateUrl!: string;
  @Field() registry!: string;
  @Field() donateText!: string;
  @Field() donateLink!: string;
  @Field() requires!: string;
  @Field(() => [TofumanConfigEntryInput]) configEntries!: TofumanConfigEntryInput[];
}

@ObjectType()
export class TofumanContainer {
  @Field(() => ID, { description: 'The managed ID.' }) id!: string;
  @Field(() => TofumanDefinition) definition!: TofumanDefinition;
  @Field() running!: boolean;
  @Field(() => String, { nullable: true, description: 'ISO 8601, UTC.' }) lastMutationAt!: string | null;
  @Field() changedSinceLastMutation!: boolean;
}

@ObjectType()
export class TofumanOperation {
  @Field(() => ID) id!: string;
  @Field(() => TofumanOperationState) state!: TofumanOperationState;
  @Field(() => String, { nullable: true, description: 'The step of section 9 that runs or that failed.' }) step!: string | null;
  @Field(() => String, { nullable: true }) error!: string | null;
  @Field(() => TofumanContainer, { nullable: true, description: 'Set after a successful create or update.' }) container!: TofumanContainer | null;
}

/** The namespace of the queries. Its fields come from TofumanQueryResolver. */
@ObjectType()
export class TofumanQuery {}

/** The namespace of the mutations. Its fields come from TofumanMutationResolver. */
@ObjectType()
export class TofumanMutation {}
