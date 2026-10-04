import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const workflowPath = new URL('../../workflows/codex-orchestrator.yml', import.meta.url);

test('workflow has safe triggers and scoped write permissions', () => {
  const yml = fs.readFileSync(workflowPath, 'utf8');
  assert.match(yml, /issue_comment:\s*\n\s*types:\s*\[created\]/);
  assert.match(yml, /workflow_dispatch:/);
  assert.doesNotMatch(yml, /pull_request_target/);
  assert.doesNotMatch(yml, /id-token:\s*write/);
  const guardIndex = yml.indexOf('guard:');
  const executeIndex = yml.indexOf('execute:');
  assert.ok(guardIndex >= 0 && executeIndex > guardIndex);
  const guardBlock = yml.slice(guardIndex, executeIndex);
  assert.doesNotMatch(guardBlock, /contents:\s*write/);
  const executeBlock = yml.slice(executeIndex);
  assert.match(executeBlock, /contents:\s*write/);
  assert.match(executeBlock, /issues:\s*write/);
  assert.match(executeBlock, /pull-requests:\s*write/);
});

test('workflow never interpolates raw comment body into shell run steps', () => {
  const yml = fs.readFileSync(workflowPath, 'utf8');
  assert.doesNotMatch(yml, /run:[\s\S]{0,500}github\.event\.comment\.body/);
  assert.doesNotMatch(yml, /run:[\s\S]{0,500}toJson\(github\.event\.comment/);
});
