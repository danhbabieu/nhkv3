# Video Semantic Ingest Contract

> Non-normative implementation contract under the sole Constitution. The
> Constitution controls if any text conflicts.

## Generic lifecycle and uncertain mutation law — 2026-09-23

Transport outcome is not canonical outcome. A mutation response is
`SUCCESS_WITH_READBACK`, `FAILED_CONFIRMED` or `OUTCOME_UNKNOWN`; an empty or
malformed response after dispatch remains `OUTCOME_UNKNOWN` until canonical
reconciliation. Recovery reuses the original idempotency identity, Capture,
normalized external Video identity and owner when present; it never creates a
second Capture or idempotency key merely because transport lost the response.

Owner creation, canonical owner read-back, editorial optimization, SEO/public
identity and publication are distinct phases. Optimization consumes the
canonical owner after read-back and controlled update, and publication requires
canonical plus public read-back. Optional enrichment is not a universal owner
gate. The public composer consumes reader-safe context; internal identifiers,
diagnostics and workflow vocabulary remain machine context. Generated
editorial prose never becomes Knowledge automatically.

Composition repair is bounded: compose, full quality, structured findings,
repair, regenerate dependent projections and full revalidation. The next
quality pass reads the repaired package, not a stale pre-repair package.

Public enrichment accepts only rows whose semantic role is reader-safe. Roles
`EDITORIAL_CONTEXT`, `INTERNAL_ORCHESTRATION`, `INTERNAL_IDENTIFIER`,
`PROVENANCE_METADATA` and `COMPLIANCE_METADATA` remain machine context and are
excluded before title, summary, body or SEO composition; factual content and
legitimate technical-domain language remain eligible. This boundary is
generic and is not a phrase blacklist.

Existing-Capture retry rehydrates the persisted source/request identity. A
caller may supply an equivalent source payload for compatibility, including a
normalized equivalent YouTube URL, but a changed semantic text, metadata or
external Video identity remains `CAPTURE_RETRY_PAYLOAD_NOT_ALLOWED`. The
current documentation checkpoint, retry mode and child selection are
execution context and do not rewrite the historical Capture request.

Workflow: `YouTube URL + user hint → source resolution → snapshot → transcript
policy → NHK lookup → relation candidates → optional Knowledge enrichment
planning → optional Dictionary lexical preview → Hub classification → editorial
package → SEO projection → completeness → governed Video Proposal`.

After the governed Video ingest is applied and the canonical Video is read back,
the universal MCP reconciliation is mandatory: canonical search →
neighborhood/Graph inspection → duplicate/reuse analysis → relation candidate
discovery → evidence/provenance validation → apply every justified useful
registered relation → final read-back. A preview, proposal or source snapshot
is not `COMPLETE`; completion also requires duplicate check, semantic research,
relation reconciliation and final verification. Weak/speculative relations are
not created merely to maximize edge count.

## Subject packet and contradiction gate — 2026-09-17

An exact canonical UUID remains the selected identity, but it is not a reason
to discard other explicit user/title hints. The resolver must validate those
hints against the UUID's bounded type, parent and family compatibility. A
different exact identity in the same registered type, or an incompatible
classification family, returns `SUBJECT_CONFLICT_REVIEW_REQUIRED`; no draft,
Video child, Knowledge claim, Graph edge or public projection is written from
that run. The selected subject packet is immutable across Capture, Video,
relation planning and enrichment; diagnostic candidates never silently replace
it.

Operational instructions such as reusing an existing Video, not creating an
Article, changing a semantic target or assigning a Hub classification remain
`non_semantic_context` with an explicit instruction class. They are not
Knowledge claims or Evidence.

An approved correction reuses the existing Video UUID. Its relation delta is
computed against active Graph state: stale registered edges are retired and a
new target is created or a retired target reactivated only after canonical
Evidence validation. Editorial subject identity and durable Graph relation
support remain separate gates.

## Single entry point for new Video submissions — 2026-09-09

New Video input is carried by the registered Video adapter of
`nhk.capture.ingest`. The adapter preserves Video's external-reference identity
and produces a governed Video proposal/review packet; it does not create a
second Video owner or a duplicate Article. The standalone `nhk.video.ingest`
surface remains internal/admin lifecycle compatibility and is not the normal
operator entry point.

Canonical public URL policy is `/video/{semantic-slug}/`; the external video ID
remains internal identity metadata and is not a default public slug suffix.
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

The reconciliation preserves provenance classes
`OBSERVED_FROM_MEDIA`, `EXPLICIT_USER_KNOWLEDGE`, `CATALOG_SUPPORTED`,
`EXTERNAL_RESEARCH` and `SYSTEM_INFERENCE`. User hints, source metadata and
observations remain scoped; they are not universal facts without supporting
evidence.

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

When Video is a child of `nhk.capture.ingest`, the Capture resolution packet is
the primary subject handoff. Video enrichment may inspect broader nodes as
bounded research context, but it must not downgrade a resolved Variant to a
broader Model because of title or hint matching. Existing Video identity is
reused by canonical platform plus external video ID before any governed ingest
proposal. Missing transcript or semantic attachment remains an explicit
diagnostic/review state and does not authorize an unscoped relation.

Its output is the bounded `knowledge_enrichment` packet with `status`,
`subject`, `candidates`, `diagnostics`, `proposal_ready` and
`unresolved_reasons`. Each candidate exposes `classification`, `subject_id`,
`facet`, `scope`, `observation`, provenance summary and `proposal_ready`.
`same_claim` and ambiguous/unresolved candidates are never proposal-ready.
`new_claim` is proposal-ready only after the shared planner has resolved its
dependencies; `add_evidence` additionally requires canonical `source_id` and
`source_revision`.

## Editorial enrichment and content gate — 2026-09-12

Video owner validity is independent from presentation enrichment. A canonical
Video with a validated provider reference, required subject/relation,
provenance and Governance/read-back may remain usable without a thumbnail or
representative Media when no consumer contract marks that image as required.
Missing thumbnail is optional enrichment/deferred repair; corrupt, private,
identity-conflicting or explicitly required presentation dependencies still
fail closed.

Historical Video recovery uses the application-level
`VideoEditorialEnrichmentService` with one immutable
`VideoEditorialEnrichmentContext`. The context is assembled from bounded
read-back only and keeps `SPECIMEN`, `SOURCE_FACT` and `CANONICAL_CONTEXT`
separate. Direct Graph neighbors are preferred; related Knowledge and Entity
IDs are reused as references and no Knowledge writer is invoked merely to make
copy longer. Generated editorial prose is never Evidence.

The enrichment result may carry the existing editorial package fields
`title`, `summary`, `body`, `context`, `facts`, `why_this_matters`,
`related_knowledge`, `related_entities`, plus derived SEO text and a
`content_quality` packet. `VideoEditorialQualityPolicy` returns exactly
`CONTENT_COMPLETE` or `CONTENT_NEEDS_REVIEW`. The latter is emitted for
missing/trivial/cut-off copy, unsupported universal language, missing specimen
scope, unused available canonical context or missing available related
references. A Video may reach `COMPLETE_VERIFIED` only when technical
completion, `CONTENT_COMPLETE`, public completion and frontend verification
all pass. This is a derived gate; it does not create a new semantic owner or a
second persistence store.

Transcript text is source material, not an atomic Knowledge claim. An approved
read-only factual-observation extractor must return bounded observations with
provenance/locator. If no extractor is configured, the packet emits
`TRANSCRIPT_FACT_EXTRACTION_UNAVAILABLE` and creates no transcript candidate;
extractor failure is diagnostic and does not fail Video intake. Generated
editorial text is never passed to the Knowledge planner or represented as
Evidence.

The standalone Video intake preview does not resolve or create a canonical NHK
Source entity and therefore never invents a Source ID. When Video is a child of
`nhk.capture.ingest`, the Capture-owned coordinated path may instead emit
governed Source, provenance Claim and Evidence proposals from the immutable
external Video snapshot and the locked exact subject handoff. Those proposals
are applied and read back before the Video proposal is constructed; they are
not written by the Video adapter and are not a second semantic writer. If
Capture has not resolved a subject, the coordinated path records
`SUBJECT_NEVER_RESOLVED`/`SUBJECT_CONFLICT` and creates no dependency or
relation. If Capture has a valid locked subject but the source snapshot does
not support a claim about that subject, the path records
`SOURCE_DOES_NOT_SUPPORT_CLAIM`, preserves the locked packet, and creates no
provenance Claim, Evidence or Graph relation. A user subject selection is not
Evidence. Existing-claim evidence remains `same_claim`/review-only unless its
canonical source identity matches the current external Video.

The Video adapter seam remains planning-only: it does not call
Knowledge/Evidence repositories, submit or approve proposals, apply mutations,
or create Graph predicates. A planner failure is diagnostic and fail-closed for
enrichment while preserving the complete Video intake preview. The coordinated
Capture orchestrator owns the dependency sequencing and applies only through
the shared Governance lifecycle. Same-claim and add-Evidence idempotency
remain governed by the shared Knowledge planner/factory; the Video adapter
does not apply either result.

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

## YouTube pipeline hardening checkpoint — 2026-09-19

The canonical happy path is `nhk.capture.ingest`; `VIDEO` defaults to
`article_required=false`. Capture remains the immutable authorization and
orchestration parent after the final Video plan is built. The final governed
Video plan is the only semantic command source for Proposal, staging scope,
admission, eligibility and Controlled Apply. Required Source → Claim → Evidence
dependencies are read back before an Evidence-backed relation can enter the
executable scope. The canonical staging authorization owner is the server-issued
signed scope packet (issued by `StagingAcceptanceScopeVerifier` after exact
staging admission); Proposal payload persistence is its immutable transport
snapshot, not a second approval owner. Governance Proposal approval is a
separate durable approval bound to Proposal revision and binding fingerprint.
Eligibility and Controlled Apply verify the same packet from that Proposal
payload. Retry rebuilds a fresh scope from the final command and removes stale
mirrored `proposal_command_fingerprint` fields; verification recomputes the
command hash from normalized payload and dependencies rather than trusting that
mirror. Scope binding failures retain their specific machine-readable reason
instead of collapsing into `STAGING_SCOPE_NOT_APPROVED`. Historical approved
proposals are never edited; stale payloads re-enter governed reconciliation and
receive a fresh scope bound to the final command. Successful retries reuse
Capture, Video, dependency and Graph identities. Public Identity is allocated
only after canonical Video read-back.

Regression coverage includes URL identity normalization, VIDEO/no-Article intent,
explicit subject precedence, Evidence binding/order, final-plan scope and
tamper checks, stale-proposal resume, governed dependency retry, Graph
idempotency, public URL ordering and transcript-unavailable warning behavior.

| Failure | Owner | Retryable | Safe retry point | Must not do |
|---|---|---:|---|---|
| `INVALID_YOUTUBE_URL` / `SOURCE_IDENTITY_INVALID` | YouTube adapter | No | Correct URL, re-enter Capture | Create Video/source |
| `SOURCE_UNAVAILABLE` | Source adapter | Yes | Source resolution | Fabricate metadata or Evidence |
| `SUBJECT_UNRESOLVED` / `SUBJECT_CONFLICT` | Subject resolution | Review | Capture plan after explicit resolution | Substitute heuristic target |
| `EVIDENCE_REQUIRED` / `NO_SEMANTIC_ATTACHMENT` | Video relation planner / Eligibility | Review | Dependency read-back and reconciliation | Downgrade to unsupported attachment |
| `STALE_PROPOSAL` / `STALE_SCOPE` / fingerprint mismatch | Governance/Staging | Yes | Governed replacement and fresh scope | Mutate historical proposal or reuse scope |
| `REVISION_CHANGED` / `APPLY_FAILED` | Controlled Apply | Yes | Current canonical revision through Governance | Direct writer or partial publish |
| `PUBLIC_URL_OWNER_NOT_FOUND` | Public Identity | Review | Canonical Video read-back, then governed identity step | Create a fake owner |

## Canonical frontend handoff — 2026-09-07

After canonical read-back, a public-capable Video must resolve Public Identity
before the frontend emits its canonical route. The route is `/video/{slug}/`;
the external YouTube URL is never a frontend destination. Query/projection code
normalizes the current persisted source under `metadata.source` and approved
compatibility shapes in one application layer. It does not duplicate source
records or infer a second semantic identity.

### Video visual support — 2026-09-11

Video editorial or Knowledge notes may consume a VisualSupportRequirement for
an exact technical/recognition feature. A thumbnail or external video source
does not satisfy a technical-detail requirement unless its declared visual
context proves the exact subject, scope, facet, feature and intent. Missing
visual support is retained independently of Video identity and is resolved by
later canonical Media Capture/reconciliation; it is not Claim/Evidence and
does not broaden scope.

### Existing-Capture editorial resume — 2026-09-13

An explicit `resume_children=["video"]` continuation is bound to the Video
child recorded on the same Capture. The coordinator reads that canonical Video
by UUID, assembles a deterministic editorial-input fingerprint from the source
identity/revision, current user editorial delta, resolved subject packet,
selected Claim IDs/revisions and policy version, then creates a governed
`video + update` proposal against the current Video revision. It never resolves
the child by title or creates a second Video.

When the effective fingerprint is unchanged, the continuation returns a
canonical read-back with `REUSE_EDITORIAL`. When it changes, the same Video
owner receives a regenerated `metadata.editorial` package and the matching
`metadata.seo` plus `metadata.seo_projection` (`open_graph` and `video_object`)
surfaces. The update preserves the source identity, external ID, relations and
other current metadata. Completion is based on the canonical Video read-back,
not on Capture addendum persistence or a proposal response alone.
