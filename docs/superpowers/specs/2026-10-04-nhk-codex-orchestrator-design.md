# NHK Codex Orchestrator Design

Date: 2026-10-04
Status: APPROVED_FOR_IMPLEMENTATION

## 1. Goal

Create a reusable, auditable orchestration loop in which ChatGPT acts as the controller for NHK engineering work:

1. ChatGPT issues one bounded implementation/audit instruction.
2. Codex works on the repository, runs tests, and emits a structured result packet.
3. ChatGPT independently reads the result, Git diff, test evidence, and runtime evidence.
4. ChatGPT decides whether to continue, correct, stop, or advance to the next gate.
5. The loop repeats until the approved plan reaches its terminal acceptance state.

The system must avoid blind recursive automation. Codex performs scoped work; ChatGPT remains the decision-maker between turns.

## 2. Existing context and constraints

- Canonical repository: `danhbabieu/nhkv3`.
- Current GitHub `main` at design time: `50378f690d9ee0c4e75202bb890257c3654a75b7`.
- That commit is directly descended from deployed/source revision `7e4460897de621b26ad22cdd91ba75fd603d6207`, resolving the previous repository-identity mismatch.
- NHK already has extensive architecture contracts and execution-state documentation. The orchestrator must consume those documents; it must not create a parallel architecture truth.
- Production/staging mutation, deployment, destructive data repair, schema migration, or irreversible governance action remains human-gated unless an existing NHK contract explicitly permits it.
- Dictionary materialization and other one-time migrations must never be repeated merely because a task is retried.

## 3. Recommended architecture

Use GitHub as the durable task bus and evidence store. Do not add a new always-on orchestration server for v1.

```text
ChatGPT controller
    |
    | GitHub connector
    v
GitHub Issue / task thread
    |
    | issues / issue_comment event
    v
GitHub Actions runner
    |
    | Codex CLI
    v
Repository worktree
    |
    +--> tests / lint / diff
    +--> structured result JSON
    +--> task branch / draft PR
    |
    v
GitHub Issue comment + artifact + PR
    |
    v
ChatGPT reads evidence
    |
    +--> continue task
    +--> corrective task
    +--> advance gate
    +--> stop / human gate
```

This architecture uses components already available to the project:

- GitHub connector for issue, comment, branch, PR, diff, commit, CI and log inspection.
- GitHub Actions for ephemeral execution.
- Codex CLI for repository-aware coding and testing.
- A small private, skills-only ChatGPT plugin for the controller workflow and packet rules.

No custom remote MCP server is required for the first version.

## 4. Why GitHub is the control plane

GitHub provides the durable state that ChatGPT and Codex both need:

- task specification,
- base revision,
- conversation/history,
- branch state,
- code diff,
- test evidence,
- workflow logs,
- result packet,
- review record,
- final PR.

This avoids maintaining a second database or queue.

Each orchestrated task maps to one GitHub issue. The issue remains the durable control thread even when Codex is restarted or a runner disappears.

## 5. Task lifecycle

### 5.1 States

The orchestration state machine is:

```text
PLANNED
  -> READY
  -> RUNNING
  -> NEEDS_REVIEW
       -> CONTINUE
       -> NEEDS_FIX
       -> BLOCKED
       -> HUMAN_GATE
       -> COMPLETE
```

The GitHub issue is authoritative for orchestration state; Codex itself is not.

### 5.2 Initial task

ChatGPT creates an issue containing:

- task ID,
- gate ID,
- expected base SHA,
- exact scope,
- files/areas allowed to change where known,
- required tests,
- explicit non-goals,
- mutation policy,
- expected result schema.

The issue receives the orchestration label, initially `codex-task`.

### 5.3 Continuation

ChatGPT reads the latest result and posts exactly one next instruction.

The workflow must only run on an explicit command comment in an already authorized orchestrator issue. It must not recursively trigger itself from its own result comment.

Preferred continuation behavior:

- reuse the same task branch;
- reuse Codex session/thread state when supported and safely persisted;
- otherwise reconstruct context from repository state plus the bounded issue transcript and latest result packet.

Session continuity is an optimization, not a correctness dependency.

## 6. Codex execution boundary

Codex runs inside a GitHub Actions checkout of the task branch.

The runner:

1. verifies issue authorization and task state;
2. verifies expected base SHA;
3. checks out the existing task branch or creates it from the approved base;
4. prepares the structured output schema;
5. invokes Codex in non-interactive automation mode;
6. captures the Codex final result separately from progress logs;
7. runs required independent verification commands after Codex returns;
8. records diff and test evidence;
9. commits only task-scope changes to the task branch;
10. creates or updates a draft PR;
11. posts the structured result packet to the issue.

Codex may edit code, tests, and documentation inside the repository. It may not deploy NHK, mutate production/staging data, manage secrets, or bypass governance unless the individual task explicitly enters a human-approved mutation gate.

## 7. Authentication

### 7.1 Preferred

Use short-lived workload identity / OIDC where available for unattended GitHub Actions.

### 7.2 Fallback

Use a repository or environment secret containing a restricted OpenAI credential.

The credential must never be:

- written into the repository,
- printed to logs,
- included in Codex prompts,
- copied into artifacts,
- returned in result packets.

The workflow should fail closed with `BLOCKED_AUTH` when authentication is unavailable.

## 8. GitHub permissions and trigger safety

The workflow must use the least GitHub permissions needed.

Expected permissions:

- `contents: write` only for the isolated task branch,
- `issues: write` for result comments,
- `pull-requests: write` for draft PR creation/update,
- `id-token: write` only if workload identity is enabled.

Execution is allowed only when all of these are true:

- repository is the canonical NHK repository;
- issue has the orchestration label;
- command author is an authorized repository actor;
- command uses the required orchestration prefix;
- task is not terminal;
- expected base/task revision checks pass.

Unknown or malformed commands fail closed.

## 9. Branch and PR policy

Never let Codex write directly to `main`.

Naming:

```text
codex/task-<issue-number>-<short-slug>
```

Every implementation task gets a draft PR.

A task may produce multiple commits on the same branch as ChatGPT sends corrective or continuation instructions.

Merging remains a separate decision. Deployment remains separate from merging.

## 10. Structured result packet

Every Codex run must end with machine-readable JSON matching a committed schema.

Minimum packet:

```json
{
  "protocol_version": "1.0",
  "task_id": "string",
  "gate_id": "string",
  "status": "PASS|NEEDS_FIX|BLOCKED|HUMAN_GATE|NOOP",
  "base_sha": "string",
  "head_sha": "string|null",
  "root_cause": "string",
  "actions_taken": ["string"],
  "files_changed": ["string"],
  "tests_run": [
    {
      "command": "string",
      "exit_code": 0,
      "summary": "string"
    }
  ],
  "verification": {
    "scope_satisfied": true,
    "independent_checks_passed": true
  },
  "mutations": ["string"],
  "blockers": ["string"],
  "recommended_next_step": "string"
}
```

Rules:

- `PASS` is invalid unless fresh independent verification ran.
- `NOOP` means the requested state was already present and verified.
- `BLOCKED` must name the exact blocker.
- unrelated baseline failures must be named, not hidden.
- no secrets or full private payloads may appear.

## 11. Independent verification

The controller must not trust the Codex status alone.

The GitHub workflow independently records:

- `git diff --check`,
- changed file list,
- requested focused tests,
- project-level test/lint command when feasible,
- current HEAD,
- working tree cleanliness after commit.

ChatGPT then inspects:

- result packet,
- PR diff,
- relevant commits,
- CI/workflow logs,
- NHK MCP/runtime read-back where the task affects runtime behavior.

Only then may ChatGPT advance the gate.

## 12. Private ChatGPT plugin

Create a private skills-only plugin named `nhk-codex-orchestrator`.

It does not own execution infrastructure.

Its skill defines:

- how to create an orchestrator issue,
- how to format a Codex task,
- how to interpret the result packet,
- how to inspect GitHub evidence,
- how to use NHK MCP read-back,
- how to advance/repair/stop gates,
- which operations require a human gate.

The plugin should prefer the existing GitHub connector. If the required GitHub actions are unavailable, it must report `MISSING_GITHUB_CONTROL_PLANE` rather than invent a fallback.

The skill must treat repository and runtime documentation as canonical project evidence.

## 13. Command protocol

Use explicit command comments so result comments cannot recursively retrigger work.

Initial/continuation command form:

```text
/codex-run
TASK_ID: ...
GATE_ID: ...
EXPECTED_BASE_SHA: ...
MODE: READ_ONLY|CODE_CHANGE
INSTRUCTION:
...
REQUIRED_VERIFICATION:
...
NON_GOALS:
...
```

Only comments beginning with `/codex-run` trigger execution.

Future protocol versions may add `/codex-cancel` and `/codex-status`, but they are not required for v1.

## 14. Failure handling

### Authentication

Return `BLOCKED_AUTH`; do not retry indefinitely.

### Base SHA mismatch

Return `BLOCKED_BASE_MOVED`. ChatGPT must inspect the new main history and decide whether to rebase/replan.

### Codex process failure

Return `BLOCKED_CODEX_EXECUTION` with sanitized stderr tail and workflow link.

### Test failure

Return `NEEDS_FIX` with failing command and concise failure summary.

### Unrelated baseline failure

Report it separately from task-caused failure. Do not silently repair unrelated code unless ChatGPT issues a new scope.

### Merge conflict

Return `HUMAN_GATE` or a dedicated rebase task; never force push main.

## 15. No infinite autonomous loop

The v1 workflow intentionally stops after every Codex run.

It does not automatically feed `recommended_next_step` back into Codex.

ChatGPT must inspect the result and explicitly issue the next `/codex-run`.

This implements the desired loop:

```text
Codex works -> reports -> ChatGPT evaluates -> ChatGPT instructs next step
```

while preserving an independent reasoning/control boundary.

## 16. Test strategy

Implementation follows test-first behavior where executable logic is added.

Required coverage:

1. command parser accepts one valid `/codex-run` packet;
2. malformed packets fail closed;
3. unauthorized comments do not execute Codex;
4. unlabelled issues do not execute Codex;
5. terminal tasks do not rerun;
6. base SHA mismatch blocks execution;
7. result schema validation rejects malformed Codex output;
8. workflow dry-run proves branch/result handling without invoking Codex;
9. one live read-only Codex smoke task proves authentication and issue/result plumbing;
10. one code-change smoke task proves branch + diff + test + draft PR flow.

The first NHK production-use task after infrastructure acceptance will be Dictionary Gate 0, read-only.

## 17. Rollout slices

### Slice A — control-plane foundation

- protocol schema,
- command parser,
- GitHub workflow dry-run,
- task branch policy,
- issue result rendering.

No Codex call yet.

### Slice B — Codex execution

- authentication,
- Codex CLI installation,
- structured output,
- independent verification,
- sanitized failure handling.

### Slice C — private plugin

- package the orchestration skill,
- install privately,
- verify the skill uses GitHub and NHK MCP correctly.

### Slice D — live smoke

- run one read-only repository audit,
- ChatGPT reads the result and issues one continuation,
- verify second run uses the same task issue and branch.

### Slice E — NHK Dictionary

Begin the previously approved Dictionary gate plan with Gate 0. Do not skip gates already proven by read-back; do not repeat materialization or migrations.

## 18. Acceptance criteria

The orchestrator is complete only when all of the following are demonstrated:

- ChatGPT can create a task through GitHub.
- GitHub Actions runs Codex without exposing credentials.
- Codex can inspect/change the task branch and run tests.
- A schema-valid result packet is posted back.
- A draft PR exposes the exact diff when code changes.
- ChatGPT can independently read the result, diff and tests.
- ChatGPT can issue a second instruction based on the first result.
- The second run executes on the same durable task thread.
- main is never directly written by Codex.
- staging/production mutation is not possible without a separate explicit gate.
- one NHK read-only Gate 0 task completes end-to-end.

## 19. Explicit non-goals

V1 does not:

- create a generic autonomous software factory;
- continuously loop without ChatGPT review;
- auto-merge PRs;
- auto-deploy NHK;
- mutate production/staging data;
- replace NHK governance;
- replace NHK canonical architecture documentation;
- require a new persistent server or database;
- make Codex the architectural authority.

## 20. Follow-up implementation plan

After this design is approved, create a detailed implementation plan before product code. The plan should implement the slices in order, with the dry-run control plane first and the live Codex call only after trigger/security/result-schema tests are green.
