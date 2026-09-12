# Current ChatGPT Handoff

Updated: 2026-09-12

## Commit checkpoint — files[] fix

COMMIT_SHA: `a66c7eae918c6eccad61121fa95e36348f53fe6b`

TEST_RESULTS: Required focused selection **96 tests / 714 assertions PASS**;
NHK Unit **1,216 / 5,957 PASS**; PHP lint, `git diff --check`, JSON validation
and secret review **PASS**.

LIVE_ACCEPTANCE_PENDING: `true`

DEPLOYED_COMMIT: `NOT_DEPLOYED`

DEPLOY_RESULT: `BLOCKED — canonical wrapper requires a clean checkout and NHK_DEMO_DEPLOY_CONFIG; current HEAD/origin/main is 946ec1fb, not target a66c..., and Snapshot changes must be preserved.`

LIVE_RUNTIME_IDENTITY: `NOT_VERIFIED`

DOCUMENTATION_VERSION: `NOT_VERIFIED`

MANIFEST_HASH: `NOT_VERIFIED`

CAPTURE_FILES_SCHEMA: `NOT_VERIFIED — no target tools/list was called`

LIVE_ACCEPTANCE_READY: `NO`

NEXT_ACTION_FOR_CHATGPT: `deploy this commit to demo.1945.vn, fresh bootstrap/discovery, then test one real chat attachment and a multi-file submission`

This commit contains only the seven files listed in the files[] checkpoint.
Video and other concurrent changes are not in the commit.

## files[] transport repair — current checkpoint

Scope was restricted to the Easy MCP native multipart compatibility boundary
for `nhk.capture.ingest`. No Capture, Governance, Media, Authority, Knowledge,
Graph, Video or Public Identity semantics changed; no deploy, push or live
mutation was performed.

### ROOT_CAUSE

`EasyMcpNativeFileCompatibilityAdapter::normalizeAbilityInput()` only ran when
`self::$proxyDispatch` was true. Easy MCP 1.7.17 can invoke the registered
Ability directly after parsing the incoming multipart request. In that path the
Ability received ChatGPT's model-facing opaque `files[]` strings and strict
`WP_Ability::validate_input()` rejected `input[files][0]` before the existing
callback could forward the native PHP file bag. The failure is transport/schema
ordering, not a Capture or Media-owner failure.

### CALL_PATH

`ChatGPT file parameter` → Easy MCP `tools/list` → canonical Capture schema +
`_meta[openai/fileParams]=["files"]` → model-facing `string[]` → execution
JSON-RPC plus native multipart `files[]` → Easy MCP direct or nested-proxy
dispatch → native descriptor normalization → `WP_Ability::validate_input()` →
`McpAbilityRegistration::executeMcp()` strips `files` from JSON and attaches the
native file bag to `WP_REST_Request` → `/nhk/v1/mcp` → `McpTransport` → one
`nhk.capture.ingest` → existing Capture physical ingest/Media boundary.

### FILES_CHANGED

- `public/wp-content/plugins/nhk-core/src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php`
- `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`
- focused tests in `EasyMcpNativeFileCompatibilityAdapterTest.php` and
  `MediaBatchUploadServiceTest.php`

The adapter now normalizes native parts on both supported Easy MCP paths,
preserves order/cardinality, keeps `items[i]` positional context untouched and
returns typed `nhk_native_multipart_required` or alignment errors when native
parts are absent/mismatched. Bytes never enter JSON; opaque IDs are never
treated as filesystem paths. Existing 1..20 / 50MB limits, partial-success,
idempotency, cleanup, text-only, addendum-file rejection and Video resume
paths remain delegated to their existing owners.

### TEST_RESULTS

- Focused transport/Capture/Media/Video/MCP: **115 tests, 808 assertions — PASS**.
- NHK Unit: **1,216 tests, 5,957 assertions — PASS** (13 warnings, 11
  deprecations, 11 PHPUnit deprecations).
- NHK Contract: **4 tests, 31 assertions — PASS**.
- PHP lint for changed PHP files: **PASS**.
- Typed bare-string probe with WordPress `WP_Error`: **PASS** —
  `nhk_native_multipart_required` / `NATIVE_MULTIPART_FILES_REQUIRED`.
- NHK Integration: **BLOCKED**, 4 bootstrap errors and 14 environment-gated
  failures because `update_option()`/`NHK_WP_TEST_PATH` is unavailable; no
  integration pass claimed.
- `git diff --check`: **PASS**; changed-scope secret review: **PASS** (no
  credential/private-key/token material).

### REMAINING_LIVE_GAP

The supplied live evidence identifies Easy MCP AI **1.7.17** and the original
validation error. This workspace has no authenticated live connector/runtime
endpoint and no local WordPress integration database, so the repaired request
has not been replayed live. Fresh ChatGPT `tools/list` rediscovery and one
read-only 1-file/2-file multipart Capture smoke are still required after the
code is deployed by an authorized operator. No deployment is claimed.

### NEXT_ACTION_FOR_CHATGPT

After an authorized deployment/restart/cache refresh, rediscover
`nhk.capture.ingest`; confirm `files` remains native binary in the descriptor and
`_meta["openai/fileParams"]` is `["files"]`. Then run one text+one-file and one
text+two-file submission with distinct `items[]`, verifying one Capture, native
multipart forwarding, ordered `items[i]` alignment and no `input[files][0]`
schema error. If native parts are still absent, report the typed
`NATIVE_MULTIPART_FILES_REQUIRED` response and do not retry via paths/base64.

## L3 descriptor investigation — current checkpoint

Scope was restricted to the Easy MCP descriptor exposure path. No Capture,
Media, Video, Knowledge or Governance business logic was changed; no endpoint,
deployment or live mutation was performed.

### Exact reproducible evidence

- Canonical `McpToolCatalog::tools()` for `nhk.capture.ingest`:
  `capture_id=present`, type `string`, format `uuid`, optional; `files=present`,
  array with `format=binary` and the native multipart description; required is
  exactly `idempotency_key, documentation_checkpoint`.
- WordPress Ability registration at
  `src/Application/Mcp/McpAbilityRegistration.php:347` takes the canonical
  `inputSchema` for Capture unchanged; the special schema rewrite only applies
  to `nhk.media.ingest` at `:369`.
- Easy MCP compatibility projection at
  `src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php:51-70`
  replaces the target tool's complete `inputSchema` from `McpToolCatalog`,
  copies the canonical description and `_meta.openai/fileParams`, while
  retaining Easy MCP tool identity/annotations.
- The response hook at `:118-132` runs only for route
  `/easy-mcp-ai/v1/mcp`, method `tools/list`, and supported installed versions
  `1.7.16`/`1.7.17`.
- Reproducible stock Easy MCP descriptor before projection: target tool had a
  stripped schema with `files` but no `capture_id`.
- Reproducible projection after adapter: target tool had the full canonical
  schema with `capture_id`, native `files`, required fields unchanged and
  annotations preserved.
- Local HTTP endpoint `http://localhost/wp-json/easy-mcp-ai/v1/mcp` was
  unavailable (`curl: Failed to connect to localhost port 80`). The local
  WordPress integration bootstrap is also DB-blocked, so no raw live/HTTP
  Easy MCP JSON could be obtained here.

### L3 conclusion

`CAN_FIX_IN_NHK_REPO=NO_EVIDENCE_YET`. The requested suspected root cause
(adapter only patching files while retaining a stock schema) is disproven by
the checked-out source and passing projection tests. The remaining hypotheses
are outside the reproducible local path: the live installed Easy MCP version
may bypass the version-gated hook, the hook may not be registered on the live
route, or the connector may transform the already projected response. The
ChatGPT observation (`capture_id=MISSING`, native files present) is recorded
as external evidence, not locally reproduced raw JSON.

Focused L3 run: **73 tests, 504 assertions, 1 deprecation, 25 skipped — PASS
for runnable tests**. Skips are WordPress/Easy MCP integration prerequisites.
No RED test was added because the adapter's canonical-complete projection
behavior is already covered and passes; adding a code change would be
speculative.

`CHATGPT_BRIDGE_UNAVAILABLE` remains active. Decision requested from ChatGPT:
provide the exact raw Easy MCP `tools/list` payload before/after NHK's
`rest_post_dispatch` projection, including `EASY_MCP_AI_VERSION`, route and
whether the response passed through the NHK filter. Until that evidence is
available, do not change code or invoke Capture continuation.

## Git

- Branch: `main`
- HEAD: `301e06808a4c7669d9989c0e4b8f0e76e8704019`
- Relation to `origin/main`: ahead 1; working tree contains only pre-existing
  untracked handoff/report files.
- The current HEAD also includes subsequent Governance queue commits from
  another workspace process; this L3 probe did not modify those changes.

## Task

Converge canonical `nhk.capture.ingest` orchestration for the approved Odo
36/10 regression without creating a new semantic owner, mutating live data,
deploying or publishing.

## Proven root causes

1. Capture and MCP/semantic resolution used separate paths. The shared typed
   Authority packet is now produced by
   `src/Application/Semantic/CanonicalAuthoritySubjectResolver.php` and
   consumed by `McpSemanticContextResolver`; exact canonical matching was
   previously inconsistent with MCP substring discovery.
2. Video enrichment ran before Capture subject resolution in
   `src/Application/Capture/EditorialCaptureCoordinator.php`, allowing title
   matching to broaden Variant Odo 36/10 to Model Odo 36.
3. `ArticleMediaCoordinator` and Capture wiring admitted current/global
   WordPress Media without persisted subject-scope proof, allowing stale
   attachments 297/298 to be considered.
4. Capture's broad exception path classified
   `ARTICLE_MEDIA_BLUEPRINT_IS_INVALID_` as `FAILED_RETRYABLE`, conflating a
   blueprint contract/system error with ordinary missing-media readiness.
5. `capture_id` was already present in `McpToolCatalog` and routed by
   `McpTransport`; continuation parity is covered by existing schema/catalog/
   transport tests rather than a second endpoint.

## Changes

- One typed canonical subject-resolution handoff with explicit UUID precedence.
- Video child enrichment consumes the resolved Capture subject and preserves
  Variant identity; external platform plus external video ID is reused before
  proposal/create.
- Historical Media reuse requires resolved subject lock plus persisted scope;
  stale WordPress featured/inline usage is rejected and no global fallback is
  used for text+Video with no eligible Media.
- User text is atomized as `EXPLICIT_USER_KNOWLEDGE`; specimen observations,
  evaluations and recognition wording remain scoped/attributed/review-required.
- Existing Claim reuse is checked before creating a duplicate proposal, while
  durable mutations remain behind Governance.
- Existing-Capture continuation remains `capture_id`-based, idempotent for the
  same addendum payload and conflicting for changed payloads.
- Canonical ACTIVE docs and ignored local MCP documentation projection were
  regenerated with the repository command.

## Tests and verification

- Focused Capture/resolver/media/video/claim/continuation/MCP suite: **125
  tests, 849 assertions — PASS**.
- NHK Unit: **962 tests, 4714 assertions — PASS**, with 7 warnings, 2
  deprecations and 9 PHPUnit deprecations.
- NHK Contract: **4 tests, 31 assertions — PASS**.
- Documentation/MCP/SEO contract subset after final projection:
  **44 tests, 544 assertions — PASS**.
- `composer lint` — PASS.
- `git diff --check` — PASS.
- Documentation manifest validation: **29/29 files ACTIVE — PASS**.
- Integration command reached WordPress bootstrap but stopped with exact
  `Error establishing a database connection`; it is **BLOCKED**, not PASS.
- Secret scan of changed files — clean.

## Documentation/build state

- Canonical command: `composer generate:mcp-docs`.
- Generated 29 canonical MCP documentation files.
- `documentation_version`:
  `ab2c095b43acd4f162537be13e8f3450592d8a108d8176420094eca71c101e55`
- `manifest_hash`:
  `d9aac4e064ca8d0ca8a22b3f0ae55008d84f4af406408c728096587ab6acfe81`
- `runtime_version`: `0.1.0`
- `build_identity`: `NOT_IN_MANIFEST_RUNTIME_COMPUTED`
- Updated canonical docs include Article Ingest, Media, Video Semantic
  Ingest, Governed Living Knowledge, MCP Content Operations, Documentation
  Status Index and V3 Execution State.

## Blockers and review

- `CHATGPT_BRIDGE_UNAVAILABLE`: no configured bridge/API for sending this
  handoff to ChatGPT was found in the current environment. No communication
  is claimed.
- Integration DB/runtime is unavailable; live Capture `01a08952-6eb0-7397-83a3-a4affb2075b2`,
  Article 355 and DEMO were not replayed or mutated.
- No architecture decision is currently required beyond the already approved
  Option A. ChatGPT review is requested for the resolution-handoff/media-gate
  design and the distinction between readiness `PARTIAL` and system
  `SYSTEM_BLOCKED` before any future runtime/live action.

## Proposed next action

## L3 live server probe — current checkpoint

ChatGPT supplied fresh live identity/discovery evidence after documentation
bootstrap: Easy MCP `1.7.16` active; NHK Core `0.1.0` active;
`documentation_version=ab2c095b43acd4f162537be13e8f3450592d8a108d8176420094eca71c101e55`;
`manifest_hash=d9aac4e064ca8d0ca8a22b3f0ae55008d84f4af406408c728096587ab6acfe81`;
and runtime `build_identity=9b20d14d70d43f40b89fab0484556f3ab52e322991d15ab5b9984bc71a1abc20`.
The final connector observation remains `capture_id=MISSING`, while `files`
has the new native multipart description.

The requested shell-side live probe cannot run through the configured repo
boundary: `NHK_DEMO_DEPLOY_CONFIG` is unset, `RemoteRuntimeAdapter` only
allows existing maintenance operations and no local HTTP server is listening.
Therefore `PRE_FILTER_RAW=UNAVAILABLE_WITHOUT_INSTRUMENTATION`; live catalog,
live hook registration/priority and final raw Easy MCP JSON are not
independently verified here. Source-level expectation remains that version
1.7.16 passes the adapter gate and `rest_post_dispatch` is registered at
priority 10 during Plugin boot.

Root cause is narrowed but not proven to live hook/path behavior or
connector-side field transformation. No NHK code fix is justified. No deploy,
restart, cache purge, database/option update, Capture call or other live
mutation was performed. This checkpoint requires ChatGPT review or an
explicitly configured read-only remote descriptor probe before code changes.

ChatGPT/control-plane review should inspect this handoff and the committed
diff. After review, provision the non-destructive `nhk_v3_test` integration
runtime and rerun the Integration suite. Do not deploy, publish or mutate live
data until that verification and an explicit review decision are available.

## L3 in-process REST probe — current checkpoint

### Probe correction — 2026-09-10

The earlier owner command used `rest_do_request($request)` and treated its
return value as the final response. That is invalid evidence for
`rest_post_dispatch`: `rest_do_request()` reaches
`WP_REST_Server::dispatch()`, while the normal serving path applies
`rest_post_dispatch` later. Do not run that earlier command. The corrected
probe below compares the raw dispatch result with the explicitly filtered
response and inspects `$wp_filter['rest_post_dispatch']` without dumping
unrelated callbacks.

The command in this section is superseded by the final owner command in the
latest handoff response: it must not manually fire `rest_api_init`, must call
`rest_get_server()` exactly once, must match the adapter using its PHP `::class`
constant, and must expose sanitized dispatch errors rather than converting
them to `tool_found=false`.

Corrected owner command:

```sh
wp --path=<LIVE_WORDPRESS_ROOT> --user=<EXISTING_WP_USER> eval '
$target = "wp_ability_nhk_v3_capture_ingest";
$pick = static function (mixed $response) use ($target): ?array {
    if (is_wp_error($response)) return null;
    $response = rest_ensure_response($response);
    $data = method_exists($response, "get_data") ? $response->get_data() : [];
    if (!is_array($data)) return null;
    foreach ((array) ($data["result"]["tools"] ?? []) as $tool) if (is_array($tool) && ($tool["name"] ?? "") === $target) return $tool;
    return null;
};
$shape = static function (?array $tool): array {
    $schema = is_array($tool["inputSchema"] ?? null) ? $tool["inputSchema"] : [];
    $properties = is_array($schema["properties"] ?? null) ? $schema["properties"] : [];
    $meta = is_array($tool["_meta"] ?? null) ? $tool["_meta"] : [];
    return [
        "tool_found" => is_array($tool),
        "capture_id" => $properties["capture_id"] ?? null,
        "files" => $properties["files"] ?? null,
        "required" => is_array($schema["required"] ?? null) ? $schema["required"] : [],
        "fileParams" => $meta["openai/fileParams"] ?? null,
    ];
};
$catalog = null;
foreach (\NHK\Core\Application\Mcp\McpToolCatalog::tools() as $tool) if (($tool["name"] ?? "") === "nhk.capture.ingest") { $catalog = $tool; break; }
$canonicalSchema = is_array($catalog["inputSchema"] ?? null) ? $catalog["inputSchema"] : [];
$canonicalProperties = is_array($canonicalSchema["properties"] ?? null) ? $canonicalSchema["properties"] : [];
$canonicalMeta = is_array($catalog["connectorMeta"] ?? null) ? $catalog["connectorMeta"] : [];
$request = new WP_REST_Request("POST", "/easy-mcp-ai/v1/mcp");
$request->set_header("Content-Type", "application/json");
$request->set_header("Accept", "application/json, text/event-stream");
$request->set_body(wp_json_encode(["jsonrpc" => "2.0", "id" => "nhk-l3-readonly-probe", "method" => "tools/list", "params" => new stdClass()]));
do_action("rest_api_init");
$server = rest_get_server();
$raw = $server->dispatch($request);
$post = apply_filters("rest_post_dispatch", rest_ensure_response($raw), $server, $request);
$hookRows = [];
global $wp_filter;
$hook = $wp_filter["rest_post_dispatch"] ?? null;
if ($hook instanceof WP_Hook) foreach ($hook->callbacks as $priority => $callbacks) foreach ($callbacks as $entry) {
    $callback = $entry["function"] ?? null;
    if (!is_array($callback) || count($callback) !== 2) continue;
    $class = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
    $method = (string) $callback[1];
    if ($class === "NHK\\Core\\Infrastructure\\Mcp\\EasyMcpNativeFileCompatibilityAdapter" && $method === "projectToolsListDescriptor") $hookRows[] = ["class" => $class, "method" => $method, "priority" => (int) $priority];
}
$out = [
    "easy_mcp_version" => defined("EASY_MCP_AI_VERSION") ? (string) EASY_MCP_AI_VERSION : null,
    "canonical" => ["capture_id" => $canonicalProperties["capture_id"] ?? null, "files" => $canonicalProperties["files"] ?? null, "required" => is_array($canonicalSchema["required"] ?? null) ? $canonicalSchema["required"] : [], "fileParams" => $canonicalMeta["openai/fileParams"] ?? null],
    "hook" => ["adapter" => "NHK\\Core\\Infrastructure\\Mcp\\EasyMcpNativeFileCompatibilityAdapter", "callback" => "projectToolsListDescriptor", "priority" => $hookRows[0]["priority"] ?? null, "registered" => $hookRows !== []],
    "raw_dispatch" => $shape($pick($raw)),
    "post_dispatch" => $shape($pick($post)),
];
echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
'
```

`CAN_EXECUTE_LIVE=NO`: WP-CLI is installed locally, but
`NHK_DEMO_DEPLOY_CONFIG=UNSET`; no authorized live runtime boundary is
configured, and local WordPress cannot bootstrap the integration database.

Source-confirmed callback: `NHK\\Core\\Infrastructure\\Mcp\\EasyMcpNativeFileCompatibilityAdapter::projectToolsListDescriptor`,
registered on `rest_post_dispatch` at priority `10`. The source version gate
accepts Easy MCP `1.7.16`; live registration remains unverified.

Owner-run safe probe (read-only, no options/data/plugin changes):

```sh
wp --path=<LIVE_WORDPRESS_ROOT> --user=<EXISTING_WP_USER> eval '
$target = "wp_ability_nhk_v3_capture_ingest";
$out = ["easy_mcp_version" => defined("EASY_MCP_AI_VERSION") ? EASY_MCP_AI_VERSION : null];
$catalog = null;
foreach (\\NHK\\Core\\Application\\Mcp\\McpToolCatalog::tools() as $tool) if (($tool["name"] ?? "") === "nhk.capture.ingest") $catalog = $tool;
$out["canonical"] = ["capture_id" => $catalog["inputSchema"]["properties"]["capture_id"] ?? null, "files" => $catalog["inputSchema"]["properties"]["files"] ?? null, "required" => $catalog["inputSchema"]["required"] ?? [], "fileParams" => $catalog["connectorMeta"]["openai/fileParams"] ?? null];
$callable = ["\\NHK\\Core\\Infrastructure\\Mcp\\EasyMcpNativeFileCompatibilityAdapter", "projectToolsListDescriptor"];
$out["hook"] = ["adapter" => $callable[0], "callback" => $callable[1], "priority" => has_filter("rest_post_dispatch", $callable)];
$request = new WP_REST_Request("POST", "/easy-mcp-ai/v1/mcp");
$request->set_header("content-type", "application/json");
$request->set_body(wp_json_encode(["jsonrpc" => "2.0", "id" => "nhk-l3-readonly-probe", "method" => "tools/list", "params" => new stdClass()]));
$response = rest_do_request($request);
if (is_wp_error($response)) { echo wp_json_encode(["error" => ["code" => $response->get_error_code(), "message" => wp_strip_all_tags($response->get_error_message())]], JSON_UNESCAPED_SLASHES), PHP_EOL; return; }
$data = rest_get_server()->response_to_data($response, false); $found = null;
foreach ((array) ($data["result"]["tools"] ?? []) as $tool) if (($tool["name"] ?? "") === $target) { $found = $tool; break; }
$out["final"] = ["tool_found" => is_array($found), "capture_id" => $found["inputSchema"]["properties"]["capture_id"] ?? null, "files" => $found["inputSchema"]["properties"]["files"] ?? null, "required" => $found["inputSchema"]["required"] ?? [], "fileParams" => $found["_meta"]["openai/fileParams"] ?? null, "description" => $found["description"] ?? null];
echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
'
```

`PRE_FILTER_RAW=UNAVAILABLE_WITHOUT_INSTRUMENTATION`. Owner should return only
the sanitized JSON output; no credentials or unrelated tool payloads.

## Probe correction — 2026-09-10

The prior owner command using `rest_do_request()` alone is invalid for
observing `rest_post_dispatch`; it is superseded and must not be used for L3
acceptance. The corrected read-only probe explicitly captures both
`RAW_DISPATCH` from `$server->dispatch($request)` and `POST_DISPATCH` after
`apply_filters('rest_post_dispatch', rest_ensure_response($raw), $server,
$request)`.

```sh
wp --path=<LIVE_WORDPRESS_ROOT> --user=<EXISTING_WP_USER> eval '
$target = "wp_ability_nhk_v3_capture_ingest";
$adapter = "\\NHK\\Core\\Infrastructure\\Mcp\\EasyMcpNativeFileCompatibilityAdapter";
$method = "projectToolsListDescriptor";
$out = ["easy_mcp_version" => defined("EASY_MCP_AI_VERSION") ? EASY_MCP_AI_VERSION : null];
$catalog = null;
foreach (\\NHK\\Core\\Application\\Mcp\\McpToolCatalog::tools() as $tool) if (($tool["name"] ?? "") === "nhk.capture.ingest") $catalog = $tool;
$out["canonical"] = ["capture_id" => $catalog["inputSchema"]["properties"]["capture_id"] ?? null, "files" => $catalog["inputSchema"]["properties"]["files"] ?? null, "required" => $catalog["inputSchema"]["required"] ?? [], "fileParams" => $catalog["connectorMeta"]["openai/fileParams"] ?? null];
$registered = [];
$hook = $GLOBALS["wp_filter"]["rest_post_dispatch"] ?? null;
foreach ((array) ($hook->callbacks ?? []) as $priority => $callbacks) foreach ($callbacks as $entry) { $fn = $entry["function"] ?? null; if (is_array($fn) && count($fn) === 2) { $class = is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0]; if ($class === $adapter && (string) $fn[1] === $method) $registered[] = ["class" => $class, "method" => (string) $fn[1], "priority" => (int) $priority]; } }
$out["hook"] = ["adapter" => $adapter, "callback" => $method, "priority" => has_filter("rest_post_dispatch", [$adapter, $method]), "registered" => $registered !== []];
$extract = static function ($response) use ($target): array { if (is_wp_error($response)) return ["tool_found" => false, "error" => ["code" => $response->get_error_code(), "message" => wp_strip_all_tags($response->get_error_message())]]; $data = rest_get_server()->response_to_data($response, false); $found = null; foreach ((array) ($data["result"]["tools"] ?? []) as $tool) if (($tool["name"] ?? "") === $target) { $found = $tool; break; } return ["tool_found" => is_array($found), "capture_id" => $found["inputSchema"]["properties"]["capture_id"] ?? null, "files" => $found["inputSchema"]["properties"]["files"] ?? null, "required" => $found["inputSchema"]["required"] ?? [], "fileParams" => $found["_meta"]["openai/fileParams"] ?? null]; };
do_action("rest_api_init");
$request = new WP_REST_Request("POST", "/easy-mcp-ai/v1/mcp");
$request->set_header("content-type", "application/json");
$request->set_body(wp_json_encode(["jsonrpc" => "2.0", "id" => "nhk-l3-readonly-probe", "method" => "tools/list", "params" => new stdClass()]));
$server = rest_get_server();
$raw = $server->dispatch($request);
$out["raw_dispatch"] = $extract($raw);
$post = apply_filters("rest_post_dispatch", rest_ensure_response($raw), $server, $request);
$out["post_dispatch"] = $extract($post);
echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
'
```

This command performs only in-process reads and one JSON-RPC `tools/list`; it
does not call Capture or mutate WordPress. `PRE_FILTER_RAW` remains
`UNAVAILABLE_WITHOUT_INSTRUMENTATION` for the unfiltered Easy MCP internals.
