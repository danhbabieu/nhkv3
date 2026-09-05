# Governed Living Knowledge Design

**Status:** owner-approved incremental design, updated 2026-09-05.

## Ownership

Authority owns canonical identity and lifecycle. Graph owns typed relations.
Knowledge owns atomic claims. Source/Evidence owns provenance and support.
WordPress native `wp_posts` owns Article title, body, author, dates, categories
and editorial URLs. Media, MediaAsset, MediaUsage and Video keep their existing
bounded contexts. Public Projection is a presentation/read model. Governance
owns durable semantic mutation.

Dictionary lexical state is a separate curation boundary governed by
`DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`. Dictionary Concept, Label,
Candidate and Mention records do not become Authority, Knowledge, Evidence or
Graph records merely by existing.

No Article, FAQ, Cluster, Projection or KnowledgeCell Authority type is added.
No new Graph predicate is added by this design.

## Living Knowledge behavior

Semantic meaning is never silently rewritten in an existing claim. Editorial
typos that do not change meaning may use the existing correction contract;
otherwise the system conservatively classifies structured input as one of:

- same claim with new Evidence;
- new compatible claim;
- qualifying Evidence/claim;
- contradicting Evidence/claim;
- semantic replacement requiring a lifecycle decision for the old claim;
- retirement of obsolete claim through Governance.

Historical claims remain readable. `supports`, `contradicts` and `qualifies`
remain the only Evidence relations. Scope is bounded at the narrowest supported
level: entity, brand, model, variant, movement or specimen observation. A
specimen observation never promotes automatically to Variant, Model or Brand.
Exact normalized text equality is only a deterministic exact-match signal for
an active claim in the same subject/facet/scope. It is not broad semantic
equivalence. Fuzzy or AI similarity cannot decide canonical identity. Explicit
structured relation context is required for add-Evidence, qualification and
contradiction; ambiguous or unsupported input fails closed.

Dictionary labels may assist lexical lookup and disambiguation before Living
Knowledge planning. They do not establish semantic equivalence by themselves.
Before a new factual claim is proposed, Current Truth/Knowledge must be checked
and an existing canonical claim reused or enriched when applicable. A
Dictionary definition or repeated wording is never sufficient evidence to mint
a duplicate Knowledge claim.

## Read model pipeline

`Canonical Knowledge → Current Truth Resolver → Knowledge Cluster/Facet Read
Model → AI Natural Synthesis port → SEO Stability Guard → Public Projection`.

Facet/profile metadata is stored in the existing provenance/metadata JSON
boundary, under validated namespaced keys. Unknown values fail closed. A
cluster is an application/read concept and never a persisted semantic entity or
Graph edge.

The resolver is read-only and returns compatible claims, qualifiers,
contradictions, unresolved conflicts, scope, evidence coverage and internal
trace references. It never persists aggregate truth and never lets AI choose a
winner for an unresolved conflict.

## Projection and provenance

Entity pages are living projections rebuilt per affected facet. Fragments are
`overview`, `recognition`, `configuration`, `movement`, `music`, `history`,
`domestic_cultural`, `evidence_media` and `related`. Every fragment carries a
dependency fingerprint made from subject, facet/profile version, fragment key,
claim IDs/revisions/lifecycle/public state, Evidence IDs/revisions/relation/
state, eligible Source revisions, projection contract version and
generator/policy version.
Unchanged fingerprints are reused; unrelated fragments remain unchanged.

AI synthesis accepts a current-truth packet, presentation context, previous
fragment and SEO constraints, and returns candidate copy plus diagnostics. It
cannot introduce a fact absent from the packet. The default implementation is
deterministic and Vietnamese-first. If synthesis is unavailable, the system
retains last-known-good eligible content or deterministic reader-safe content;
it never emits fabricated prose or turns unavailable into empty.

## Article, Video, Media and Dictionary boundaries

Knowledge changes affecting an Article create an enrichment/update suggestion
packet only. They never write an Article body or bypass the Article workflow.
Video `user_hint` and bounded factual observations extracted from an authorized
transcript enter the same read-only planner through an optional Video intake
seam after canonical target resolution. One observation selects only the
narrowest confidently supported subject (`specimen > variant > model >
movement/brand`); equal subjects are ambiguous and no candidate is duplicated
upward or sideways. The output is a complete `knowledge_enrichment` planning
packet only. Transcript text itself is never a Knowledge claim, and an absent
extractor produces `TRANSCRIPT_FACT_EXTRACTION_UNAVAILABLE` rather than a
fabricated transcript claim. `USER_HINT`/transcript provenance is retained,
YouTube metadata remains source input, and generated editorial text is never
Evidence. At this phase Video does not resolve/create Source; no Source ID is
invented, and `add_evidence` is proposal-ready only when canonical source ID
and revision are supplied. Planner/extractor failure is diagnostic and
fail-closed for enrichment without losing the Video intake result. The seam
never submits, approves or applies a Knowledge proposal, writes
Knowledge/Evidence directly, or creates a Graph predicate. Media annotations
remain outside this Video slice; `MediaUsage`/`depicts` alone is not Evidence.

When Video intake receives an already-validated explicit `about` relation, that
canonical target is preserved as the enrichment subject before text matching.
The planner must not broaden an explicit Variant to Model/Brand merely because a
title or hint also matches a broader name. This is a target-handoff invariant,
not a new inference rule; absent an explicit target the normal narrowest,
fail-closed resolution still applies.

Dictionary detection may run alongside Article/Knowledge/Media/Video workflows,
but it is lexical only. Research/preflight uses read-only Dictionary preview.
After a canonical Knowledge or Video write, or after an actual WordPress
Article/Media save, the Dictionary observer may idempotently store a lexical
Mention or private Candidate. That post-write observation is non-blocking and
never rolls the canonical write back merely because lexical curation is
unavailable.

For Media/Image, caption, alt, filename, OCR or recognition may produce only
bounded lexical observations/candidates. For Video, title, description, tags
and an authorized transcript may do the same. None of these signals becomes
Knowledge/Evidence or a Graph relation through Dictionary. An explicit Video
semantic target may be passed as context for disambiguation but must never be
broadened by the lexical detector.

A Dictionary review that discovers a real new fact hands that fact to this
Living Knowledge planner; Dictionary curation itself does not write the claim.
Likewise, attaching a colloquial/technical/phonetic label to an existing
Dictionary concept is not proof that a semantic entity relation exists.

Media → Living Knowledge and Article → Living Knowledge write integration are
not implemented by the completed Video slice. Future adapters may emit planning
or update-suggestion packets only until their own governed boundary is reviewed.
Image OCR/recognition, MediaUsage, `depicts`, Article body text and generated copy
remain candidate inputs at most; none is Evidence or canonical truth merely by
existing in Media/WordPress storage.

Semantic apply remains `Proposal → Human Approval → Eligibility → Controlled
Apply → canonical repository → audit → read-back`. Same-intent repeats are
idempotent and produce no duplicate claim, Evidence or relation.

## Governed apply boundary

The effective operation vocabulary is read from the current runtime catalog
(`McpToolCatalog::governedOperations()`); this slice does not introduce an
operation registry or an adapter. Knowledge claim and Evidence creation use a
registered `create`/`ingest` operation selected from that vocabulary. If no
corresponding operation is registered, proposal translation returns typed
`REGISTRY_GAP`; unsupported candidate classifications return `UNSUPPORTED`.

Evidence candidates are proposal-eligible only after canonical `claim_id` and
`source_id` resolution. Their structured contract carries relation,
excerpt/observation, optional locator, metadata and claim/source revision
closure. Unresolved source input remains an `ambiguous` review candidate and
cannot be translated into an Evidence proposal.

Create proposals carry no existing-target revision (`expected_revision=null`).
Existing-target lifecycle proposals must carry the repository revision read at
the binding boundary; eligibility rejects a changed revision. Canonical
structured ordering binds content, dependency and idempotency fingerprints.
The factory is translation-only and performs no KnowledgeService or repository
write.

## E2E acceptance boundary

The governed apply slice is accepted only when a focused in-memory test proves
the complete sequence `candidate → factory → proposal → submit/review/binding →
approve → eligibility → ControlledApplyService → AuthorityProposalExecutor →
KnowledgeService → canonical repository read-back → audit`, including replay of
the same idempotency binding. The same test must cover `new_claim` and Evidence
relations `supports`, `qualifies` and `contradicts`, reject a changed dependency
after approval, and prove failure atomicity (no semantic mutation or applied
proposal after a controlled failure, with a durable failed attempt).

The approval record binds the complete proposal binding fingerprint, which
includes content and dependency fingerprints. Integration evidence is a
separate gate and may only be reported when the exact guarded `nhk_v3_test`
runtime is available. If unavailable, the result is `ENVIRONMENT_BLOCKED`, not
PASS.

## Acceptance and non-goals

Reference corpora used in focused tests remain acceptance/reference data only
and do not mutate production/demo semantic state.

The focused in-memory E2E acceptance closes the implementation code gap for the
approved Living Knowledge slice. It does not claim database/runtime verification
when the exact WordPress test runtime is unavailable.

Dictionary lexical implementation has its own acceptance criteria in
`DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`; it cannot be used to claim Living
Knowledge semantic acceptance, and vice versa.

Durable public identity remains a separately reported storage/runtime concern
unless additive storage is verified in the target environment. No bulk slug
migration is part of this feature.
