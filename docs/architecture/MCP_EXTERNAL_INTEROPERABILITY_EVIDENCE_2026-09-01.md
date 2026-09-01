# MCP External Interoperability Evidence — 2026-09-01

This is a read-only probe record. No external or local NHK data was written.
It does not approve a deployment or declare MCP parity.

## Probe result

| Read-only ability | Result | Bounded observation |
|---|---|---|
| nhk/source-list | PASS | Callable with page/limit parameters; returned Source records whose review state was DRAFT; response includes canonical identity and a richer core object. |
| nhk/media-list | PASS | Callable with page/limit parameters; returned total=242; the sample included both PUBLIC and PRIVATE records and asset processing states. |
| nhk/video-list | PASS | Callable with page/limit parameters; returned total=0; readiness reported VIDEO_STORAGE_READY; no Video record was available for active-detail QA. |

## Interoperability boundary

The external abilities are reachable and read-only, but this probe does not
prove wire-level parity with the V3 core Streamable HTTP endpoint. Their
payloads use an external adapter schema (core, status, asset, public_url, rich
relations) rather than the V3 public REST/MCP serializers. Source records are
still draft, Media records retain mixed visibility, and the external Video
inventory is empty. Mapping, authentication, pagination/error semantics and
deployment verification remain open before MCP interoperability can be marked
complete.

Evidence was collected with limits of at most 10 Media and 5 Source/Video
records per call. The calls were read-only and reported zero writes where the
adapter exposes a write counter.

## Canonical identity cross-check

Read-only calls by canonical ID confirmed that three external Media records
(`839ba38c-d60e-4029-ad90-245bd73a267a`,
`6c1783b3-d49e-4664-bec0-256d91db79b9` and
`11f5eb62-076e-44b6-a34a-740963e5c50c`) resolve to the same canonical IDs in
the local V3 database. Local public REST returns `404 nhk_media_not_found` for
all three because their parent Media is draft and/or the processed asset is
not currently deliverable; this is the intended V3 fail-closed policy, not an
identity mismatch. The external adapter exposes the records as PRIVATE with
ready processed assets, so its richer response cannot be forwarded directly to
the V3 public serializer. The source/usage mapping and explicit policy for
bridging this state remain open.

## Local V3 wire probe

The local V3 endpoint was also probed with standard Streamable HTTP JSON-RPC:
`initialize` with `params.protocolVersion`, `tools/list` and `tools/call` with
the `MCP-Protocol-Version` header only all returned HTTP 200 and JSON-RPC
success. `notifications/initialized` returned HTTP 202 with no body. Custom
`Mcp-Method`/`Mcp-Name` headers remain supported as optional mismatch guards.
This closes the previously observed custom `_meta`/header coupling for the
basic session and tool exchange. The endpoint registration also extends the
WordPress REST CORS allowlist for `MCP-Protocol-Version`, `Mcp-Method` and
`Mcp-Name`, covering browser preflight for those protocol headers. A live
preflight curl against `http://localhost/wp-json/nhk/v1/mcp` returned HTTP 200,
echoed the requesting origin and listed all three protocol headers. External
adapter mapping and deployment verification remain open.

The result is reproducible with the repository's no-write command
`php tools/mcp-wire-smoke.php`, which passes CORS preflight, `initialize`,
`tools/list` (18 tools), invalid-Origin rejection (403) and the 202
response/no-body notification contract.
