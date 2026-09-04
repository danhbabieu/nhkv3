# Living Knowledge SEO Stability Contract

**Status:** owner-approved contract, 2026-09-03.

> Preserve search identity; enrich informational depth.

## SEO stable core

Knowledge enrichment must not automatically change the canonical public URL,
slug, canonical tag, H1 identity, established SEO title/identity, primary search
intent, robots/indexability, schema entity identity/`@id` or redirect rules.
Existing
public canonical pages are SEO-protected by default. Indexed status is never
guessed. Living Knowledge uses the shared SEO Core result and may enrich only
eligible fragments/facets. FAQ remains optional editorial projection, not a
rich-result architecture requirement.

## Living content

Recognition, configuration, music, history detail, observed variants,
evidence-backed media, related Knowledge, FAQ projection and supporting
paragraphs may be enriched only at the affected fragment/facet.

Risk is classified as LOW for same-topic additions and evidence-backed media,
MEDIUM for material intro/meta/description/FAQ/section-order changes, and HIGH for stable-core
changes. LOW may auto-project after normal gates; MEDIUM requires stronger
diff/render verification and is not fully publication-approved merely because
the guard allows projection; HIGH requires human approval and is never
auto-applied.

## Guards and failure behavior

The guard compares stable-core fields before and after projection, rejects HIGH
changes without an approved gate, and reports changed facets/fragments and
dependency fingerprints. AI/generated copy is never Evidence and cannot create
SEO identity. Public Vietnamese copy remains subject to the shared advertising
compliance contract. Synthesis/runtime failure preserves last-known-good
eligible projection or deterministic safe content; unavailable is not empty.
