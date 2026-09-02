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

The current slice provides metadata ingestion and policy enforcement. A real
byte-upload transport and the adapter that maps a WordPress attachment to a
canonical Media remain separate runtime work and require their own evidence.
