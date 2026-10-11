import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const schemaPath = new URL('../result.schema.json', import.meta.url);
const requiredTop = [
  'protocol_version','task_id','gate_id','status','base_sha','head_sha','root_cause',
  'actions_taken','files_changed','tests_run','verification','mutations','blockers','recommended_next_step'
];

test('result schema pins protocol and top-level contract', () => {
  const schema = JSON.parse(fs.readFileSync(schemaPath, 'utf8'));
  assert.equal(schema.type, 'object');
  assert.equal(schema.additionalProperties, false);
  assert.deepEqual([...schema.required].sort(), [...requiredTop].sort());
  assert.equal(schema.properties.protocol_version.const, '1.0');
  assert.deepEqual(schema.properties.status.enum, ['PASS','NEEDS_FIX','BLOCKED','HUMAN_GATE','NOOP']);
});

test('result schema requires test record and verification fields', () => {
  const schema = JSON.parse(fs.readFileSync(schemaPath, 'utf8'));
  const testItem = schema.properties.tests_run.items;
  assert.deepEqual([...testItem.required].sort(), ['command','exit_code','summary'].sort());
  assert.equal(testItem.additionalProperties, false);
  assert.deepEqual([...schema.properties.verification.required].sort(), ['scope_satisfied','independent_checks_passed'].sort());
});
