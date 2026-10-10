export const CANONICAL_REPOSITORY = 'danhbabieu/nhkv3';

export function evaluateRunGuard(input) {
  if (input.repository !== CANONICAL_REPOSITORY) {
    return blocked('BLOCKED_REPOSITORY', 'repository is not the canonical NHK repository');
  }
  if (!input.isCommand) {
    return blocked('BLOCKED_COMMAND', 'comment is not a valid /codex-run command');
  }
  if (!input.hasTaskLabel) {
    return blocked('BLOCKED_LABEL', 'issue is missing codex-task label');
  }
  if (!input.actorAuthorized) {
    return blocked('BLOCKED_ACTOR', 'comment author is not authorized');
  }
  if (input.taskTerminal) {
    return blocked('BLOCKED_TERMINAL', 'task is already terminal');
  }
  if (!input.expectedBaseSha || input.expectedBaseSha !== input.actualBaseSha) {
    return blocked('BLOCKED_BASE_MOVED', 'expected base SHA does not match current base SHA');
  }
  return { allowed: true, status: 'READY', reason: 'authorized' };
}

function blocked(status, reason) {
  return { allowed: false, status, reason };
}
