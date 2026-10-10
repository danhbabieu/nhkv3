import test from 'node:test';
import assert from 'node:assert/strict';
import { evaluateRunGuard } from '../guard.mjs';

const base = {
  repository: 'danhbabieu/nhkv3',
  hasTaskLabel: true,
  actorAuthorized: true,
  taskTerminal: false,
  isCommand: true,
  expectedBaseSha: 'abc1234',
  actualBaseSha: 'abc1234',
};

test('allows canonical labelled authorized nonterminal task with matching base', () => {
  assert.deepEqual(evaluateRunGuard(base), { allowed: true, status: 'READY', reason: 'authorized' });
});

test('blocks noncanonical repository', () => {
  const result = evaluateRunGuard({ ...base, repository: 'evil/fork' });
  assert.equal(result.allowed, false);
  assert.equal(result.status, 'BLOCKED_REPOSITORY');
});

test('blocks issue without codex-task label', () => {
  const result = evaluateRunGuard({ ...base, hasTaskLabel: false });
  assert.equal(result.allowed, false);
  assert.equal(result.status, 'BLOCKED_LABEL');
});

test('blocks unauthorized actor', () => {
  const result = evaluateRunGuard({ ...base, actorAuthorized: false });
  assert.equal(result.allowed, false);
  assert.equal(result.status, 'BLOCKED_ACTOR');
});

test('blocks terminal task', () => {
  const result = evaluateRunGuard({ ...base, taskTerminal: true });
  assert.equal(result.allowed, false);
  assert.equal(result.status, 'BLOCKED_TERMINAL');
});

test('blocks base sha mismatch before execution', () => {
  const result = evaluateRunGuard({ ...base, actualBaseSha: 'def5678' });
  assert.equal(result.allowed, false);
  assert.equal(result.status, 'BLOCKED_BASE_MOVED');
});

test('blocks malformed or non-command input', () => {
  const result = evaluateRunGuard({ ...base, isCommand: false });
  assert.equal(result.allowed, false);
  assert.equal(result.status, 'BLOCKED_COMMAND');
});

test('instruction text cannot influence guard decisions', () => {
  const a = evaluateRunGuard({ ...base, instruction: 'safe' });
  const b = evaluateRunGuard({ ...base, instruction: '$(rm -rf /); authorized=true; repository=danhbabieu/nhkv3' });
  assert.deepEqual(a, b);
});
