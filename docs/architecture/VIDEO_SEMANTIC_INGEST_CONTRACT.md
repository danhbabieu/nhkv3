# Video Semantic Ingest Contract

> Non-normative implementation contract under the sole Constitution. The
> Constitution controls if any text conflicts.

Workflow: `YouTube URL + user hint → source resolution → snapshot → transcript
policy → NHK lookup → relation candidates → optional Knowledge enrichment
planning → optional Dictionary lexical preview → Hub classification → editorial
package → SEO projection → completeness → governed Video Proposal`.

Canonical public URL policy is `/video/{semantic-slug}-{external-video-id}/`.
Semantic slug fallback order is explicit governed NHK semantic/editorial
context; confirmed attached Brand/Model/Variant/Movement/Music context;
governed editorial title; governed user hint when allowed; and source-platform
title only as a controlled last resort. A source-platform marketing title must
not replace confirmed NHK context. URL changes are explicit Public Identity
operations; source synchronization never changes UUID or creates a duplicate
Video.

The public MCP entry point is the existing governed `nhk.video.ingest`. It may
return a single preview packet with source, editorial, Hub, relation, SEO,
warning and ambiguity information. It never approves, applies or publishes.
Dictionary-specific MCP tools are not implied by this contract and must not be
claimed unless present in the current executable catalog and fresh runtime
discovery.

Input is intentionally small: `url`, optional `user_hint`, optional
`intended_category`, optional already-resolved `intended_relations`, optional
`editorial_instruction` and optional `idempotency_key`. User hints are retained
as `USER_HINT`; they are high-value context, not Authority truth.

The source adapter is the only boundary allowed to call the external video
platform. The preferred client is its official data API with an
environment-provided key. A missing key is an explicit
configuration/unavailable warning. No HTML scraping, SSRF, arbitrary host or
transcript workaround is permitted.

The Proposal payload reuses the dedicated Video metadata boundary for the
normalized source snapshot, transcript policy, editorial package, Hub result,
relation candidates, SEO package, provenance and source-rights state. It is not
WordPress post meta and does not create a second semantic store.

When configured, `VideoIntakeService` invokes an optional read-only Knowledge
enrichment seam after canonical semantic target resolution. The seam selects
one narrowest confidently supported subject per observation in the order
`specimen > variant > model > movement/brand`; it never copies an observation
upward or sideways. Equal candidates are ambiguous and produce no
proposal-ready candidate. Brand-only context does not infer a Variant.

If `intended_relations` contains an already-validated explicit `about` target,
that canonical target is authoritative for both the Video attachment candidate
and the enrichment subject. The planner must preserve its canonical UUID/type
before any broader title/description/user-hint matching. This is preservation of
an explicit resolved target, not permission to infer a Variant from Model-only
text. Multiple conflicting explicit targets remain ambiguous and fail closed.

Its output is the bounded `knowledge_enrichment` packet with `status`,
`subject`, `candidates`, `diagnostics`, `proposal_ready` and
`unresolved_reasons`. Each candidate exposes `classification`, `subject_id`,
`facet`, `scope`, `observation`, provenance summary and `proposal_ready`.
`same_claim` and ambiguous/unresolved candidates are never proposal-ready.
`new_claim` is proposal-ready only after the shared planner has resolved its
dependencies; `add_evidence` additionally requires canonical `source_id` and
`source_revision`.

Transcript text is source material, not an atomic Knowledge claim. An approved
read-only factual-observation extractor must return bounded observations with
provenance/locator. If no extractor is configured, the packet emits
`TRANSCRIPT_FACT_EXTRACTION_UNAVAILABLE` and creates no transcript candidate;
extractor failure is diagnostic and does not fail Video intake. Generated
editorial text is never passed to the Knowledge planner or represented as
Evidence.

At this phase Video does not resolve or create a canonical NHK Source entity.
The intake therefore passes no invented Source ID. If a future caller supplies
canonical `source_id` plus `source_revision`, the shared planner may produce a
proposal-ready `add_evidence` candidate. Without that canonical binding,
existing-claim evidence remains `same_claim`/review-only and the packet records
`SOURCE_RESOLUTION_NEEDED`; Video intake does not create Source or Evidence.

The seam is planning-only: it does not call Knowledge/Evidence repositories,
submit or approve proposals, apply mutations, or create Graph predicates. A
planner failure is diagnostic and fail-closed for enrichment while preserving
the complete Video intake preview and its existing `about` relation/proposal
flow. Same-claim and add-Evidence idempotency remain governed by the shared
Knowledge planner/factory; Video intake does not apply either result.

## Dictionary lexical integration — 2026-09-05

Dictionary behavior follows
`docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`.

- Video title, source description, tags, user-supplied lexical hints and only an
  authorized transcript may be inspected for lexical terms.
- Dictionary preview is read-only. It may report resolved labels, ambiguity and
  review candidates but must not write Candidate/Mention rows during Video
  intake preview.
- After the governed Video canonical create/update succeeds, a non-blocking
  Dictionary observer may persist idempotent Mention/Candidate rows from the
  stored Video metadata/text. Lexical observation failure never turns a
  successful canonical Video write into semantic failure.
- An explicit validated `about` target may be supplied as context to
  disambiguate a term, but Dictionary must not broaden, replace or manufacture
  that target.
- Video metadata, transcript text and generated editorial copy are never
  Evidence merely because Dictionary recognized a term.
- An existing approved lexical label/current canonical owner is reused. An
  unresolved term becomes a private review candidate, never a public concept
  automatically.

Same external identity plus same intent is idempotent. Existing identity means
reconcile/update candidate, never duplicate Video. Source changes require a
new governed review packet; NHK fields are not overwritten by source metadata.

## Verified target-handoff checkpoint — 2026-09-04

Focused/unit implementation and the runtime smoke path distinguish target
resolution from textual research. A validated explicit Variant UUID is retained
as both `about` target and `knowledge_enrichment.subject`; the candidate scope
remains `variant` and no Model/Brand fallback is emitted. This checkpoint
changes no semantic data and does not relax the separate Source/Evidence or
Governance gates.

## Governed dependency lifecycle

The coordinated ingest path is `Source → Knowledge Claim → Evidence → Video →
about target`. Every node uses `create/ingest → submit → review/approve under
the current approval policy → eligibility → Controlled Apply → canonical owner
read-back`. Orchestration never approves on behalf of a required human/manual
policy and never writes a domain repository directly. `proposal_id` remains
separate from `target_uuid` and `canonical_id`; a create/ingest response has
`canonical_id: null` until apply and verification complete.

Controlled Apply's `result_entity_uuid` is only a candidate result. Success and
dependency progression require an internal canonical snapshot matching entity
type, UUID, active state and revision. Read-back failure is non-success and
fail-closed. Retries reuse idempotency, content and dependency fingerprints.
