export class CodexCommandParseError extends Error {
  constructor(message) {
    super(message);
    this.name = 'CodexCommandParseError';
  }
}

const SCALAR_HEADERS = ['TASK_ID', 'GATE_ID', 'EXPECTED_BASE_SHA', 'MODE'];
const SECTION_HEADERS = ['INSTRUCTION', 'REQUIRED_VERIFICATION', 'NON_GOALS'];
const ALL_HEADERS = new Set([...SCALAR_HEADERS, ...SECTION_HEADERS]);

export function parseCodexCommand(body) {
  if (typeof body !== 'string') {
    throw new CodexCommandParseError('Command body must be a string');
  }

  const normalized = body.replace(/\r\n/g, '\n');
  const lines = normalized.split('\n');
  if (lines[0]?.trim() !== '/codex-run') {
    return { isCommand: false };
  }

  const scalars = {};
  const sections = {};
  let activeSection = null;

  for (let i = 1; i < lines.length; i += 1) {
    const line = lines[i];
    const headerMatch = /^([A-Z_]+):(.*)$/.exec(line);

    if (headerMatch && ALL_HEADERS.has(headerMatch[1])) {
      const [, key, rest] = headerMatch;
      if (SCALAR_HEADERS.includes(key)) {
        if (activeSection !== null) {
          throw new CodexCommandParseError(`Scalar header ${key} cannot appear after section content starts`);
        }
        scalars[key] = rest.trim();
      } else {
        activeSection = key;
        sections[key] = [];
        if (rest.length > 0) {
          sections[key].push(rest.replace(/^\s/, ''));
        }
      }
      continue;
    }

    if (activeSection !== null) {
      sections[activeSection].push(line);
      continue;
    }

    if (line.trim() !== '') {
      throw new CodexCommandParseError(`Unexpected content before sections at line ${i + 1}`);
    }
  }

  for (const key of SCALAR_HEADERS) {
    if (!scalars[key]) {
      throw new CodexCommandParseError(`Missing required field ${key}`);
    }
  }

  if (!['READ_ONLY', 'CODE_CHANGE'].includes(scalars.MODE)) {
    throw new CodexCommandParseError('MODE must be READ_ONLY or CODE_CHANGE');
  }

  const instruction = joinRequiredSection(sections, 'INSTRUCTION');
  const requiredVerification = joinOptionalSection(sections, 'REQUIRED_VERIFICATION');
  const nonGoals = joinOptionalSection(sections, 'NON_GOALS');

  return {
    isCommand: true,
    taskId: scalars.TASK_ID,
    gateId: scalars.GATE_ID,
    expectedBaseSha: scalars.EXPECTED_BASE_SHA,
    mode: scalars.MODE,
    instruction,
    requiredVerification,
    nonGoals,
  };
}

function joinRequiredSection(sections, key) {
  const value = joinOptionalSection(sections, key);
  if (!value) {
    throw new CodexCommandParseError(`Missing required field ${key}`);
  }
  return value;
}

function joinOptionalSection(sections, key) {
  if (!Object.hasOwn(sections, key)) return '';
  return sections[key].join('\n').replace(/\n+$/g, '');
}
