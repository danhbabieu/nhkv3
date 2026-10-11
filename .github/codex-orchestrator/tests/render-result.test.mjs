import test from 'node:test';
import assert from 'node:assert/strict';
import { renderResult, redactSecrets } from '../render-result.mjs';

const packet = {
  protocol_version: '1.0', task_id: 't1', gate_id: 'g1', status: 'PASS',
  base_sha: 'abc1234', head_sha: 'def5678', root_cause: 'none',
  actions_taken: ['checked'], files_changed: ['a.txt'],
  tests_run: [{ command: 'node --test', exit_code: 0, summary: '1/1 pass' }],
  verification: { scope_satisfied: true, independent_checks_passed: true },
  mutations: [], blockers: [], recommended_next_step: 'continue'
};

test('renders deterministic bounded result markdown', () => {
  const out = renderResult(packet, { prUrl: 'https://github.com/danhbabieu/nhkv3/pull/99' });
  assert.match(out, /Codex Orchestrator Result/);
  assert.match(out, /PASS/);
  assert.match(out, /t1/);
  assert.match(out, /g1/);
  assert.match(out, /node --test/);
  assert.match(out, /pull\/99/);
  assert.ok(out.length < 12000);
});

test('redacts secret-like values', () => {
  const input = 'sk-abcdefghijklmnopqrstuvwxyz123456 Bearer abcdefghijklmnopqrstuvwxyz CODEX_ACCESS_TOKEN=topsecret';
  const out = redactSecrets(input);
  assert.doesNotMatch(out, /topsecret/);
  assert.doesNotMatch(out, /sk-abcdefghijklmnopqrstuvwxyz123456/);
  assert.doesNotMatch(out, /Bearer abcdefghijklmnopqrstuvwxyz/);
  assert.match(out, /\[REDACTED\]/);
});

test('bounds large diagnostics', () => {
  const out = renderResult({ ...packet, root_cause: 'x'.repeat(50000) });
  assert.ok(out.length < 12000);
});

test('does not render raw environment objects', () => {
  const out = renderResult({ ...packet, environment: { CODEX_ACCESS_TOKEN: 'secret' } });
  assert.doesNotMatch(out, /CODEX_ACCESS_TOKEN/);
  assert.doesNotMatch(out, /secret/);
});
