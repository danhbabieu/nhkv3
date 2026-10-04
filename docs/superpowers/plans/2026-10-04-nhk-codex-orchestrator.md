# NHK Codex Orchestrator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a fail-closed GitHub-based control plane that lets ChatGPT dispatch one scoped NHK task to Codex, receive a schema-valid result plus diff/test evidence, review it independently, and explicitly issue the next task.

**Architecture:** Keep orchestration outside the WordPress runtime. GitHub Issues are the durable task threads, GitHub Actions is the runner, small dependency-free Node 20 utilities parse/validate protocol, and Codex CLI performs repository work on an isolated task branch. A private skills-only ChatGPT plugin teaches the controller workflow but does not own execution infrastructure.

**Tech Stack:** GitHub Actions, Node.js 20 built-ins, JSON Schema-compatible structured output contract, Codex CLI (`codex exec`), existing GitHub connector, existing NHK MCP/runtime tools.

**Spec:** `docs/superpowers/specs/2026-10-04-nhk-codex-orchestrator-design.md`

## Global Constraints

- Canonical repository is `danhbabieu/nhkv3`.
- Codex never writes directly to `main`.
- The workflow must stop after every Codex run; ChatGPT explicitly issues the next `/codex-run`.
- No auto-merge, auto-deploy, production/staging mutation, destructive data repair, schema migration, or Governance bypass.
- Every Codex session must read `AGENTS.md → docs/constitution/READ_FIRST.md → docs/constitution/NHK_V3_CONSTITUTION.md → relevant normative contracts` before NHK architectural/implementation work.
- Authentication material must never be committed, printed to logs, embedded in prompts, stored in artifacts, or returned in result packets.
- Missing auth, malformed commands, unauthorized actors, revision drift, malformed result JSON, or unresolved runtime ambiguity fail closed.
- `PASS` requires fresh independent verification outside Codex's own self-report.
- Existing one-time migrations/materializations are never repeated merely because an orchestration task is retried.
- V1 uses GitHub as the only durable orchestration state store; no new persistent orchestration server/database.

## Review Focus

1. **Prompt/shell injection through issue comments:** multiline task text must be parsed as data and passed through files/stdin, never interpolated into shell command syntax. Task 1 tests metacharacters, backticks, `$(...)`, and YAML-like content round-trip unchanged.
2. **Untrusted trigger actor or unlabelled issue:** workflow must refuse execution before checkout/write-capable steps. Task 2 tests missing label and unauthorized association/allowlist conditions.
3. **Base/task branch moved between instruction and execution:** workflow must return `BLOCKED_BASE_MOVED` before Codex runs or writes. Task 2 tests expected SHA mismatch.
4. **Codex emits invalid/truncated/secret-bearing output:** validator must reject invalid schema and sanitize bounded diagnostics. Task 3 tests missing required fields, unexpected enum values, oversize strings, and secret-like tokens.
5. **Credential leakage through environment/log/result artifacts:** workflow must not echo auth values and result renderer must redact known secret patterns. Task 3 tests redaction and Task 4 verifies workflow env/steps never serialize token values.

---

### Task 1: Protocol parser and result schema

**Files:**
- Create: `.github/codex-orchestrator/result.schema.json`
- Create: `.github/codex-orchestrator/command-parser.mjs`
- Create: `.github/codex-orchestrator/tests/command-parser.test.mjs`
- Create: `.github/codex-orchestrator/tests/result-schema.test.mjs`

**Interfaces:**
- Consumes: raw GitHub issue/comment body text.
- Produces: `parseCodexCommand(body: string) -> { taskId, gateId, expectedBaseSha, mode, instruction, requiredVerification, nonGoals }`.
- Produces: committed result schema with protocol version `1.0` and statuses `PASS|NEEDS_FIX|BLOCKED|HUMAN_GATE|NOOP`.

- [ ] **Step 1: Write failing parser tests**

Add tests asserting:
- a valid `/codex-run` packet parses all required fields;
- multiline `INSTRUCTION`, `REQUIRED_VERIFICATION`, and `NON_GOALS` preserve shell metacharacters literally;
- missing `TASK_ID`, `GATE_ID`, `EXPECTED_BASE_SHA`, `MODE`, or `INSTRUCTION` throws a typed parse error;
- `MODE` accepts only `READ_ONLY` or `CODE_CHANGE`;
- bodies not beginning with `/codex-run` return a non-command result.

- [ ] **Step 2: Run parser tests and verify RED**

Run:
`node --test .github/codex-orchestrator/tests/command-parser.test.mjs`

Expected: FAIL because parser module does not exist.

- [ ] **Step 3: Implement `parseCodexCommand(body)`**

Use deterministic line/header parsing. Treat section bodies as opaque text. Do not execute, normalize shell syntax, or perform environment substitution.

- [ ] **Step 4: Run parser tests and verify GREEN**

Run:
`node --test .github/codex-orchestrator/tests/command-parser.test.mjs`

Expected: PASS.

- [ ] **Step 5: Write schema contract tests**

Assert the committed JSON schema:
- requires every field from the design packet;
- disallows additional top-level properties;
- pins `protocol_version` to `1.0`;
- pins the status enum;
- requires test records to contain `command`, `exit_code`, and `summary`;
- requires both verification booleans.

- [ ] **Step 6: Run schema test and verify RED**

Run:
`node --test .github/codex-orchestrator/tests/result-schema.test.mjs`

Expected: FAIL because schema file is absent/incomplete.

- [ ] **Step 7: Add `result.schema.json`**

Represent the exact design packet. Bound string/list sizes sufficiently to prevent unbounded issue/artifact output while preserving normal diagnostics.

- [ ] **Step 8: Run all Task 1 tests**

Run:
`node --test .github/codex-orchestrator/tests/*.test.mjs`

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add .github/codex-orchestrator
git commit -m "feat: define Codex orchestration protocol"
```

---

### Task 2: Authorization, revision gate, and dry-run control plane

**Files:**
- Create: `.github/codex-orchestrator/guard.mjs`
- Create: `.github/codex-orchestrator/tests/guard.test.mjs`
- Create: `.github/codex-orchestrator/render-result.mjs`
- Create: `.github/codex-orchestrator/tests/render-result.test.mjs`
- Create: `.github/workflows/codex-orchestrator.yml`

**Interfaces:**
- Consumes: parsed command, GitHub event metadata, canonical repo identity, current base/task refs.
- Produces: `evaluateRunGuard(input) -> { allowed: boolean, status: string, reason: string }`.
- Produces: sanitized Markdown issue comment from a result packet.
- Workflow supports `issue_comment` for real commands and `workflow_dispatch` with `dry_run=true` for infrastructure verification.

- [ ] **Step 1: Write failing guard tests**

Cover:
- canonical repository + labelled issue + authorized actor + nonterminal task + matching SHA => allowed;
- missing `codex-task` label => blocked;
- unauthorized actor/repository association => blocked before write-capable steps;
- terminal task => blocked;
- expected base SHA mismatch => `BLOCKED_BASE_MOVED`;
- malformed command => blocked;
- actor-controlled instruction content never affects guard decisions.

- [ ] **Step 2: Run guard tests and verify RED**

Run:
`node --test .github/codex-orchestrator/tests/guard.test.mjs`

Expected: FAIL because guard module does not exist.

- [ ] **Step 3: Implement `evaluateRunGuard(input)`**

Use only structured GitHub metadata for authorization. Do not infer authorization from comment text.

- [ ] **Step 4: Run guard tests and verify GREEN**

Expected: PASS.

- [ ] **Step 5: Write failing renderer tests**

Assert:
- status/task/gate/base/head/test summaries render deterministically;
- large diagnostics are bounded;
- token-like values (`sk-`, bearer tokens, `CODEX_ACCESS_TOKEN=`) are redacted;
- raw environment objects are never rendered.

- [ ] **Step 6: Implement `renderResult(packet)` and verify tests**

Run:
`node --test .github/codex-orchestrator/tests/render-result.test.mjs`

Expected: PASS.

- [ ] **Step 7: Add dry-run workflow**

Workflow requirements:
- trigger on `issue_comment: created` and explicit `workflow_dispatch`;
- start with least permissions and elevate only the execution job that passed guard;
- checkout workflow/control code from the trusted default branch for authorization logic;
- parse event/comment into a temporary file, not shell interpolation;
- in `dry_run`, do not install/invoke Codex and do not push branches;
- emit a synthetic schema-valid `NOOP` packet and upload/comment it;
- timeout each job;
- concurrency key per issue to prevent overlapping task runs.

- [ ] **Step 8: Add workflow contract assertions**

Extend guard/workflow tests to read `.github/workflows/codex-orchestrator.yml` and assert:
- required triggers exist;
- no `pull_request_target`;
- no direct checkout of arbitrary fork code before guard;
- no command body appears inside a `run:` interpolation;
- `contents: write`, `issues: write`, and `pull-requests: write` are scoped to execution job;
- `id-token: write` is absent until OIDC is actually configured.

- [ ] **Step 9: Run control-plane test suite**

Run:
`node --test .github/codex-orchestrator/tests/*.test.mjs`

Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add .github/codex-orchestrator .github/workflows/codex-orchestrator.yml
git commit -m "feat: add fail-closed Codex control plane"
```

---

### Task 3: Codex execution wrapper and structured result validation

**Files:**
- Create: `.github/codex-orchestrator/result-validator.mjs`
- Create: `.github/codex-orchestrator/tests/result-validator.test.mjs`
- Create: `.github/codex-orchestrator/run-codex.mjs`
- Create: `.github/codex-orchestrator/tests/run-codex.test.mjs`
- Modify: `.github/workflows/codex-orchestrator.yml`

**Interfaces:**
- Consumes: trusted parsed command JSON and `result.schema.json`.
- Produces: `validateResultPacket(value) -> validated packet` or typed validation failure.
- Produces: `buildCodexInvocation(input) -> { args: string[], promptFile: string, outputFile: string }`; secrets are supplied only via environment.
- Workflow invokes current Codex CLI non-interactively with structured output.

- [ ] **Step 1: Write failing result-validator tests**

Cover valid packet plus failures for:
- missing required key;
- invalid status;
- extra top-level property;
- wrong protocol version;
- invalid test record;
- oversize diagnostics;
- secret-like values in fields that would be published.

- [ ] **Step 2: Run validator tests and verify RED**

Run:
`node --test .github/codex-orchestrator/tests/result-validator.test.mjs`

Expected: FAIL because validator does not exist.

- [ ] **Step 3: Implement schema validator**

No third-party dependency. Implement only the checks required by the committed v1 schema; keep the schema as the canonical contract and tests in parity.

- [ ] **Step 4: Run validator tests and verify GREEN**

Expected: PASS.

- [ ] **Step 5: Write failing invocation tests**

Assert:
- prompt content is written to a file/stdin boundary, not inserted into a shell command;
- invocation uses `codex exec --json`;
- invocation supplies `--output-schema .github/codex-orchestrator/result.schema.json`;
- output packet path is explicit;
- no token value is part of argv;
- `READ_ONLY` and `CODE_CHANGE` produce distinct Codex instructions;
- the instruction always requires reading NHK Constitution chain first.

- [ ] **Step 6: Implement invocation builder**

Use the current Codex CLI automation contract. The workflow installs a pinned Codex CLI version, with the version stored once as a workflow/env constant for reproducibility.

- [ ] **Step 7: Add auth fail-closed behavior**

For v1, support `CODEX_ACCESS_TOKEN` as the CI credential because current Codex CLI supports non-persistent automation through that environment variable. If unavailable, emit `BLOCKED_AUTH` without invoking Codex. Do not add a long-lived `OPENAI_API_KEY` fallback silently.

- [ ] **Step 8: Add post-Codex independent verification**

After Codex returns:
- validate packet;
- run `git diff --check`;
- collect changed filenames;
- run the task-declared focused verification through an allowlisted execution path, not arbitrary issue-comment shell;
- for code-change tasks run the repository baseline verification policy appropriate to changed scope;
- record exit codes/summaries in the packet;
- downgrade `PASS` to `NEEDS_FIX` if independent checks fail.

- [ ] **Step 9: Run all orchestration unit tests**

Run:
`node --test .github/codex-orchestrator/tests/*.test.mjs`

Expected: PASS.

- [ ] **Step 10: Secret review**

Run:
`git diff --check`

Then inspect changed files for credential literals and verify workflow never echoes `CODEX_ACCESS_TOKEN`.

- [ ] **Step 11: Commit**

```bash
git add .github/codex-orchestrator .github/workflows/codex-orchestrator.yml
git commit -m "feat: execute Codex with structured evidence"
```

---

### Task 4: Task branch, commit, draft PR, and durable issue result

**Files:**
- Create: `.github/codex-orchestrator/git-policy.mjs`
- Create: `.github/codex-orchestrator/tests/git-policy.test.mjs`
- Modify: `.github/workflows/codex-orchestrator.yml`

**Interfaces:**
- Consumes: issue number, task ID, gate ID, approved base SHA, validated result packet.
- Produces: deterministic branch name `codex/task-<issue-number>-<short-slug>`.
- Produces: committed task-scope changes, draft PR, issue result comment/artifact.

- [ ] **Step 1: Write failing branch-policy tests**

Assert:
- deterministic safe branch names;
- same issue reuses same branch;
- branch cannot be `main` or arbitrary user-supplied ref;
- base mismatch blocks before push;
- no-change `NOOP` creates no empty commit/PR;
- changed scope outside task allowances forces `NEEDS_FIX`/block rather than silently committing.

- [ ] **Step 2: Implement git policy and verify GREEN**

Run:
`node --test .github/codex-orchestrator/tests/git-policy.test.mjs`

Expected: PASS.

- [ ] **Step 3: Wire branch/PR lifecycle into workflow**

Use GitHub-provided token only after guard passes. Configure bot commit identity. Push only the deterministic task branch. Create/update a draft PR targeting `main`. Never merge.

- [ ] **Step 4: Persist evidence**

Upload:
- final schema-valid result JSON;
- sanitized Codex JSONL/event log with size bound;
- independent verification summary.

Post a sanitized issue comment containing task status and PR link.

- [ ] **Step 5: Run full Node control-plane suite**

Run:
`node --test .github/codex-orchestrator/tests/*.test.mjs`

Expected: PASS.

- [ ] **Step 6: Repository checks**

Run:
`git diff --check`

For PHP code unchanged, do not claim PHP suite relevance from Node tests. Record the existing repository-wide PHP baseline separately when the eventual Codex task changes PHP.

- [ ] **Step 7: Commit**

```bash
git add .github
git commit -m "feat: persist Codex task branches and review evidence"
```

---

### Task 5: Private `nhk-codex-orchestrator` ChatGPT plugin

**Files:**
- Create in plugin package workspace: `plugin.json`
- Create: `skills/nhk-codex-orchestrator/SKILL.md`
- Create supporting reference: `skills/nhk-codex-orchestrator/references/protocol.md`
- No NHK runtime code changes.

**Interfaces:**
- Consumes: user-approved NHK plan, existing GitHub connector, NHK MCP/runtime tools.
- Produces: controller behavior for creating issues, posting `/codex-run`, reading result/diff/tests, runtime read-back, and deciding CONTINUE/NEEDS_FIX/BLOCKED/HUMAN_GATE/COMPLETE.

- [ ] **Step 1: Build the plugin package from the approved spec/protocol**

Skill rules must include:
- read canonical NHK docs before architectural decisions;
- do not trust Codex self-report;
- inspect result packet + PR diff + CI/log evidence;
- use NHK MCP read-back for runtime-affecting work;
- never auto-merge/deploy;
- stop at existing NHK human gates;
- never repeat one-time materialization/migrations on retry.

- [ ] **Step 2: Validate manifest/package structure locally**

Use the plugin-creator packaging guidance; ensure exactly one private plugin package and no secrets/binaries that are not required.

- [ ] **Step 3: Create the private plugin**

Call Plugin Creator with the verified archive.

Expected: private plugin returns a plugin ID and plugin URL.

- [ ] **Step 4: Inspect the created plugin source**

Read back manifest and skill through Plugin Creator; verify name, version, workflow instructions and no secret material.

- [ ] **Step 5: Record plugin identity in orchestration documentation**

Add only non-secret plugin ID/version/reference to the implementation checkpoint. Do not couple NHK runtime to the plugin.

---

### Task 6: GitHub dry-run acceptance

**Files:**
- Modify only if acceptance exposes a bug: `.github/workflows/codex-orchestrator.yml`, `.github/codex-orchestrator/**`
- Update: `docs/architecture/V3_EXECUTION_STATE.md` with a bounded checkpoint after evidence exists.

**Interfaces:**
- Consumes: merged/available orchestration workflow on default branch.
- Produces: evidence that GitHub can parse, guard, render and persist a synthetic task without invoking Codex.

- [ ] **Step 1: Run `workflow_dispatch` dry-run**

Expected:
- guard passes only for controlled dispatch;
- no Codex installation/auth required;
- result is schema-valid `NOOP`;
- no task branch/PR is created unless explicitly testing code-change plumbing with synthetic files;
- artifact/comment evidence is available.

- [ ] **Step 2: Read workflow job steps/logs independently**

Verify exact steps executed and absence of secret leakage.

- [ ] **Step 3: If dry-run fails, write a failing regression test before changing code**

Follow RED → GREEN for every behavioral fix.

- [ ] **Step 4: Re-run dry-run until evidence passes**

No live Codex call yet.

- [ ] **Step 5: Commit checkpoint documentation**

Record exact workflow run ID, commit SHA, tests and status; do not claim Codex execution.

---

### Task 7: Credential setup and live read-only Codex smoke

**Files:**
- No credential file in repository.
- Modify orchestration code only if smoke exposes a tested defect.
- Update: `docs/architecture/V3_EXECUTION_STATE.md` after verified live evidence.

**Interfaces:**
- Consumes: GitHub repository secret `CODEX_ACCESS_TOKEN`.
- Produces: one read-only Codex result packet with no repository mutation.

- [ ] **Step 1: Configure Codex automation credential**

Preferred current v1 credential is a short-lived `CODEX_ACCESS_TOKEN` stored as a GitHub repository/environment secret. If OpenAI workload identity federation is configured later, migrate to OIDC in a separately reviewed change.

If the connector cannot set GitHub secrets, stop with `HUMAN_GATE_CREDENTIAL_PLACEMENT` and provide the exact one-time UI action; never ask the user to paste the token into chat.

- [ ] **Step 2: Create an orchestrator issue for a read-only repository audit**

Task must:
- use `MODE: READ_ONLY`;
- read Constitution chain;
- report current HEAD, relevant orchestration files and verification commands;
- change no files.

- [ ] **Step 3: Trigger one explicit `/codex-run`**

Expected:
- Codex CLI authenticates;
- result packet validates;
- files_changed is empty;
- no commit/PR is created;
- independent verification passes;
- issue receives result.

- [ ] **Step 4: Independently inspect workflow logs/result**

Do not accept Codex's own PASS without GitHub evidence.

- [ ] **Step 5: Post one continuation instruction on the same issue**

Ask Codex for a second bounded read-only check. Verify same durable issue thread and no uncontrolled recursion.

- [ ] **Step 6: Record acceptance checkpoint**

Only now mark live Codex control loop as accepted.

---

### Task 8: Code-change smoke on orchestration-owned fixture

**Files:**
- Create a disposable orchestration fixture under `.github/codex-orchestrator/fixtures/` only for smoke validation, then remove it in the same task/PR if the test design allows.
- No WordPress/domain code.

**Interfaces:**
- Consumes: live accepted control plane.
- Produces: task branch + tested change + draft PR + result packet proving code-change lifecycle.

- [ ] **Step 1: Create a bounded task asking Codex to change only the fixture and its test**

Require one explicit failing test then minimal fix.

- [ ] **Step 2: Run one `CODE_CHANGE` task**

Expected:
- deterministic task branch;
- non-main commit;
- draft PR;
- valid result packet;
- changed files only within allowed fixture scope.

- [ ] **Step 3: Inspect diff, tests and logs independently**

If scope drift occurs, reject the run and fix orchestrator policy before NHK use.

- [ ] **Step 4: Close/remove smoke fixture through a normal reviewed commit**

Leave no meaningless production artifact.

- [ ] **Step 5: Update execution-state checkpoint**

Mark infrastructure accepted for NHK use, not NHK Dictionary complete.

---

### Task 9: Start NHK Dictionary Gate 0 through the new loop

**Files:**
- No predetermined production files; Gate 0 is read-only.
- Update only execution evidence after review.

**Interfaces:**
- Consumes: approved Dictionary gate plan and accepted orchestrator.
- Produces: one structured audit of source/runtime/build identity for the Dictionary effort.

- [ ] **Step 1: Create the Dictionary Gate 0 issue**

Instruction must compare:
- GitHub `main` HEAD;
- deployed NHK source revision;
- documentation/build identity;
- Migration024/ENTRY_SENSE_MODE readiness.

Non-goals:
- no code change;
- no materialization;
- no migration;
- no semantic mutation;
- no deployment.

- [ ] **Step 2: Run Codex read-only audit**

Require exact evidence and blocker classification.

- [ ] **Step 3: ChatGPT independently cross-checks with GitHub and NHK MCP**

Do not advance based only on Codex output.

- [ ] **Step 4: Decide next Dictionary gate**

Post exactly one next command only after the cross-check:
- proceed if identities align;
- corrective deployment/rebase planning if they do not;
- stop if a human gate is reached.

---

## Final Verification Before Orchestrator Completion

- [ ] Run all Node protocol/control-plane tests:
  `node --test .github/codex-orchestrator/tests/*.test.mjs`
- [ ] Run `git diff --check`.
- [ ] Inspect workflow permissions/triggers manually against the spec.
- [ ] Verify no credential material exists in git history/diff/artifacts/comments created by this branch.
- [ ] Verify one GitHub dry-run.
- [ ] Verify one live read-only Codex run.
- [ ] Verify one second instruction on the same issue.
- [ ] Verify one bounded code-change smoke creates a draft PR without touching `main`.
- [ ] Verify ChatGPT can read result + diff + test/log evidence and make the next decision.
- [ ] Verify staging/production mutation is still impossible without a separate explicit NHK gate.
- [ ] Update `docs/architecture/V3_EXECUTION_STATE.md` with exact evidence and distinguish `CODE_IMPLEMENTED`, `CREDENTIAL_CONFIGURED`, `LIVE_ACCEPTED`, and `NHK_TASK_STARTED`.
