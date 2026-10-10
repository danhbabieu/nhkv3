const MAX_RENDER_CHARS = 10000;
const MAX_FIELD_CHARS = 2000;

export function redactSecrets(value) {
  return String(value ?? '')
    .replace(/\bsk-[A-Za-z0-9_-]{16,}\b/g, '[REDACTED]')
    .replace(/\bBearer\s+[A-Za-z0-9._~+\/-]{12,}/gi, 'Bearer [REDACTED]')
    .replace(/\b(CODEX_ACCESS_TOKEN|OPENAI_API_KEY)\s*=\s*[^\s]+/gi, '$1=[REDACTED]');
}

export function renderResult(packet, options = {}) {
  const clean = (value) => truncate(redactSecrets(value), MAX_FIELD_CHARS);
  const list = (items) => Array.isArray(items) && items.length
    ? items.slice(0, 50).map((item) => `- ${clean(item)}`).join('\n')
    : '- none';
  const tests = Array.isArray(packet?.tests_run) && packet.tests_run.length
    ? packet.tests_run.slice(0, 50).map((item) => `- \`${clean(item.command)}\` → ${Number(item.exit_code)} — ${clean(item.summary)}`).join('\n')
    : '- none';

  const sections = [
    '## Codex Orchestrator Result',
    `- Status: **${clean(packet?.status)}**`,
    `- Task: \`${clean(packet?.task_id)}\``,
    `- Gate: \`${clean(packet?.gate_id)}\``,
    `- Base: \`${clean(packet?.base_sha)}\``,
    `- Head: \`${clean(packet?.head_sha ?? 'none')}\``,
    options.prUrl ? `- Draft PR: ${clean(options.prUrl)}` : null,
    '',
    '### Root cause',
    clean(packet?.root_cause || 'none'),
    '',
    '### Files changed',
    list(packet?.files_changed),
    '',
    '### Verification',
    tests,
    '',
    `- Scope satisfied: ${Boolean(packet?.verification?.scope_satisfied)}`,
    `- Independent checks passed: ${Boolean(packet?.verification?.independent_checks_passed)}`,
    '',
    '### Blockers',
    list(packet?.blockers),
    '',
    '### Recommended next step',
    clean(packet?.recommended_next_step || 'none'),
  ].filter((line) => line !== null);

  return truncate(sections.join('\n'), MAX_RENDER_CHARS);
}

function truncate(value, max) {
  const text = String(value ?? '');
  if (text.length <= max) return text;
  return `${text.slice(0, Math.max(0, max - 20))}\n…[TRUNCATED]`;
}
