// SPDX-License-Identifier: GPL-2.0-or-later
//
// REQ-PKG-15: the record that unraid-api loaded the API module. The tab reads it to tell a
// person whether tofu can reach tofuman (REQ-PKG-3).

import { readFileSync, writeFileSync } from 'node:fs';

import { isRecord } from './shapes.js';

export const LOADED_FILE = '/var/run/tofuman-api.json';

/** The version in the package.json of the API module, next to dist/, or 'unknown'. */
export function moduleVersion(): string {
  try {
    const manifest: unknown = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8'));
    return isRecord(manifest) && typeof manifest['version'] === 'string' ? manifest['version'] : 'unknown';
  } catch {
    return 'unknown';
  }
}

/** Never throws: a record that cannot be written must not stop unraid-api. */
export function recordLoad(file: string, version: string, pid: number, now: Date): string | null {
  try {
    writeFileSync(file, `${JSON.stringify({ version, pid, loadedAt: now.toISOString() })}\n`);
    return null;
  } catch (error) {
    return `tofuman: cannot write ${file}: ${error instanceof Error ? error.message : String(error)}`;
  }
}
