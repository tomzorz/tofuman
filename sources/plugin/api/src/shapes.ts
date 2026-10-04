// SPDX-License-Identifier: GPL-2.0-or-later
//
// The shapes the shim answers with, checked at the boundary so that nothing past it needs a
// cast. The field names are those of spec section 15.

export enum TofumanConfigType {
  PATH = 'PATH',
  PORT = 'PORT',
  VARIABLE = 'VARIABLE',
  LABEL = 'LABEL',
  DEVICE = 'DEVICE',
}

export enum TofumanOperationState {
  QUEUED = 'QUEUED',
  RUNNING = 'RUNNING',
  SUCCEEDED = 'SUCCEEDED',
  FAILED = 'FAILED',
}

export interface ConfigEntry {
  type: TofumanConfigType;
  name: string;
  target: string;
  value: string;
  default: string;
  mode: string;
  description: string;
  display: string;
  required: boolean;
  mask: boolean;
}

export interface Definition {
  name: string;
  repository: string;
  network: string;
  ipAddresses: string[];
  macAddress: string;
  autostart: boolean;
  privileged: boolean;
  cpuset: string;
  shell: string;
  extraParams: string[];
  postArgs: string[];
  webUi: string;
  icon: string;
  overview: string;
  category: string;
  support: string;
  project: string;
  readMe: string;
  templateUrl: string;
  registry: string;
  donateText: string;
  donateLink: string;
  requires: string;
  configEntries: ConfigEntry[];
}

export interface ContainerView {
  id: string;
  definition: Definition;
  running: boolean;
  lastMutationAt: string | null;
  changedSinceLastMutation: boolean;
}

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function isStringList(value: unknown): value is string[] {
  return Array.isArray(value) && value.every((item) => typeof item === 'string');
}

/** The shim answered with something other than spec section 15; a bug on one side, never user input. */
export class ShapeError extends Error {}

function text(record: Record<string, unknown>, key: string, at: string): string {
  const value = record[key];
  if (typeof value !== 'string') {
    throw new ShapeError(`${at}.${key} is not a string`);
  }
  return value;
}

function flag(record: Record<string, unknown>, key: string, at: string): boolean {
  const value = record[key];
  if (typeof value !== 'boolean') {
    throw new ShapeError(`${at}.${key} is not a boolean`);
  }
  return value;
}

function texts(record: Record<string, unknown>, key: string, at: string): string[] {
  const value = record[key];
  if (!isStringList(value)) {
    throw new ShapeError(`${at}.${key} is not a list of strings`);
  }
  return value;
}

function configType(value: string, at: string): TofumanConfigType {
  const type = Object.values(TofumanConfigType).find((candidate) => candidate === value);
  if (type === undefined) {
    throw new ShapeError(`${at}.type '${value}' is not a config entry type`);
  }
  return type;
}

function parseEntry(value: unknown, at: string): ConfigEntry {
  if (!isRecord(value)) {
    throw new ShapeError(`${at} is not an object`);
  }
  return {
    type: configType(text(value, 'type', at), at),
    name: text(value, 'name', at),
    target: text(value, 'target', at),
    value: text(value, 'value', at),
    default: text(value, 'default', at),
    mode: text(value, 'mode', at),
    description: text(value, 'description', at),
    display: text(value, 'display', at),
    required: flag(value, 'required', at),
    mask: flag(value, 'mask', at),
  };
}

export function parseDefinition(value: unknown, at = 'definition'): Definition {
  if (!isRecord(value)) {
    throw new ShapeError(`${at} is not an object`);
  }
  const entries = value['configEntries'];
  if (!Array.isArray(entries)) {
    throw new ShapeError(`${at}.configEntries is not a list`);
  }
  const list: unknown[] = entries;
  return {
    name: text(value, 'name', at),
    repository: text(value, 'repository', at),
    network: text(value, 'network', at),
    ipAddresses: texts(value, 'ipAddresses', at),
    macAddress: text(value, 'macAddress', at),
    autostart: flag(value, 'autostart', at),
    privileged: flag(value, 'privileged', at),
    cpuset: text(value, 'cpuset', at),
    shell: text(value, 'shell', at),
    extraParams: texts(value, 'extraParams', at),
    postArgs: texts(value, 'postArgs', at),
    webUi: text(value, 'webUi', at),
    icon: text(value, 'icon', at),
    overview: text(value, 'overview', at),
    category: text(value, 'category', at),
    support: text(value, 'support', at),
    project: text(value, 'project', at),
    readMe: text(value, 'readMe', at),
    templateUrl: text(value, 'templateUrl', at),
    registry: text(value, 'registry', at),
    donateText: text(value, 'donateText', at),
    donateLink: text(value, 'donateLink', at),
    requires: text(value, 'requires', at),
    configEntries: list.map((entry, index) => parseEntry(entry, `${at}.configEntries[${index}]`)),
  };
}

export function parseContainerView(value: unknown, at = 'container'): ContainerView {
  if (!isRecord(value)) {
    throw new ShapeError(`${at} is not an object`);
  }
  const lastMutationAt = value['lastMutationAt'];
  if (lastMutationAt !== null && typeof lastMutationAt !== 'string') {
    throw new ShapeError(`${at}.lastMutationAt is neither a string nor null`);
  }
  return {
    id: text(value, 'id', at),
    definition: parseDefinition(value['definition'], `${at}.definition`),
    running: flag(value, 'running', at),
    lastMutationAt,
    changedSinceLastMutation: flag(value, 'changedSinceLastMutation', at),
  };
}

/** The answer of the query `check` (REQ-MUT-25). */
export interface CheckView {
  failedChecks: string[];
  policyGaps: number;
}

export function parseCheck(value: unknown): CheckView {
  if (!isRecord(value)) {
    throw new ShapeError('check is not an object');
  }
  const policyGaps = value['policyGaps'];
  if (typeof policyGaps !== 'number' || !Number.isInteger(policyGaps)) {
    throw new ShapeError('check.policyGaps is not an integer');
  }
  return { failedChecks: texts(value, 'failedChecks', 'check'), policyGaps };
}

export function parseContainerViews(value: unknown): ContainerView[] {
  if (!Array.isArray(value)) {
    throw new ShapeError('the list of containers is not a list');
  }
  const list: unknown[] = value;
  return list.map((view, index) => parseContainerView(view, `containers[${index}]`));
}
