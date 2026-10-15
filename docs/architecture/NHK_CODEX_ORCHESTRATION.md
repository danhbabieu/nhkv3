# NHK V3 — ChatGPT / GitHub / Codex orchestration

Status: **Phase 1 implementation only — guarded automated analysis.** Not a fully autonomous code-fix bot.

## Control plane

ChatGPT (connected GitHub tool) creates and triages Issues, reads PRs and CI, comments acceptance reports. GitHub is the durable task ledger; Codex executes only inside an explicitly authorized workflow. A GitHub Issue/comment does **not** automatically invoke Codex unless the workflow trigger fires.

### States
REQUESTED → TRIAGED → CODEX_ANALYSIS → READY_FOR_IMPLEMENTATION → PR_OPEN → CI_VERIFY → REVIEW_REQUIRED → APPROVED → MERGED → DEPLOY_APPROVAL → DEPLOYED → VERIFIED. Failure states: BLOCKED, CI_FAILED, TEST_RUNTIME_UNAVAILABLE, SECURITY_REVIEW_REQUIRED.

Each transition needs an immutable issue/PR reference and run/commit identity. No silent success.

## Phase 1 (implemented in this PR)
- GitHub Actions workflow `.github/workflows/nhk-codex-orchestration.yml`.
- Trigger by repository owner labeling an **open** issue `nhk:codex`, or owner-only manual workflow dispatch with an issue number.
- Use official `openai/codex-action@v1` with `:read-only` permission and a dedicated GitHub Actions `OPENAI_API_KEY` secret.
- Fetch issue data as untrusted JSON. Codex reads Constitution, analyzes code, returns report. A separate job posts the report back to the Issue.
- No code edits, git push, DB writes, merge, deploy, migrations, staging or production mutations.

## Setup required by repository administrator
1. Review this PR and merge it **only with explicit approval**.
2. Set repository Actions secret `OPENAI_API_KEY` using the GitHub settings UI; never put it in an issue, code, chat, workflow log, or file.
3. Create label `nhk:codex` if absent. Apply label as repo owner to one approved issue, or manually dispatch workflow.
4. Verify GitHub Actions permissions allow issue comments, and review the first run log and output. The workflow fails closed when secret is missing.
5. Monitor API usage/costs; this uses API billing, not necessarily ChatGPT subscription usage.

## Phase 2 (NOT implemented)
A separate, owner-authorized code-write workflow should:
- Use a dedicated isolated runner/worktree and least-privilege Codex workspace permissions.
- Write only to a new feature branch, never `main`; reject workflow/security/constitution file modifications without explicit scope.
- Run lint, focused, unit and contract tests; capture evidence and baseline failures.
- Create a draft PR using a **separate** least-privilege publishing job; never give code-writing Codex a token that can merge/deploy.
- Bound attempts and retries (e.g. maximum two automated repair attempts); do not loop indefinitely.
- Require owner review before marking ready; no auto-merge.

## Phase 3 (NOT implemented)
- Authorized isolated TEST runtime integration, with runtime/database/build identity verification.
- Signed exact packets and readback for any later approved STAGING semantic acceptance.
- Separate GitHub protected environments and approvals for merge, deployment, migration and STAGING/production writes. GitHub workflow labels are **not** authorization for these actions.
- Audit every request, actor, branch, commit, CI run, policy decision and approval.

## Security notes
- No `pull_request_target` execution of untrusted code.
- No self-hosted production runner.
- Treat issue body and Codex output as untrusted; avoid interpolation into shell commands.
- `openai/codex-action` should be the last step of the analysis job; the report is posted in a separate job.
- Restrict triggers to repository owner initially; expand only after a permission model is reviewed.
- Existing NHK V3 Constitution and AGENTS.md always take precedence.

## Definition of done for end-to-end automation
Owner-created Issue → guarded Codex code fix → draft PR → CI → ChatGPT review → owner approval → merge, with deploy/migration/data writes separately approved. Phase 1 alone **does not** meet this definition.
