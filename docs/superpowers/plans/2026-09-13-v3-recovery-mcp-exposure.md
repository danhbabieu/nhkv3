# V3-Recovery MCP Exposure and Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose the isolated `v3-video-recovery-1309` WordPress MCP runtime through a dedicated HTTPS endpoint, authenticate it with a recovery-only least-privilege credential, and verify the required read surface plus the supplied golden object through the authenticated endpoint.

**Architecture:** Keep `/wp-json/nhk/v1/mcp` as the only MCP application endpoint and reuse its JSON-RPC 2.0 Streamable HTTP transport. Add a fail-closed runtime binding at the MCP boundary so a recovery deployment must report the exact recovery environment and database before any MCP request is served. Use the existing WordPress application-password authentication path and a dedicated HTTPS reverse-proxy/tunnel that targets only the local recovery listener; no staging/demo hostname or semantic writer is involved.

**Tech Stack:** PHP 8, WordPress REST API, NHK Core `McpTransport`/`McpApi`, WordPress application passwords and capabilities, local PHP HTTP server, existing Cloudflare tunnel/proxy mechanism, cURL, PHPUnit.

**Spec:** User-provided P0 `EXPOSE AND AUTHENTICATE V3-RECOVERY MCP` acceptance requirements dated 2026-09-13.

## Global Constraints

- Use only recovery environment `v3-video-recovery-1309` and database `nhk_v3_video_recovery`.
- Do not touch snapshot architecture, export/import/re-export/re-import, backlog Videos, Demo staging, historical conflict Proposal, or Article publication.
- MCP endpoint remains `/wp-json/nhk/v1/mcp`, JSON-RPC 2.0, Streamable HTTP, protocol `2026-07-28`.
- Runtime identity mismatch fails closed before MCP dispatch; no recovery mutation capability is available on mismatch.
- Read-only acceptance calls use documentation, inventory, entity, neighborhood, Knowledge, Source, Evidence, Video, Graph and Proposal review/eligibility boundaries only.
- Credentials remain outside Git, are never printed, and are never written to reports, logs, snapshots or payloads.
- ChatGPT-side connector installation is an explicit operator action and is not claimed unless visible through the connector client.

### Task 1: Verify the existing recovery runtime and local MCP boundary

**Files:**
- Read: `tools/recovery-runtime-prepend.php`, `config/recovery-runtime.example.env`, `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/McpApi.php`, `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Test: `tools/mcp-wire-smoke.php` plus a new read-only recovery acceptance probe outside Git

**Interfaces:**
- Local URL: `http://127.0.0.1:8090/wp-json/nhk/v1/mcp`
- Required headers: `Content-Type: application/json`, `Accept: application/json, text/event-stream`, `MCP-Protocol-Version: 2026-07-28`
- Required local calls: `initialize`, `tools/list`, `nhk.documentation.bootstrap`, `nhk.documentation.list`, and the supplied read tools.

- [ ] **Step 1:** Explicitly load the non-secret recovery constants before WordPress, query `SELECT DATABASE()` and layered health, and verify exact identity, migration `20/20`, and supplied inventory counts without mutating data.
- [ ] **Step 2:** Start a recovery-only local HTTP listener bound to `127.0.0.1:8090`, using an explicit router that loads `/private/tmp/nhk-v3-recovery-runtime/runtime-constants.php` before WordPress.
- [ ] **Step 3:** Verify endpoint path, Streamable HTTP headers, initialization status/format, tools/list, anonymous rejection, and authenticated read discovery without calling mutation tools.
- [ ] **Step 4:** Record only status codes, tool names, typed failures, and redacted diagnostics.

### Task 2: Add hard recovery MCP environment binding

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Mcp/RecoveryMcpRuntimeBinding.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/McpApi.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/RecoveryMcpRuntimeBindingTest.php`

**Interfaces:**
- `RecoveryMcpRuntimeBinding::check(): array{ok:bool,reason_code:?string,environment:string,database:string}`
- Expected environment `v3-video-recovery-1309`, mode `recovery`, database `nhk_v3_video_recovery`.
- Exact binding delegates to `McpTransport`; mismatch returns non-success before dispatch and exposes no mutation path.

- [ ] **Step 1:** Add failing tests for exact identity, wrong environment/mode/database, unavailable `$wpdb`, and pre-dispatch rejection.
- [ ] **Step 2:** Run the focused tests and observe failure because the binding is absent.
- [ ] **Step 3:** Implement the binding from runtime constants and `SELECT DATABASE()`; never derive identity from request host/client input and never emit secrets.
- [ ] **Step 4:** Wire the binding into `McpApi` for every MCP request; return HTTP 503 with a machine-readable fail-closed error on mismatch.
- [ ] **Step 5:** Run focused tests and PHP lint.

### Task 3: Provision recovery-only authentication and dedicated HTTPS exposure

**Files:**
- Modify outside Git: recovery WordPress runtime configuration and local service process only
- Read/verify: existing proxy/tunnel configuration and DNS/TLS state
- Report: non-secret connector registration record

**Interfaces:**
- Authentication: WordPress Application Password over HTTP Basic authentication for a dedicated recovery service user.
- Required read capabilities: `read` and `nhk_view_governance`; no ingest/proposal-apply/public-url/internal mutation capabilities.
- HTTPS connector URL: a dedicated recovery hostname ending at `/wp-json/nhk/v1/mcp`.

- [ ] **Step 1:** Inspect recovery users/roles read-only and do not reuse an administrator or staging credential.
- [ ] **Step 2:** Create/configure one recovery-only service account and application password through WordPress’s supported API; keep the secret in an operator secret store or restrictive untracked file.
- [ ] **Step 3:** Route only the dedicated recovery hostname to `127.0.0.1:8090`; preserve the MCP path and authorization header; never use `demo.1945.vn` or a production hostname.
- [ ] **Step 4:** Verify TLS hostname/chain, HTTPS reachability, and absence of local-binding/secret disclosure.

### Task 4: Run authenticated HTTPS golden MCP acceptance

**Files:**
- Read-only runtime probes only; no semantic data files or database writes
- Update: `docs/architecture/V3_EXECUTION_STATE.md` after checkpoint

**Interfaces:**
- Authenticated calls run through the dedicated HTTPS MCP URL, never SQL or internal PHP calls.
- Golden IDs: Capture `01a094df-6e43-7226-a3a5-78a6c4c05c7e`, Video `01a094df-6ff2-7872-9bbe-ca4e843a68ef`, Variant `852da54d-457a-4397-a16d-52d9452ba766`.

- [ ] **Step 1:** Bootstrap/discover through HTTPS and verify deployed documentation/runtime identity.
- [ ] **Step 2:** Read the registered minimum surface: docs bootstrap/get/list, search, entity get/neighborhood, canonical inventory, Knowledge, Source, Evidence, Video, Graph inventory, proposal review/eligibility, and public URL/completion reads where registered.
- [ ] **Step 3:** Read the golden Capture/Video/Variant and returned Source/Claim/Evidence/Attachment/Graph/Public Identity/completion records through HTTPS.
- [ ] **Step 4:** Compare with direct recovery read-back and require exact `MATCH`, `CONTENT_COMPLETE PASS`, and unchanged mutation counters.
- [ ] **Step 5:** Verify anonymous rejection, least-privilege reads, and write denial before execution.

### Task 5: Final verification and handoff

**Files:**
- Update: `docs/architecture/V3_EXECUTION_STATE.md`
- Create: `docs/architecture/V3_RECOVERY_MCP_EXPOSURE_ACCEPTANCE_2026-09-13.md`

- [ ] **Step 1:** Re-read `V3_EXECUTION_STATE.md` and `V2_V3_PARITY_MATRIX.md` before the final checkpoint.
- [ ] **Step 2:** Run relevant PHPUnit/PHP lint, `git diff --check`, and changed-scope secret review; treat network/TLS/auth failures as failures.
- [ ] **Step 3:** Write the final acceptance report with all requested PASS/FAIL fields, connector URL/type/display name, `Secrets printed: NO`, and mutation counters.
- [ ] **Step 4:** Stop at the ChatGPT account-side gate; if not visible in the connector client, report explicit operator connection still required and do not claim installation.
