// SPDX-License-Identifier: GPL-2.0-or-later
//
// What the resolvers do, with nothing NestJS in it but the logger, so the tests run it against
// the real shim.

import { randomUUID } from 'node:crypto';

import { Logger } from '@nestjs/common';
import { GraphQLError } from 'graphql';

import { type CheckView, type ContainerView, type Definition, parseCheck, parseContainerView, parseContainerViews, TofumanOperationState } from './shapes.js';
import { type Caller, runShim, ShimError } from './shim.js';

/** Where the .plg file installs the shim (REQ-PKG-6). */
export const DEFAULT_SHIM = '/usr/local/emhttp/plugins/tofuman/shim/tofuman-shim.php';

/** REQ-MUT-6: at least 1 hour after the operation ends. */
const KEEP_ENDED_MS = 60 * 60 * 1000;

/** REQ-MUT-16: the length of the start check, in seconds. */
export const START_CHECK_DEFAULT = 10;
export const START_CHECK_MAX = 600;

export interface OperationView {
  id: string;
  state: TofumanOperationState;
  step: string | null;
  error: string | null;
  container: ContainerView | null;
}

interface OperationRecord extends OperationView {
  endedAt: number | null;
}

type Mutation = 'createContainer' | 'updateContainer' | 'deleteContainer';
type Action = 'create' | 'update' | 'delete';

export class TofumanService {
  private readonly operations = new Map<string, OperationRecord>();
  private queue: Promise<void> = Promise.resolve();
  // REQ-PRV-17's counterpart on the server: one line per operation in the log of unraid-api
  private readonly logger = new Logger('tofuman');

  constructor(private readonly shimPath: string) {}

  async get(id: string | null, name: string | null, caller: Caller): Promise<ContainerView | null> {
    const result = await this.shim('get', { id, name }, caller);
    return result === null ? null : parseContainerView(result);
  }

  async list(caller: Caller): Promise<ContainerView[]> {
    return parseContainerViews(await this.shim('list', {}, caller));
  }

  /** REQ-MUT-25: the checks of a mutation, without the mutation. */
  async check(id: string | null, definition: Definition | null, caller: Caller): Promise<CheckView> {
    return parseCheck(await this.shim('checkQuery', { id, definition }, caller));
  }

  /** REQ-MUT-7: a restart of unraid-api loses the operations in memory. */
  async operation(id: string, caller: Caller): Promise<OperationView> {
    await this.shim('authorize', {}, caller);
    const record = this.operations.get(id);
    if (record === undefined) {
      throw new GraphQLError(`tofuman knows no operation ${id}; a restart of unraid-api loses the operations in memory`, { extensions: { code: 'TOFUMAN_UNKNOWN_OPERATION' } });
    }
    return snapshot(record);
  }

  create(definition: Definition, startCheckSeconds: number, caller: Caller): Promise<OperationView> {
    return this.start('createContainer', 'create', null, definition, startCheckSeconds, caller);
  }

  update(id: string, definition: Definition, startCheckSeconds: number, caller: Caller): Promise<OperationView> {
    return this.start('updateContainer', 'update', id, definition, startCheckSeconds, caller);
  }

  delete(id: string, caller: Caller): Promise<OperationView> {
    return this.start('deleteContainer', 'delete', id, null, START_CHECK_DEFAULT, caller);
  }

  /** REQ-UPG-7: once per start of unraid-api. Never throws: unraid-api must start either way. */
  async startup(): Promise<void> {
    try {
      const result = await runShim(this.shimPath, 'startup', {}, { id: 'unraid-api', name: 'unraid-api', admin: true, providerVersion: null });
      if (typeof result === 'object' && result !== null && 'tested' in result && result.tested === false) {
        this.logger.warn('the webgui files match no tested build, so tofuman refuses every mutation until a plugin release covers this Unraid release');
      }
    } catch (error) {
      this.logger.warn(`the startup check failed: ${error instanceof Error ? error.message : String(error)}`);
    }
  }

  /** Settles when every operation queued so far has ended. The tests wait on it. */
  idle(): Promise<void> {
    return this.queue;
  }

  /**
   * REQ-MUT-2 to REQ-MUT-5: the checks answer before the mutation returns, and the work waits
   * for its turn. nginx ends a request after 60 seconds, and a pull alone can take longer.
   */
  private async start(mutation: Mutation, action: Action, id: string | null, definition: Definition | null, startCheckSeconds: number, caller: Caller): Promise<OperationView> {
    if (!Number.isInteger(startCheckSeconds) || startCheckSeconds < 0 || startCheckSeconds > START_CHECK_MAX) {
      const error = `startCheckSeconds must be a whole number from 0 to ${START_CHECK_MAX}, not ${startCheckSeconds}`;
      await this.shim('recordRefusal', { mutation, id, name: definition?.name ?? '', error }, caller);
      throw new GraphQLError(error, { extensions: { code: 'TOFUMAN_REFUSED', errors: [error], policyGaps: 0 } });
    }
    await this.shim('check', { mutation, id, definition }, caller);
    this.prune();
    const record: OperationRecord = { id: randomUUID(), state: TofumanOperationState.QUEUED, step: null, error: null, container: null, endedAt: null };
    this.operations.set(record.id, record);
    const operation = { id: record.id, queuedAt: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'), startCheck: startCheckSeconds };
    const args = action === 'create' ? { definition, operation } : action === 'update' ? { id, definition, operation } : { id, operation };
    const name = definition?.name ?? id ?? '';
    this.queue = this.queue.then(() => this.run(record, mutation, action, name, args, caller));
    return snapshot(record);
  }

  private async run(record: OperationRecord, mutation: Mutation, action: Action, name: string, args: Record<string, unknown>, caller: Caller): Promise<void> {
    record.state = TofumanOperationState.RUNNING;
    const started = Date.now();
    this.logger.log(`operation ${record.id}: ${mutation} of ${name} for ${caller.name || caller.id} started`);
    try {
      const result = await runShim(this.shimPath, action, args, caller);
      record.container = action === 'delete' ? null : parseContainerView(result);
      record.state = TofumanOperationState.SUCCEEDED;
      this.logger.log(`operation ${record.id}: ${mutation} of ${name} succeeded in ${Date.now() - started} ms`);
    } catch (error) {
      record.state = TofumanOperationState.FAILED;
      record.step = error instanceof ShimError ? error.step : null;
      record.error = error instanceof Error ? error.message : String(error);
      this.logger.warn(`operation ${record.id}: ${mutation} of ${name} failed at the step ${record.step ?? 'unknown'} after ${Date.now() - started} ms`);
    } finally {
      record.endedAt = Date.now();
    }
  }

  private prune(): void {
    const cutoff = Date.now() - KEEP_ENDED_MS;
    for (const [id, record] of this.operations) {
      if (record.endedAt !== null && record.endedAt < cutoff) {
        this.operations.delete(id);
      }
    }
  }

  /** REQ-MUT-3: a refusal names each failed check, and counts its policy gaps (REQ-PRV-18). */
  private async shim(action: string, args: Record<string, unknown>, caller: Caller): Promise<unknown> {
    try {
      return await runShim(this.shimPath, action, args, caller);
    } catch (error) {
      if (error instanceof ShimError) {
        throw new GraphQLError(error.message, {
          extensions: { code: error.refused ? 'TOFUMAN_REFUSED' : 'TOFUMAN_FAILED', errors: error.errors, step: error.step, policyGaps: error.policyGaps },
        });
      }
      throw error;
    }
  }
}

function snapshot(record: OperationRecord): OperationView {
  return { id: record.id, state: record.state, step: record.step, error: record.error, container: record.container };
}
