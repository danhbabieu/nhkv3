# DEMO Cutover Infrastructure Design

**Status:** Approved for implementation by the user on 2026-09-03.

## Goal

Provide one human-controlled command for a safe, repeatable DEMO deployment and
semantic cutover workflow:

```text
local safety → deploy → demo verification → authenticated runtime preflight
→ Graph read → editorial snapshot → live inventory → proposal planning
→ submit → human approval → eligibility → Controlled Apply → read-back
→ evidence update
```

The implementation prepares this capability only. It does not execute a real
DEMO deployment or mutate demo, staging, production, V2, WordPress or semantic
data during this task.

## Boundaries and invariants

- The only supported deployment target in this design is the explicit
  allowlist entry `demo.1945.vn`; arbitrary hosts fail closed.
- `--pack` identifies a generic semantic-pack manifest. The runner does not
  contain Odo business rules, stable keys, entity lists, relation intent or
  content. Pack-specific behavior is supplied by a validated manifest/adapter.
- `nhk-core` deployment is deterministic and validates the exact source
  revision, package/autoload state and expected plugin artifact before a
  remote action is considered successful.
- Local safety is read-only: clean/known worktree state, required files,
  dependency lock, no secret-like files in the deployment set, and an explicit
  target/pack allowlist are checked before deployment.
- Runtime verification is authenticated and fail-closed. Health alone is not
  sufficient; the runtime must prove the expected application version,
  capability manifest, protocol, and required capabilities.
- The cutover never uses direct SQL, local semantic database access, V2 data,
  production data, hard delete, auto-approval or a second business-logic path.
- Graph, WordPress editorial snapshots, live inventory, proposal planning,
  Governance submit/approval/eligibility/apply, read-back and evidence update
  are injected ports. The orchestration layer coordinates them and records
  structured, redacted evidence; it does not implement their domain rules.
- Proposal planning binds every semantic mutation to live UUIDs, stable keys,
  revisions, registry-valid operation data and an idempotency key. A stale or
  incomplete read blocks the workflow.
- The approval gate is explicit, interactive and fail-closed. A non-interactive
  invocation cannot apply. Approval is never inferred from flags, plan state or
  a previous run.
- Controlled Apply is invoked only after a fresh eligibility result and the
  human approval token for the exact proposal fingerprint. Read-back must
  match the applied receipt; partial/uncertain results remain incomplete.
- Logs and evidence contain no credentials, tokens, private keys, request
  headers, bodies or secret values. Evidence is append-only and safe to retain.

## Components

### Thin shell entrypoint

`./scripts/nhk-demo-cutover` parses only supported command-line options and
executes the PHP runner with the repository root. It contains no business logic
and preserves exit codes. The default command is:

```text
./scripts/nhk-demo-cutover --target=demo.1945.vn --pack=odo
```

The command is human-controlled: it may prepare and verify automatically, then
prints the exact proposal summary and pauses for explicit approval. A future
operator supplies deployment credentials through the environment/credential
store; no credential is accepted as a CLI value or written to evidence.

### PHP orchestration

`DemoCutoverRunner` owns stage ordering and fail-closed transitions. Each stage
is a small port with a typed result. `DemoCutoverContext` carries target,
manifest identity, source revision and run ID. `CutoverEvidence` stores only
stage name, status, reason code, timestamps, safe identifiers, fingerprints and
counts.

The runner has two modes: `prepare` runs through proposal submission and stops
at approval; `apply` is available only to the same interactive process after
approval. There is no unattended apply mode. The public CLI starts `prepare`.

### Generic adapters

Ports cover:

1. local safety;
2. deterministic `nhk-core` deployment;
3. authenticated demo verification/runtime capability preflight;
4. Graph read;
5. WordPress editorial snapshot;
6. live semantic inventory;
7. generic manifest validation and proposal planning;
8. Governance submit, approval gate, eligibility and Controlled Apply;
9. read-back verification; and
10. evidence persistence.

The initial repository implementation provides safe local/in-memory adapters
and production wiring seams. It must not claim a live DEMO deployment when a
remote adapter or credential is unavailable.

## Failure handling

Every stage returns `PASS`, `BLOCKED`, or `FAILED` with a stable reason code.
The first non-pass result stops later stages. Empty data, unavailable runtime,
hydration loss, authentication failure, stale revision, proposal conflict,
eligibility block and uncertain apply are distinct. Re-running with the same
run/input fingerprint is idempotent at the orchestration/evidence boundary;
domain mutation idempotency remains owned by Governance.

The runner emits machine-readable JSON Lines only when explicitly requested by
the adapter, with human-safe summaries on stderr/stdout. Secret review scans
the deployment manifest and serialized evidence before any external action.

## Testing strategy

- Unit tests exercise stage ordering, exact target allowlist, thin-shell argument
  contract, fail-closed behavior, no-apply-before-approval, live-revision
  planning, read-back mismatch, uncertain apply, evidence redaction and
  idempotent reruns.
- Contract tests exercise generic deploy/runtime/Governance ports with in-memory
  adapters.
- Existing PHP Unit suite, PHP lint, Composer validation, diff check and secret
  review are required.
- Live integration is intentionally not run in this implementation task. A
  later cutover readiness task must supply authenticated credentials and produce
  a readiness report before any real DEMO mutation.

## Out of scope

Real deployment/cutover, remote credential setup, production/V2 changes, direct
database operations, pack business rules, automatic approval, semantic data
creation outside Governance, identity merges/rekeys, editorial body migration,
and final production cutover.
