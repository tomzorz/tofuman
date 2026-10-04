// SPDX-License-Identifier: GPL-2.0-or-later

import { GraphQLError } from 'graphql';

import { isRecord } from './shapes.js';
import type { Caller } from './shim.js';

/** REQ-PRV-14: the provider names its version in this header. */
export const PROVIDER_HEADER = 'x-tofuman-provider';

/**
 * The principal that unraid-api's guards put on the request: an API key, the signed-in webgui
 * session, or the local CLI session. The last two always carry the ADMIN role.
 */
export function callerFrom(context: unknown): Caller {
  const request = isRecord(context) ? context['req'] : undefined;
  const user = isRecord(request) ? request['user'] : undefined;
  if (!isRecord(user) || typeof user['id'] !== 'string') {
    throw new GraphQLError('tofuman: the request carries no authenticated caller');
  }
  const roles = user['roles'];
  const name = user['name'];
  const headers = isRecord(request) ? request['headers'] : undefined;
  const version = isRecord(headers) ? headers[PROVIDER_HEADER] : undefined;
  return {
    id: user['id'],
    name: typeof name === 'string' ? name : '',
    admin: Array.isArray(roles) && roles.includes('ADMIN'),
    // a version is short; anything longer is not one, and stays out of the audit log
    providerVersion: typeof version === 'string' && /^[\w.+-]{1,40}$/.test(version) ? version : null,
  };
}
