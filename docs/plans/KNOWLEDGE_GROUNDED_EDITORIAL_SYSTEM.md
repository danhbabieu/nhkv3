# Knowledge-Grounded Editorial System — Shared Design Specification

> **Status:** Research/design only — 2026-09-22.
>
> This document is subordinate implementation guidance. It is not a second
> Constitution, does not authorize migration or semantic mutation, and does
> not change any active contract. The Constitution and executable registries
> remain authoritative.

## 1. Status and constitutional authority

The analysis follows `AGENTS.md`, `docs/constitution/READ_FIRST.md`,
`docs/constitution/NHK_V3_CONSTITUTION.md`,
`docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md` and
`docs/architecture/V3_EXECUTION_STATE.md`, followed by the active Capture,
Article, Knowledge, Graph, Authority, Media, Video, Dictionary, SEO,
Compliance, Visual Support and public-projection contracts.

The governing boundaries are unchanged:

| Owner | Remains authoritative for |
|---|---|
| WordPress native Post | Article title, body, author, dates, category, archive, search, RSS, sitemap and editorial URL |
| Authority | Canonical semantic entity identity and canonical fields |
| Knowledge | Atomic Claims and their semantic scope |
| Source/Evidence | Provenance and support/contradiction/qualification |
| Graph | Typed relations and bounded reachability |
| Governance | Durable semantic mutation and controlled apply |
| Media/MediaAsset/MediaUsage | Physical media identity, assets and contextual placement |
| Video | Canonical external-reference identity and Video editorial package |
| Dictionary | Lexical curation, labels, mentions and candidate destinations |
| SEO/Public Projection | Read-only projections of eligible canonical truth |
| Capture | Orchestration, receipts and bounded request context; never semantic truth |

No CONSTITUTION_CONFLICT was found for the target architecture. The following
would be a conflict if implemented: a second Knowledge/Graph store, treating
Graph reachability as proof, promoting generated prose or image inference to
Evidence, silently rewriting published copy, creating an Article for every
Video/Knowledge submission, or making SEO a semantic writer.

## 2. Problem statement

NHK V3 already accepts text, image and Video context and can resolve subjects,
retrieve bounded semantic context and compose editorial output. The current
behavior is not yet a single shared knowledge-grounded editorial system:

* Capture has a reusable `ClaimRetrievalEngine`, but Article research also has
  a separate inventory callback and Video has a separate enrichment/resume
  path.
* The current Article inventory reader performs bounded Graph/`about` lookup,
  then loads Claims and Evidence, but it does not expose one durable-shaped
  eligibility/selection context for every editorial profile.
* `ArticleComposer` records Claim IDs, revisions, scope, provenance and path,
  while `VideoEditorialEnrichmentService` consumes source/specimen/canonical
  text rows and related identities rather than the shared selected-Claim
  model.
* Video completion verifies required fields and semantic attachments, but
  editorial richness and Knowledge utilization are not one shared quality
  decision.
* Dictionary and SEO already provide useful preview/projection services, but
  their context is assembled independently from Article/Video composition.

Therefore a sparse input can produce a technically valid owner and still
produce thin, repetitive or weakly contextual public copy. The design target
is to make the semantic editorial context shared while preserving every
existing owner boundary.

## 3. Goals

1. Treat input as intent, context and scoped observation — never as the whole
   article or as automatic canonical truth.
2. Resolve canonical subject/topic before selecting reusable Knowledge.
3. Discover candidates through bounded, registry-driven Graph reads.
4. Separate Claim eligibility, ranking and editorial selection.
5. Preserve Claim UUID, revision, original subject/scope, provenance, Evidence
   status and explainable Graph path in a transient/read-model context.
6. Give Article, Video, Media/Image-led content, Entity/Dossier, cards, SEO and
   related-content projections one shared semantic input seam.
7. Allow AI to plan and compose reader-friendly prose only from eligible
   context; never manufacture unsupported facts.
8. Make Living Knowledge changes visible as re-enrichment candidates, never as
   silent public rewrites.
9. Keep first implementation read-model/transient and schema-neutral.

## 4. Non-goals

* No feature implementation, migration, backfill, import or production/staging
  mutation.
* No Article/Video duplicate owner and no new `Editorial Intelligence` owner.
* No new Claim type, Graph predicate, endpoint type, semantic enum or SEO truth
  store merely for this design.
* No automatic promotion of input observations, transcripts, captions, OCR,
  generated prose or visual inference to Knowledge/Evidence.
* No frontend redesign, bulk re-enrichment or automatic publication.
* No hard-coded Odo rule or Video-only special engine.

## 5. Architectural invariants

The target continues the active pipeline:

`Capture → interpret → Content Intent → resolve canonical subjects → bounded
Graph neighborhood → candidate Claims → scope/provenance/Evidence/relevance
validation → governed write-back/review when needed → Editorial Context Pack →
profile composition → SEO/public-claim gate → owner read-back → public
projection`.

Graph reachability only discovers candidates. A Claim is reusable only after
the Claim owner, subject/scope, Source/Evidence and applicable public/compliance
rules pass. A relation path is retained as explanation, not authorization.

WordPress remains the sole Article body owner. Video remains the Video package
owner. The shared layer is a read model and orchestration seam, not a durable
semantic owner.

## 6. Current AS-IS architecture

### 6.1 Concrete map

| Stage | Current executable path | Owner | Reads | Writes/output | Limitation |
|---|---|---|---|---|---|
| Capture admission | `McpCaptureIngestHandler` → `EditorialCaptureCoordinator::execute()` | Capture/orchestration | request, idempotency, documentation checkpoint | one `CaptureRecord`, phase receipts | Correct boundary, but downstream context is assembled in multiple callbacks |
| Input interpretation | `Application/Semantic/TextInputInterpreter` | Capture semantic adapter | text, assets, subject hints, metadata | candidate statements, media observations, mentions, non-semantic instructions | Deterministic extraction; topic/intent richness remains downstream |
| Content intent | `Application/Capture/ContentIntentRouter` | Capture | explicit intent, interpretation, assets | VIDEO, IMAGE_ARTICLE, TEXT_ARTICLE, KNOWLEDGE_DELTA, MEDIA_ENRICHMENT state | Correctly prevents unwanted Article creation; not an editorial profile planner |
| Subject resolution | `SubjectResolutionService`, `CanonicalAuthoritySubjectResolver`, Video subject handoff | Authority read boundary | UUID/stable key/name/alias candidates | resolution packet, ambiguity/conflict diagnostics | Capture and Video use related but distinct adapters |
| Graph neighborhood | `GraphService`, `RelatedSemanticQuery`, `SemanticNeighborhoodQuery`, `PredicateTraversalPolicy` | Graph | registered endpoints, predicates, active edges | bounded items, best/alternative paths | Max two hops and bounded limits are good; Article inventory still has an additional `about` branch |
| Candidate Claim retrieval | `ClaimRetrievalEngine`; Article `Plugin.php` inventory callback | Knowledge + Graph read | Claim repository, Graph, Evidence, Source | candidate rows, selected rows, evidence status | Shared engine is underused; Article callback and Video planner do not consume the same packet |
| Claim reuse | `ClaimReusePolicy` and `CanonicalDependencyValidator` | Knowledge | subject/scope/text, canonical repository | read-only reuse decision, dependency checks | Reuse matching is useful but separate from editorial role/selection |
| Semantic write-back | Capture semantic callback, `KnowledgeService`, Governance/Controlled Apply | Knowledge/Governance | candidates, Source/Evidence dependencies, signed scope | proposal/apply/read-back receipts | Correctly governed; not part of public prose generation |
| Article research | `ArticleResearchPreflight` plus `McpSemanticContextResolver` and Plugin inventory reader | Article/read model | Authority, Graph, Claims, Evidence, Source, Media, Video, Posts, Dictionary | `ArticleResearchResult`, links, overlap, blueprint, compliance diagnostics | Strong preflight but inventory, selection and composition are separate concerns |
| Article composition | `ArticleComposer` | WordPress Article projection/orchestration | input, observations, selected Claims, visual context | title, excerpt, body, managed sections, `claim_trace`, research snapshot, dependency fingerprint | Good traceability; prose is simple and not yet journey/profile driven |
| Article owner update | Capture draft updater; `ArticleIngestCoordinator`; `OwnerPublicationApplicationService` | WordPress/Governance | state token, preflight, proposal, rendered verification | native Post and publication receipts | Correct owner boundary; publication requires several gates |
| Video intake | `VideoIntakeService`, `VideoEditorialResumePlanner`, `VideoKnowledgeEnrichmentPlanner` | Video | source snapshot, user hint, transcript, resolved subject | Video proposal/package, enrichment metadata, attachments | Separate Video path; canonical Claims are not the shared editorial source |
| Video prose | `VideoEditorialGenerator`, `VideoEditorialEnrichmentService` | Video | source facts, specimen facts, canonical context, related rows | title, summary, body, why-this-matters, SEO description | Safe scope language, but sparse and template-like; does not consume selected Claims/roles/journey |
| Video readiness | `VideoCompletenessPolicy`, `VideoCompletenessReconciliationService`, publication verifier | Video/publication | source, editorial fields, content quality, category, attachments, SEO | blockers/warnings/readiness | Field presence is necessary but a shared information-gain/journey gate is missing |
| Media/Image | `MediaBatchIngestService`, `MediaIngestGateway`, `MediaBindingService`, `VisualOpportunityDetector`, `VisualSupportRequirementService` | Media/MediaUsage/visual ledger | physical asset, observations, target scope, usage | Media identity, asset, usage, visual requirement/readback | Observations are correctly scoped; image-led editorial context is not unified with Claim selection |
| Dictionary | `DictionaryObservationRegistry`, `ArticleResearchPreflight` dictionary planner | Dictionary | text, subject, owning source kind | preview terms, links, candidate observations | Preview is useful; persistence remains correctly tied to owner write/curation |
| SEO | `PublicSeoProjection`, `ArticleSeoGate`, `VideoSeoProjection`, Entity/Media SEO providers | SEO/public projection | eligible Authority, Claims/projections, Dictionary, routes, Media/Video | title/meta/canonical/OG/structured data/sitemap/link candidates | Read-only and safe; no shared SEO plan object with editorial context |
| Public Entity/Dossier | `PublicEntityCollectionQuery`, `EntityKnowledgeProjection`, `Public Entity Dossier` assembly, theme templates | Public projection | direct Knowledge, Evidence/Source, Graph paths, MediaUsage, Video, Article | dossier sections, related cards, public routes | Correct direct-vs-derived scope; it is not an editorial composer |
| Public frontend | theme `single.php`, `video.php`, `entity.php`, `media.php`, search/home queries | WordPress theme/projection | owner DTOs/read models | Vietnamese public HTML | Consumes existing summaries/related items; no redesign in this spec |
| Completion | `CompletionCoordinator`, Capture phase reducers, Article publication gates, Video completeness | Each owner plus Capture | canonical read-backs, receipts, compliance/readiness | COMPLETE/PARTIAL/REVIEW/BLOCKED-style receipts | Cross-domain completion is better than field-only, but editorial quality is not a single shared diagnostic |

### 6.2 Current input paths

* `TEXT_ARTICLE` / `IMAGE_ARTICLE`: Capture stores assets, interprets text,
  resolves subjects, retrieves Claims, runs governed semantic reconciliation,
  composes a native Article, reconciles MediaUsage, runs publication gates and
  reads back the Post.
* `KNOWLEDGE_DELTA`: Capture resolves the semantic target and uses the governed
  Knowledge path without creating an Article.
* `MEDIA_ENRICHMENT`: exact typed Media binding may use the documented fast
  path; it does not require NLP, Claims or Graph traversal when the binding is
  already exact and bounded.
* `VIDEO`: the current accepted architectural route preserves Video as a
  distinct owner. In the coordinator's registered adapter path, Video
  enrichment occurs after subject resolution, but the Video editorial package
  is still generated by Video-specific services and the shared Capture Claim
  retrieval does not yet become its editorial context.

### 6.3 Existing bounds and diagnostics

The executable read paths already provide useful bounds: Graph traversal is at
most two hops, relation/query limits are capped, Article inventory uses limits
for posts/Claims/relations/Evidence, Capture has an orchestration budget, and
completion retains canonical read-back/partial/review/blocker distinctions.
These bounds should be reused, not replaced with an unbounded corpus scan.

## 7. Gap analysis

### 7.1 Structural gaps

1. There is no single transient object carrying interpretation, selected Claims,
   excluded candidates, roles, journey, Dictionary context and link targets.
2. Eligibility is partly in `ClaimRetrievalEngine`, partly in Article preflight,
   partly in projection/compliance policies and partly in Video governance.
3. Ranking and selection are not explicitly separate from eligibility.
4. Article has Claim dependency traces; Video has separate fingerprints and
   enrichment metadata; Media/Image has observations and visual-support state.
5. Reader journey and information gain are not shared quality inputs.
6. SEO can consume several canonical projections but does not receive a common
   semantic/topic/keyword/link plan.
7. Living Knowledge has revision signals and Article managed sections, but no
   shared preview/diff/re-enrichment read model across profiles.

### 7.2 Quality gaps

Presence of `title`, `summary`, `body` or SEO fields is not equivalent to
grounded, useful editorial quality. `VideoCompletenessPolicy` correctly
blocks missing fields and poor `content_quality`, but the quality package must
also distinguish direct-input repetition, selected Knowledge utilization,
scope correctness, information gain, journey coherence and link usefulness.

Article's current fallback “waiting for more editorial data” is honest but
should remain an incomplete/review outcome, not a rich editorial pass.

### 7.3 Video-specific diagnosis

The thin-copy behavior is caused by architecture, not by missing prose
templates alone: `VideoEditorialGenerator` starts from source title/user hint;
`VideoEditorialEnrichmentService` receives scoped fact arrays and canonical
context; `VideoKnowledgeEnrichmentPlanner` is a planning/governance helper for
hint/transcript-derived Knowledge. None is the shared
interpret → Graph neighborhood → Claim eligibility → selection → journey
context path used by the target design. The fix is to feed Video's existing
editorial owner from the shared pack, not to create a second Video knowledge
engine.

### 7.4 Visual Support cross-cutting gap

`VISUAL_SUPPORT_REQUIREMENT_CONTRACT.md` is ACTIVE whenever editorial output
names a visually explainable technical or recognition feature. The shared
design must distinguish `Representative Media` from `feature-support Media`:
a general Odo 36 representative image does not prove or illustrate every
wall-plate variant. Feature support requires exact canonical subject, scope,
facet, registered feature key and visual intent, then reuse or binding through
the existing Visual Support Requirement, MediaUsage and MediaBinding owners.

Missing or ineligible visual support remains distinct from Knowledge missing,
Evidence missing, Media unavailable, a feature that does not require public
visual support, and deliberate projection omission.

## 8. Target TO-BE architecture

### 8.1 Composition decision

The target can be implemented primarily by composing/extending existing
services. The first new application-layer seams should be:

1. `EditorialContextPack` — transient/read-model value object, not persistence
   owner.
2. `EditorialClaimEligibility` — deterministic policy façade over existing
   Claim, Source/Evidence, scope, Graph and compliance checks.
3. `EditorialKnowledgeSelector` — bounded deterministic ranking plus an
   AI-assisted choice interface that can only choose eligible candidates.
4. `ReaderJourneyPlanner` — intent/profile-aware read-model planner.
5. `SharedEditorialQualityEvaluator` — cross-profile diagnostic evaluator.
6. `SemanticSeoPlanner` — read-only plan over canonical context, Dictionary and
   public routes.

These are application services/read models. They do not create a new database
table, endpoint, predicate, Claim type or durable owner in the first slice.
Existing `ClaimRetrievalEngine`, `ClaimReusePolicy`, `SubjectResolutionService`,
`RelatedSemanticQuery`, `ArticleComposer`, Video services, Dictionary preview,
SEO projections, Media suitability and compliance guards remain the underlying
owners.

### 8.2 Detailed data flow

1. Capture validates documentation checkpoint, idempotency and Content Intent.
2. Input Interpreter emits direct statements, media observations, mentions,
   instructions and uncertainty, each with input provenance.
3. Subject Resolution resolves canonical primary subject/topic; conflict and
   ambiguity remain review/blockers.
4. `SemanticNeighborhoodQuery`/`RelatedSemanticQuery` discovers bounded Graph
   candidates and retains best/alternative paths.
5. Claim retrieval loads Claims by canonical read port and joins Source/Evidence
   snapshots within budget.
6. Eligibility validates identity, active/revision state, direct or registered
   path, semantic scope, provenance, Evidence support, public/compliance rules
   and topic applicability.
7. Selection ranks only eligible candidates and records excluded candidates plus
   reason. AI may choose among the bounded eligible set only.
8. The Context Pack is assembled with roles, topic, journey inputs, Dictionary
   terms, link candidates and related-content candidates.
9. A profile planner produces an Article/Video/Media/Dossier/card/SEO plan.
10. The owning composer generates derived copy. Article writes only to the
    native Post; Video writes only to Video metadata/package; MediaUsage writes
    only to MediaUsage; SEO remains projection.
11. Compliance, semantic quality, owner-specific readiness, route and rendered
    read-back gates run. No field-presence shortcut may produce public
    completion.

## 9. Knowledge retrieval design

### 9.1 Candidate discovery

Use the existing `SemanticNeighborhoodQuery` profile and Graph traversal
budgets. For a resolved subject, retrieve direct and bounded derived nodes with
registered predicates. For each reachable target, retrieve Claims through the
Knowledge repository and join only bounded Evidence/Source rows. Retain:

* root subject and candidate semantic subject;
* ordered path steps (`source`, `predicate`, `target`);
* hop count and direct/derived class;
* alternative paths;
* registry/profile and result budgets;
* unavailable/partial diagnostics.

Do not scan the full Knowledge corpus for a resolved subject. The existing
Article callback's `about` lookup may remain a compatibility read, but the
shared design should prefer the path-aware Graph read and explicit Claim
eligibility policy.

### 9.2 Eligibility, ranking and selection

These are three different decisions:

| Decision | Meaning | Can it override failure? |
|---|---|---|
| Eligibility | Whether a candidate may be reused at all | No |
| Ranking | Relative usefulness among eligible candidates | Never |
| Editorial selection | Which eligible candidates serve this profile/role | Never |

Eligibility must check: canonical Claim ID and revision; active state; original
semantic subject; subject/scope compatibility; registered path; Source/Evidence
eligibility; provenance; relevance to the topic/intent; public/compliance
restrictions; duplicate/topic-drift/uncertainty constraints. A specimen Claim
never generalizes to Variant, Model or Brand merely through reachability.

Ranking may consider topic relevance, proximity, reader usefulness, information
gain, explanatory value, semantic SEO value, duplication, topic drift and
uncertainty. The numerical ranking is editorial ordering only, never confidence,
truth or Evidence.

AI-assisted selection receives an allowlisted candidate set and returns IDs and
roles only. The server revalidates every ID/revision and rejects unknown,
ineligible, stale or out-of-budget choices.

## 10. Editorial Context Pack

The pack is a transient value object or serialized diagnostic/read-model packet
inside Capture/Article/Video planning. It must not be a semantic truth store.

Suggested shape:

```text
EditorialContextPack {
  pack_revision, capture_id, content_intent, topic, primary_subject,
  input_facts[], input_observations[], uncertainty[],
  candidate_claims[], selected_claims[], excluded_candidates[],
  supporting_entities[], supporting_concepts[],
  dictionary_context, internal_link_candidates[], related_content[],
  visual_support_requirements[], eligible_feature_media[],
  unresolved_visual_support[], visual_support_paths[],
  support_role_feature_map[],
  journey_plan, profile_inputs, diagnostics, dependency_snapshot
}
```

Each candidate/selected Claim carries:

```text
claim_id, claim_revision, claim_text_for_internal_planning,
original_subject {type,id}, subject_scope, scope_compatibility,
source_ids/revisions, evidence_ids/revisions, evidence_status,
provenance, graph_path[], graph_path_explanation, hop_count,
eligibility, ranking, selection_reason, editorial_role,
uncertainty, exclusion_reason
```

The pack may carry direct facts and observations, but must preserve their
provenance (`EXPLICIT_USER_KNOWLEDGE`, `OBSERVED_FROM_MEDIA`, or other existing
controlled class) and scope. It must not flatten them into canonical Claims.

The pack may carry semantic keywords/topic context and link candidates as
derived projections. Dictionary concepts remain lexical state; link targets
must be public-route eligible and owner-correct.

### 10.1 Feature visual-support flow

`selected feature Claim → VisualSupportRequirement lookup/reconciliation →
exact Media scope/facet/feature/intent validation → eligible MediaUsage or
MediaBinding reuse → feature-support path → quality/readiness decision →
public projection`.

The pack records requirements and support paths as read-model context only.
`VisualSupportRequirementService` remains the application ledger and
`MediaBindingService` remains the canonical Media → MediaUsage → exact target
owner. No new Media, Evidence or visual-truth store is created. An image
observation may explain why a requirement was detected, but cannot prove a
Claim or broaden its scope.

Reverse reconciliation starts from canonical Media read-back and searches only
bounded missing/review requirements, validating exact subject/scope/facet/
feature/intent before reuse. A later unsuitable/private/placeholder asset,
changed MediaUsage or changed Claim scope invalidates applicable support/readiness
projection without deleting Media or silently rewriting public copy.

Public projection selects only a public-safe eligible derivative. Missing,
private, review-required, unavailable, placeholder or mismatched feature Media
is omitted or keeps the visual expansion qualified/review-required.

## 11. Knowledge selection design

Selection roles are read-model labels, not new semantic vocabulary:

`CORE`, `CONTEXT`, `EXPLANATION`, `IDENTIFICATION`, `COMPARISON`, `HISTORY`,
`CURIOSITY`, `SEO_SUPPORT`, `NEXT_STEP`.

Represent them as code constants/value objects inside the application planner
only if validation and deterministic serialization are needed. Do not persist
them as Claim types or Graph predicates. A first implementation may keep them
as allowlisted string labels in the transient pack.

Selection rules:

* CORE: directly answers the input topic and passes exact scope.
* CONTEXT: gives the minimum canonical background needed to understand CORE.
* EXPLANATION/IDENTIFICATION: clarifies terminology or canonical identity with
  path and Evidence intact.
* COMPARISON/HISTORY: only when the topic and eligible claims support it.
* CURIOSITY: useful adjacent fact with a bounded path, never filler.
* SEO_SUPPORT: lexical/topic support only; never a reason to weaken eligibility.
* NEXT_STEP: public-route eligible related Entity/Article/Video/Media target.

Exclude duplicate claims, claims outside subject scope, unsupported rankings or
superiority, unrelated facts, claims with unresolved identity, and candidates
whose only support is an `about`/`depicts` path or lexical overlap.

## 12. Reader Journey design

`ReaderJourneyPlanner` consumes intent, profile, topic, selected roles and
available evidence. It returns an ordered abstract plan, not mandatory prose:

```text
opening_context → curiosity/question → core_topic → explanation/identification
→ useful_expansion → next_knowledge
```

Profiles can omit or reorder stages. A technical identification input may start
with the subject and move directly to core/explanation; a broad discovery input
may use context first. The planner must cap repeated claims, avoid system/legal
language in visitor copy, and return `REVIEW_REQUIRED` when the selected set
cannot support a coherent journey.

## 13. Editorial profiles

| Profile | Persisted text? | Owner | Shared pack use |
|---|---:|---|---|
| Article | Yes | WordPress Post | compose body/title/excerpt; store existing trace/managed sections only |
| Video | Yes | Video metadata/editorial package | replace Video-specific fact assembly with shared selected context |
| Image/Media-led Article | Yes for Article; MediaUsage metadata separately | WordPress/MediaUsage | use observations for scope, Claims for context, Visual Support for exact feature |
| Entity/Dossier | No new prose owner | Public projection | project direct claims and approved relation context; no article composition |
| Card/feed summary | Derived | owning public query | bounded summary from owner package and selected eligible context |
| SEO | Derived | SEO projection | title/meta/questions/links/structured data from canonical eligible context |
| Related/internal links | Derived | Graph/Dictionary/route projection | route-eligible targets with path/reason, no new relation |

Generated text remains derived from the owning content boundary. Only existing
Article/Video/MediaUsage owners persist their respective editorial fields.

## 14. Semantic SEO design

`SemanticSeoPlanner` should be read-only and consume:

* primary canonical subject and topic;
* resolved intent/search intent;
* selected eligible Claims and supporting concepts;
* Dictionary lexical variants and question intents;
* public-route-eligible internal-link candidates;
* related Article/Video/Entity/Media targets;
* overlap/cannibalization diagnostics from Article research.

It may produce a blueprint: title/H1 intent, meta intent, topic cluster,
question candidates, internal links, related targets, structured-data inputs and
indexability expectations. It must not invent facts, create slugs, create
Claims/relations, or outrank canonical public eligibility. Existing SEO Core,
Article SEO, Video SEO, Dictionary, route and sitemap contracts remain the
projection law.

## 15. Quality and compliance design

`SharedEditorialQualityEvaluator` should return a diagnostic read model with
separate dimensions, without inventing a persistent enum:

* `FACTUAL_GROUNDING` — prose traces to input or eligible canonical context;
* `SCOPE_CORRECTNESS` — subject, facet and Claim scope are not broadened;
* `EVIDENCE_ELIGIBILITY` — Source/Evidence and provenance are eligible;
* `VISUAL_SUPPORT_READINESS` — exact feature support is resolved or explicitly omittable;
* `EDITORIAL_QUALITY` — usefulness, information gain, journey and readability;
* information gain beyond direct input;
* reader journey coherence;
* readability;
* repetition/template-language detection;
* semantic coverage;
* `SEO_READINESS` — route, lexical, structured-data and asset inputs are eligible;
* internal-link usefulness;
* public-claim compliance.

Keep these states distinct:

* semantic completion — canonical semantic dependencies/read-back;
* editorial completion — owner package has a coherent grounded result;
* SEO readiness — projection inputs/routes/assets are valid;
* `PUBLIC_READINESS` — all applicable owner, visual, compliance, rendered and
  route gates pass.

These names are conceptual/read-model dimensions, not new persistent enums.
Visual support diagnostics must distinguish `KNOWLEDGE_MISSING`,
`EVIDENCE_MISSING`, `MEDIA_UNAVAILABLE`, `VISUAL_SUPPORT_NOT_REQUIRED`,
`VISUAL_SUPPORT_REVIEW_REQUIRED`, `VISUAL_SUPPORT_OMITTED_BY_PROJECTION` and
`VISUAL_SUPPORT_READY` without creating a new semantic owner.

Do not treat `COMPLETE` in one dimension as completion in another. Existing
`CompletionCoordinator`, Article publication gates, Video completeness and
public-claim policy remain the final owners of lifecycle decisions.

Generated copy must pass the existing public claim/compliance guard. Unsupported
superiority, rarity, popularity, chronology, provenance or technical facts are
blocked or narrowed only through an evidence-bound policy decision.

## 16. Living Knowledge and re-enrichment

The first implementation should extend/combine existing dependency evidence,
not create a duplicate dependency owner. Article already has `claim_trace`,
`research_snapshot`, managed-section dependency fingerprints and composition
revision. Video existing-Capture editorial resume already fingerprints source
identity/revision, user editorial delta, resolved subject, selected Claim
IDs/revisions and policy version. That Video mechanism remains the Video
owner's dependency mechanism; the shared pack supplies the same canonical
inputs and may contribute a deterministic fingerprint component, but does not
replace Video metadata or create a second Video dependency store.

Article, Image-led and other profiles may eventually apply the same principle:
canonical subject/revision + selected Claim IDs/revisions + visual-support
requirement/Media revisions + Dictionary/SEO policy version → deterministic
recomputation/fingerprint. Start with read-time recomputation and existing
owner traces before considering a new persisted dependency schema.

When a Claim revision changes:

`revision change → dependent owner detected → re-enrichment candidate → preview
diff → owner review/confirmation → existing owner update/publication workflow →
canonical/rendered read-back`.

No public body, Video package or SEO output is silently rewritten. The preview
must identify old/new Claim IDs/revisions, changed roles/paths/scope and the
proposed copy/SEO diff without treating generated prose as Evidence.

Do not add a cross-domain dependency table in the first slice. A deterministic
read model over existing traces/fingerprints is safer. A later persisted index
would require a separate contract, owner decision, retention policy and
constitutional review.

## 17. Public copy boundary

No reader-facing title, summary, body, excerpt, SEO description, Open Graph
copy, VideoObject description, card copy or related-content description may
contain internal diagnostics, workflow tokens, canonical UUIDs, stable keys,
Governance terminology, MCP terminology or Graph/Evidence implementation
jargon unless those terms are genuinely the editorial subject matter.

Machine-readable provenance, Claim IDs/revisions, paths, requirement states and
diagnostics remain machine-readable in owner metadata/read models. They are not
reader-facing disclosure. Public composition uses natural Vietnamese-first copy
and the existing compliance/public-copy guards.

## 18. Ownership and write-boundary table

| Operation | Shared layer may do | Shared layer may not do |
|---|---|---|
| Resolve subject | read Authority and return candidates/ambiguity | create/merge/repair Authority |
| Discover Graph context | bounded read and explainable paths | create edges or treat paths as truth |
| Retrieve Claims | read Claim/Source/Evidence snapshots | create a second Knowledge store |
| Select context | rank/select eligible candidates | promote ineligible facts or write Claim types |
| Compose Article | provide context to `ArticleComposer` | persist a second body or write generic Post directly |
| Compose Video | provide context to Video owner services | create duplicate Video/Article or bypass Governance |
| Enrich Media | provide observations/context and exact Visual Support inputs | turn inference into Evidence or create duplicate binary |
| Plan SEO/links | derive route-eligible projection candidates | create truth, slug, relation or fallback route |
| Re-enrich | propose preview/diff and call existing owner workflow after approval | silently rewrite public content |
| Complete | aggregate diagnostics and owner read-backs | infer quality from field presence or ingest success |

## 19. Impact/dependency matrix

| Area | Current responsibility | Proposed change | Risk/owner | Code expected? | Schema/migration? | Tests |
|---|---|---|---|---:|---:|---|
| Capture | orchestration and receipts | assemble/reuse shared pack | Capture must remain non-owner | Yes | No | stage order, idempotency, partial/review |
| Authority | identity/resolution | expose one resolver seam | ambiguity/merge risk | Small adapter | No | UUID/stable/name conflict |
| Knowledge | Claims | expose revision/scope/evidence read DTO | stale/unsupported reuse | Yes | No | scope, revision, evidence |
| Source/Evidence | provenance/support | common eligibility input | privacy/public mismatch | Small adapter | No | active/public/private/support |
| Graph | traversal/relations | path-aware neighborhood input | overreach/limits | Reuse/adapter | No | 2-hop, registry, alternate paths |
| Dictionary | lexical preview | add pack projection | lexical truth confusion | Small adapter | No | candidate/ambiguous/link ownership |
| Article | research/composition | consume pack and journey | WordPress ownership | Yes | No | claim trace, body, publication gates |
| Video | package/readiness | consume pack, remove duplicate semantic assembly | Video scope/revision | Yes | No | sparse input, governed update, SEO |
| Media/Image | observations/usage | consume pack without broadening scope; resolve exact feature support | visual inference becomes fact; representative image is mistaken for feature support | Yes | No | observation vs Evidence, Visual Support, reverse reconciliation |
| SEO | projections | consume shared plan | invented/stronger copy | Yes | No | canonical route, claims, links |
| Compliance | public claim checks | evaluate every profile | legal/public drift | Reuse | No | unsupported superiority/scope |
| Governance | durable mutation | unchanged; gate selected write-back | bypass risk | No/adapter only | No | signed scope, CAS, idempotency |
| Frontend/Public | render owner projections | consume richer existing DTOs only | stale/template copy | Later projection adjustments | No | public route/empty/error |
| MCP/Admin | Capture/direct guarded boundaries | expose no new writer by default | client exposure gap | Contract/runtime tests | No | registry parity/fail closed |
| Testing | domain/contract suites | add shared golden fixtures/property cases | false COMPLETE | Yes | No | matrix below |
| Documentation | contracts/status/execution state | add reviewed contract/spec and checkpoint | law drift | This document only now | No | documentation manifest/path checks |

## 20. Failure modes and fail-closed behavior

* Missing/stale documentation checkpoint: reject Capture as existing
  `DOCUMENTATION_CHECKPOINT_REQUIRED/STALE`.
* Ambiguous/conflicting subject: `REVIEW_REQUIRED`; do not retrieve broad
  Knowledge as if identity were resolved.
* Graph unavailable/unsupported profile: preserve partial/unavailable status;
  never substitute an unbounded corpus scan.
* Claim missing ID/revision/text, invalid path, incompatible scope, weak/missing
  provenance or ineligible Evidence: exclude or review; never include from rank.
* AI returns an unknown/stale/ineligible Claim: reject selection and require
  deterministic revalidation.
* Dictionary unavailable: keep editorial truth intact; omit lexical links and
  report `UNAVAILABLE`/warning according to existing contract.
* Public route or asset unavailable: omit target/asset and keep honest
  incomplete/readiness diagnostics.
* Quality dimensions incomplete: no `public-ready` inference from owner write.
* Claim revision drift: re-enrichment candidate/preview; no silent rewrite.
* Video sparse context: use shared eligible pack or remain review/incomplete;
  do not fill with unsupported generic facts.
* Generated prose/every visual observation: never become Evidence automatically.
* Generic representative Media for a specific feature: reject as feature support
  unless exact subject/scope/facet/feature/intent validation passes.
* Visual Support missing while Knowledge/Evidence is otherwise eligible: keep
  factual Claim state separate; omit or qualify only the visual expansion.
* Visual Support is not required for the feature/profile: record that explicit
  state rather than reporting Media unavailable.

## 21. Observability and diagnostics

Use existing status vocabulary and diagnostics where possible. New diagnostics
should be read-model codes, not persisted domain enums, until a contract proves
durability is necessary. Every pack/plan should expose bounded metadata:

* input/capture/profile and elapsed phase timings;
* subject resolution status and diagnostics;
* Graph profile, hops, result budget and availability;
* candidate/eligible/selected/excluded counts;
* exclusion reasons by policy category;
* Claim IDs/revisions and dependency fingerprint, not private full evidence;
* visual-support requirement state, exact feature mapping, Media/MediaUsage
  revisions and bounded support path;
* journey/profile/quality dimension results;
* Dictionary and route availability;
* compliance/readiness blockers and owner read-back state.

Do not return complete private Source/Evidence payloads, secrets, signatures or
unbounded prose in diagnostics.

## 22. Testing strategy

### 21.1 Contract/unit tests

* `EditorialContextPack` deterministic serialization, no semantic owner fields.
* Candidate eligibility rejects wrong subject scope, specimen-to-parent
  generalization, missing Evidence, missing provenance, unregistered paths and
  `about`-only applicability.
* Ranking cannot select an ineligible high-score candidate.
* Selection revalidates AI IDs/revisions and rejects stale/unknown choices.
* Graph paths preserve direct/derived class, alternative path and bounds.
* Visual Support resolves exact feature scope and never treats a generic
  representative image as support for every feature.
* Reader journey is deterministic, role-aware, capped and profile-specific.
* Quality distinguishes semantic, editorial, SEO and public readiness.
* Compliance blocks unsupported superiority/rarity/popularity/chronology.

### 21.2 Integration/owner tests

* Capture uses one pack for Article and Video without creating duplicate owners.
* Article retains native Post ownership and existing claim trace/read-back.
* Video consumes selected canonical Claims while preserving Video identity,
  governed CAS, idempotency and `VideoCompletenessPolicy`.
* Media observations remain scoped and Visual Support does not create Claim or
  Graph truth.
* Visual support states distinguish Knowledge/Evidence/Media gaps, not-required
  support and deliberate public omission.
* Dictionary/SEO/link projections omit unavailable/private/ineligible targets.
* Claim revision produces preview/review-required re-enrichment, never a silent
  write.
* Ingest success, draft creation or field presence alone cannot produce public
  completion.

### 21.3 Regression corpus

Use sparse, ambiguous, unsupported, stale, duplicate, topic-drift, private
Evidence, Graph-unavailable, Dictionary-unavailable, no-media and no-transcript
fixtures. Include Article, Video, image-led Article, Knowledge-only and
MEDIA_ENRICHMENT intents. Do not seed or mutate development/production data.

## 23. Golden Acceptance Case — “3 phiên bản vách máy của đồng hồ Odo 36”

This is a behavior fixture, not an implementation special case. No code may
mention or branch on Odo 36.

Expected design behavior:

1. Resolve the canonical Odo 36 subject; ambiguity blocks.
2. Resolve/reach the canonical movement subject only through registered,
   explainable Graph paths.
3. Retrieve bounded Claims about the movement/back-plate topic.
4. Select vách cam, vách xoáy and máy 3 vách only when Claim scope,
   provenance and Evidence are eligible.
5. Add broader Odo 36 context only if it increases comprehension.
6. Add vách sọc/vách hở only if separately eligible and useful.
7. Exclude arbitrary dimensions/case details when unrelated to the topic.
8. Produce a natural context → topic → explanation → next-knowledge journey.
9. Build semantic SEO context from eligible concepts/Dictionary/routes.
10. Reject invented popularity, rarity, chronology, superiority or technical
    facts.
11. Mark selected vách cam, vách xoáy, máy 3 vách, vách sọc or vách hở Claims
    as visually explainable when the applicable feature policy says so.
12. Resolve `VisualSupportRequirement` for each selected feature, reuse exact
    suitable Media where available, and retain the support role/feature mapping.
13. Do not use a generic Odo 36 representative image as proof of every
    wall-plate variant.
14. Let the composer omit or qualify a visual expansion when support is
    missing/ineligible while preserving the factual Claim decision.
15. Produce natural public copy with no internal diagnostics, IDs or workflow
    language.
16. Produce semantic SEO only from eligible subject, Claim, Dictionary, route
    and Media context.
17. Preserve Claim UUID/revision, policy version, visual-support dependency and
    other existing owner fingerprint inputs for deterministic re-enrichment.
18. Do not add an Odo 36 special case.

If the canonical movement/Claim records are absent or unavailable, the result
must clearly say the topic is incomplete/review-required rather than inventing
the expected terms.

## 24. Rollout checkpoints

These are design checkpoints only; each requires a fresh contract/status review,
tests, PHP lint, `git diff --check`, secret review and execution-state update
before any code checkpoint.

1. **Contract/design baseline:** approve pack shape, diagnostic vocabulary and
   owner matrix; no runtime write.
2. **Shared retrieval seam:** adapt existing Graph/Claim/Source/Evidence reads;
   prove bounds and path retention.
3. **Eligibility and selection:** deterministic policy first; AI selection only
   inside the eligible candidate set.
4. **Context Pack:** add transient/read-model assembly and Capture diagnostics.
5. **Journey/composition core:** plan roles and journeys; preserve Article
   Composer and existing managed-section ownership.
6. **SEO/link plan:** consume canonical/Dictionary/route projections only.
7. **Shared quality gate:** add dimensioned diagnostics and distinguish states.
8. **Article integration:** consume the pack in existing Article flow; no body
   migration or publication shortcut.
9. **Video integration:** adapt existing Video generator/resume owner to the
   pack; no Odo branch and no second engine.
10. **Media/Image integration:** consume observations/Visual Support and exact
    scope; no Evidence promotion.
11. **Living re-enrichment preview:** compare existing traces/fingerprints and
    route review through current owner workflows.
12. **Public projection review:** adjust DTO consumers only after richer owner
    output passes route/compliance/read-back tests.
13. **Regression/cutover readiness:** run golden corpus and produce a readiness
    report; do not perform production cutover autonomously.

## 25. Migration assessment

No schema change or migration is expected for the first implementation. The
safe initial form is transient pack plus owner-specific existing trace,
fingerprint and receipt fields. No Article bodies, legacy Knowledge, Video,
Media or Graph data should be backfilled. A future durable dependency index or
persisted selection history would require a separate constitutional/contract
decision and is not implied by this design.

## 26. Open questions

1. Should `EditorialContextPack` remain Capture-local and be passed by value, or
   receive a bounded immutable cache/read-model identity for long-running
   continuation? Default recommendation: value/receipt-local first.
2. Which active runtime registry should provide the final allowlist for
   profile-specific Graph paths, beyond generic `PredicateTraversalPolicy`?
3. Should the first AI selector be omitted until deterministic ranking and
   golden fixtures are stable? Recommendation: yes, deterministic first.
4. How should Article managed sections and Video metadata expose a common
   dependency preview without adding a cross-owner persistence table?
5. Which existing public Claim Projection revision is safe for Article/Video
   SEO in every deployment, and how should unavailable runtime be surfaced?
6. What minimum information-gain threshold is useful without turning a
   numerical editorial score into a truth/confidence signal?
7. When a Claim revision removes a selected role, should the owner preview
   propose deletion, replacement or human-authored continuation? This needs
   owner-specific policy.
8. Can the registered Video Capture adapter be promoted as the same shared
   Capture path in the target deployment, or must Video remain a distinct
   external-reference intake until runtime/client exposure is verified?

## 27. Explicitly rejected alternatives

* **A new Editorial Intelligence database/store:** rejected; duplicates
  Knowledge/Graph and violates ownership.
* **Graph traversal as truth:** rejected; reachability is discovery only.
* **Graph path equals truth:** rejected; path context must still pass Claim
  scope, provenance, Evidence and relevance eligibility.
* **Copying Article/Video prose into Knowledge:** rejected; generated prose is
  not Evidence.
* **Generic representative image satisfies all technical visual support:**
  rejected; feature support requires exact scope/facet/feature/intent.
* **OCR/caption becomes Knowledge automatically:** rejected; these remain
  observations/candidates until the governed Knowledge/Evidence path.
* **A separate Article, Video or Image knowledge-selection engine:** rejected;
  all profiles consume the shared read-model selection seam.
* **Persistent Context Pack as a semantic owner:** rejected; the pack is
  transient/read-model context only.
* **A Video-only enrichment engine:** rejected; it would diverge from shared
  semantics and reproduce the current thin-context problem.
* **Unbounded full-corpus Claim search:** rejected; violates scope, budget and
  explainability.
* **Persisting roles as Claim types or Graph predicates:** rejected; roles are
  editorial read-model labels.
* **Automatic silent re-enrichment:** rejected; Living Knowledge changes need
  preview, review and the existing owner workflow.
* **Unconditional public regeneration after Claim revision:** rejected; owner
  review, compliance, route/read-back and publication gates remain required.
* **SEO as truth or fallback identity:** rejected by SEO/public identity law.
* **One universal prose template:** rejected; journeys must vary by intent and
  profile while remaining grounded.
* **Odo-specific code path:** rejected; Odo 36 is only a golden acceptance
  fixture.

## 27. Research output

`RESEARCH_STATUS=COMPLETE_DESIGN_ONLY`

`GIT_STATUS_BEFORE=main clean; no pre-existing worktree changes observed`

`CURRENT_ARCHITECTURE_SUMMARY=Capture already orchestrates interpretation,
subject resolution, bounded Graph/Claim retrieval, governed reconciliation and
Article composition; Article has a substantial research preflight; Video and
Media have separate enrichment/readiness paths; SEO/Dictionary/public dossier
are read-only projections.`

`MAJOR_GAPS=No shared Editorial Context Pack; fragmented eligibility/selection;
Video does not consume shared selected canonical Claims; journey/information
gain/quality are not shared; re-enrichment preview is not cross-profile.`

`REUSE_CANDIDATES=TextInputInterpreter, SubjectResolutionService,
CanonicalAuthoritySubjectResolver, RelatedSemanticQuery,
SemanticNeighborhoodQuery, ClaimRetrievalEngine, ClaimReusePolicy,
CanonicalDependencyValidator, ArticleResearchPreflight, ArticleComposer,
VideoEditorialGenerator, VideoEditorialEnrichmentService,
VideoEditorialResumePlanner, DictionaryObservationRegistry, SEO projections,
PublicClaimCopyPolicy, VisualSupportRequirementService and CompletionCoordinator.`

`NEW_COMPONENTS_PROPOSED=EditorialContextPack, EditorialClaimEligibility,
EditorialKnowledgeSelector, ReaderJourneyPlanner,
SharedEditorialQualityEvaluator and SemanticSeoPlanner as application/read
models only.`

`CONTRACTS_AFFECTED=Capture/MCP, Article ingest/research/SEO, Knowledge and
Living Knowledge, Graph, Authority, Dictionary, Media/Image, Video ingest/
relationship/workflow/SEO, SEO Core, public claim compliance, Visual Support,
Public Entity Dossier, public routes/internal linking and frontend projection
contracts.`

`SCHEMA_CHANGE_EXPECTED=NO for the first transient/read-model slice`

`MIGRATION_EXPECTED=NO; explicitly prohibited for this research task`

`CONSTITUTION_CONFLICTS=NONE_FOUND; listed rejected alternatives remain
CONSTITUTION_CONFLICT if pursued`

`DESIGN_DOC_PATH=docs/plans/KNOWLEDGE_GROUNDED_EDITORIAL_SYSTEM.md`

`DESIGN_DOC_SECTIONS=27 sections covering the requested status, AS-IS, gaps,
TO-BE, data flow, retrieval, eligibility, selection, pack, journey, profiles,
SEO, quality, Living Knowledge, ownership, impact matrix, failure behavior,
observability, tests, golden case, rollout, migration, questions and rejected
alternatives`

`TEST_STRATEGY_SUMMARY=Deterministic unit/contract tests first; bounded Graph
and Claim eligibility; AI choice revalidation; profile integration tests;
revision/re-enrichment preview; compliance/readiness/public-route tests; sparse
and unavailable golden corpus; no data mutation.`

`GOLDEN_CASE_RESULT=Design supports the required Odo 36 behavior without any
Odo-specific implementation; missing canonical support remains review/incomplete.`

`OPEN_QUESTIONS=Pack lifetime, path/profile registry, AI timing, dependency
preview shape, Claim Projection revision, quality thresholds, revision-removal
policy and Video adapter runtime readiness.`

`RECOMMENDED_CHECKPOINT_ORDER=Baseline → retrieval → eligibility/selection →
Context Pack → journey/composition → SEO/links → quality → Article → Video →
Media/Image → re-enrichment preview → public projection → regression/readiness.`

`FILES_CHANGED=docs/plans/KNOWLEDGE_GROUNDED_EDITORIAL_SYSTEM.md`
