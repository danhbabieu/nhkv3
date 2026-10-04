import test from 'node:test';
import assert from 'node:assert/strict';
import { parseCodexCommand, CodexCommandParseError } from '../command-parser.mjs';

const valid = `/codex-run
TASK_ID: dictionary-gate-0
GATE_ID: gate-0
EXPECTED_BASE_SHA: 50378f690d9ee0c4e75202bb890257c3654a75b7
MODE: READ_ONLY
INSTRUCTION:
Read AGENTS.md first.
echo $(whoami) \`uname\`
key: value
REQUIRED_VERIFICATION:
Check HEAD.
Run git diff --check.
NON_GOALS:
Do not mutate data.
Do not deploy.`;

test('parses a valid codex command and preserves multiline bodies literally', () => {
  const result = parseCodexCommand(valid);
  assert.equal(result.isCommand, true);
  assert.equal(result.taskId, 'dictionary-gate-0');
  assert.equal(result.gateId, 'gate-0');
  assert.equal(result.expectedBaseSha, '50378f690d9ee0c4e75202bb890257c3654a75b7');
  assert.equal(result.mode, 'READ_ONLY');
  assert.equal(result.instruction, 'Read AGENTS.md first.\necho $(whoami) `uname`\nkey: value');
  assert.equal(result.requiredVerification, 'Check HEAD.\nRun git diff --check.');
  assert.equal(result.nonGoals, 'Do not mutate data.\nDo not deploy.');
});

test('returns non-command when body does not start with /codex-run', () => {
  assert.deepEqual(parseCodexCommand('hello\n/codex-run'), { isCommand: false });
});

for (const field of ['TASK_ID', 'GATE_ID', 'EXPECTED_BASE_SHA', 'MODE', 'INSTRUCTION']) {
  test(`rejects missing ${field}`, () => {
    let body = valid;
    if (field === 'INSTRUCTION') {
      body = body.replace(/INSTRUCTION:\n[\s\S]*?\nREQUIRED_VERIFICATION:/, 'REQUIRED_VERIFICATION:');
    } else {
      body = body.replace(new RegExp(`^${field}:.*\\n`, 'm'), '');
    }
    assert.throws(() => parseCodexCommand(body), CodexCommandParseError);
  });
}

test('accepts only READ_ONLY or CODE_CHANGE modes', () => {
  const body = valid.replace('MODE: READ_ONLY', 'MODE: DESTROY_EVERYTHING');
  assert.throws(() => parseCodexCommand(body), CodexCommandParseError);
});
