# NHK V3 ChatGPT file transport threat model

## Scope

This model covers the capability-gated `nhk.media.widget-upload` transport,
the structured OpenAI provided-file reference and the single centralized
remote materializer before the existing image/Media ingestion boundary.

## Root cause

The previous materializer authorized a provided `download_url` by exact
hostname allowlist. ChatGPT temporary storage hostnames are not fixed by the
OpenAI file-parameter contract, so a new storage region produced
`CHATGPT_FILE_HOST_NOT_ALLOWED`. The same decision was also an inadequate
network safety boundary because hostname membership did not prove the resolved
destination was safe.

## Assets and threats

| Asset | Threat | Required control |
|---|---|---|
| NHK server network | SSRF to private, loopback, link-local, metadata, reserved or mapped-private destinations | HTTPS/443, hostname validation, A+AAAA resolution, every address globally public, pinned connection |
| Credentials and signed URLs | Leakage through forwarded headers, redirects, logs or model-visible results | No Authorization/Cookie/WP auth/Referer forwarding; redirect revalidation; no URL in diagnostics/results |
| Temporary file and Media state | Oversized stream, decoder/resource exhaustion, corrupt input and orphan artifacts | Random 0600 temp file outside webroot, streaming byte cap, MIME/signature/decoder/dimension checks, existing rollback/read-back pipeline |
| Transport trust boundary | URL-only, opaque/local-path or arbitrary input being treated as a ChatGPT file | Capability-gated tool plus exact provided-file object with bounded `file_id` and `download_url` |

## Security invariants

Provider/region hostname patterns are telemetry/anomaly signals only and are
never the sole authorization or SSRF control. Redirects are limited to two
hops and each hop is independently resolved and validated. The direct cURL
transport disables ambient proxy routing, forwards only a fixed image Accept
header, verifies the TLS peer and hostname, and pins a validated address while
retaining the URL hostname for Host/SNI.

The materializer returns only a native temporary file bag. The widget result
continues to expose canonical attachment/Media read-back fields and never
returns `download_url`, query tokens or signed transport URIs. Existing image
ingest remains responsible for the private source-original, public WebP
derivative, attachment read-back and cleanup of durable partial state.

## Residual operational gate

Live acceptance must still demonstrate two successful uploads whose temporary
URLs use different ChatGPT storage hostnames/regions, with no source-code
hostname allowlist change between them. A live host is recorded as diagnostic
evidence only.
