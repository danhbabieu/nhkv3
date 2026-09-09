# Media/Image SEO Projection Contract

> **SUBORDINATE TO THE CONSTITUTION.** This is a projection-only contract.

`Media != MediaAsset != MediaUsage != WordPress attachment != standalone SEO
page`.

The source-original and derivatives retain one canonical Media identity.
MediaAsset delivery URLs are delivery identities, not standalone SEO pages.
WordPress attachment and editorial placement are storage/projection state.

SEO respects representative, evidence and `technical_detail` usage roles.
Representative selection follows the post-ingest deterministic precedence:
exact subject specificity → visual coverage → technical relevance → image
quality/resolution → provenance confidence → current representative quality.
Evidence or technical imagery never replaces a representative solely because it
is newer or larger, but a fully compared and more suitable candidate may be
promoted while the prior representative is demoted if still suitable. Private,
placeholder and technical-only assets are excluded where the target surface
requires a public representative.

Alt text is contextual, concise and accessibility-first. Caption is editorial
context. Neither may assert an unseen fact or create Knowledge, Evidence or a
Graph edge. OCR, EXIF, recognition, filename, alt and caption are candidate
observations only and never semantic writes.

## Canonical full-size image resolution

The public canonical `/anh/<slug>.webp` must use a public derivative generated
from the retained source-original under **PUBLIC IMAGE MAX LONG EDGE = 1200
PX**. When the source long edge is `<= 1200px`, retain its original dimensions;
when it is larger, use `scale = 1200 / max(width, height)` and
`round(dimension × scale)` for both dimensions. Never upscale, crop, stretch or
force a square canvas; preserve the source aspect ratio. 240×340 and similar
thumbnail derivatives are not canonical full-size assets. WebP quality targets
82–88 (current default 86), with no excessive sharpening or intentional color
change. Listings may use separate thumbnail/srcset candidates, but clicking
the gallery image must open the canonical `/anh/<slug>.webp` asset. Missing
source-derived output is an unavailable/incomplete projection, not permission
to fall back to an oversized or thumbnail asset.

After every MCP Media ingest, the Media semantic enrichment and representative
reconciliation required by Constitution §20.1 must complete before the Media
operation is `COMPLETE`; SEO projection consumes the final read-back and does
not create relations or select an image from upload recency alone.
