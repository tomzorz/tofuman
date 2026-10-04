// SPDX-License-Identifier: GPL-2.0-or-later
//
// The protocol of the shim (sources/plugin/shim/src/Shim.php): one JSON request on stdin,
// one JSON response on stdout, one PHP process per request.

import { spawn } from 'node:child_process';

import { isRecord, isStringList } from './shapes.js';

/** Who asks. The shim checks the key allowlist and writes the caller into the audit log. */
export interface Caller {
  id: string;
  name: string;
  admin: boolean;
  /** REQ-PRV-14: the header x-tofuman-provider, for the audit log. */
  providerVersion: string | null;
}

/** A refusal (a check failed, nothing changed) or a failure at a step of an operation. */
export class ShimError extends Error {
  constructor(
    readonly refused: boolean,
    readonly step: string | null,
    readonly errors: string[],
    /** REQ-PRV-18: how many of the errors of a refusal are policy gaps. */
    readonly policyGaps = 0,
  ) {
    super(errors.join('; '));
  }
}

interface Output {
  stdout: string;
  stderr: string;
}

function spawnShim(shimPath: string, request: string): Promise<Output> {
  return new Promise((resolve, reject) => {
    const child = spawn('php', [shimPath], { stdio: ['pipe', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    child.stdout.setEncoding('utf8').on('data', (chunk: string) => {
      stdout += chunk;
    });
    child.stderr.setEncoding('utf8').on('data', (chunk: string) => {
      stderr += chunk;
    });
    child.on('error', reject);
    child.on('close', () => resolve({ stdout, stderr }));
    child.stdin.end(request);
  });
}

export async function runShim(shimPath: string, action: string, args: Record<string, unknown>, caller: Caller): Promise<unknown> {
  const { stdout, stderr } = await spawnShim(shimPath, JSON.stringify({ action, args, caller }));
  let response: unknown;
  try {
    response = JSON.parse(stdout);
  } catch {
    throw new ShimError(false, null, [`the shim answered ${action} without JSON: ${(stdout || stderr).trim()}`]);
  }
  if (!isRecord(response) || typeof response['ok'] !== 'boolean') {
    throw new ShimError(false, null, [`the shim answered ${action} with an unknown shape: ${stdout.trim()}`]);
  }
  if (response['ok']) {
    return response['result'];
  }
  const errors = response['errors'];
  const step = response['step'];
  const policyGaps = response['policyGaps'];
  throw new ShimError(
    response['refused'] === true,
    typeof step === 'string' ? step : null,
    isStringList(errors) ? errors : [`${action} failed without an error text`],
    typeof policyGaps === 'number' ? policyGaps : 0,
  );
}
