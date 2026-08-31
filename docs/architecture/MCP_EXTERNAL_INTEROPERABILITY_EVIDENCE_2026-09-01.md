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
