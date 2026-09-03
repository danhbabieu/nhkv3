# NHK V3 Admin Media Input Guidance

> **NON-NORMATIVE.** This is implementation guidance. If it conflicts with
> `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

## Purpose

Admin and other operator-facing inputs must preserve the Media, MediaAsset and
MediaUsage boundaries while allowing an editor to describe image intent. The
Admin surface is an input adapter; it is not a second semantic write path.

## Required flow

Admin composes a governed Media proposal and submits it through the existing
Governance and Controlled Apply path. The proposal may carry asset metadata,
contextual usage fields, the controlled usage role, SEO Blueprint context and
batch context. The application layer then delegates Media persistence through
`MediaIngestGateway` and `MediaService`.

Admin must not write `nhk_media`, `nhk_media_assets` or `nhk_media_usages`
directly, create a Graph edge from an upload, promote OCR/recognition to an
Authority identity, or use keyword groups as meta keywords or Knowledge.

## Controlled input vocabulary

Use `MediaUsageRoleRegistry` for roles, `MediaDetailTypeRegistry` for detail
types and `SeoKeywordGroupRegistry` for bounded keyword groups. Use
`MediaDiagnosticCodeRegistry` and `MediaSeoStateRegistry` when displaying
missing, placeholder, low-resolution, metadata or rights diagnostics. Unknown
values fail closed.

For an Article, the input adapter may select one Media for
`featured_primary`, one distinct Media for `inline_primary`, and zero or more
supporting Media. The Article coordinator reuses suitable existing Media
before creating a placeholder. WordPress remains the owner of editorial image
selection and content ordering.

## SEO and upload expectations

Alt text and caption are usage-context fields. Subject, view and filename
intent belong to the Blueprint and asset metadata; they do not change Media
identity. Camera-style filenames are normalized by the application boundary
when enough context exists. Public preferred-image and sitemap projections
exclude placeholders and non-public assets.

Every new NHK-managed image byte upload must normalize before durable
persistence: validate, auto-orient, resize, derive a contextual ASCII filename,
encode WebP, persist the normalized primary, create only contract-required
WebP derivatives, read back the attachment, and clean up source/work files.
The original upload name and source binary are not durable public identity. A
missing trustworthy context fails closed; the adapter must not invent a
descriptive filename. This policy is scoped to the canonical Media ingest
boundary and does not infer Authority, Knowledge, Evidence, Graph or
`depicts`.

The current direct multipart MCP adapter persists one verified WebP primary and
does not call WordPress's global intermediate-size generator, so this path is
bounded to one physical image file until a real derivative contract requires
more. Existing legacy attachments are read-only and are not renamed, rewritten
or deleted by this policy.
