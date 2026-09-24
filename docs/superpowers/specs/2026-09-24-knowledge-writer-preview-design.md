# NHK Knowledge Writer Preview Design

## Status

Owner-approved design for a local, read-only implementation slice. This
document is subordinate to `AGENTS.md`, the NHK V3 Constitution, the current
documentation status index, and the active Knowledge/MCP contracts.

## Goal

Expose the existing Universal Enrichment and Editorial Intelligence pipeline
through one system-wide read-only capability, conceptually named
`nhk.knowledge.writer.preview`, so a consumer can request a grounded,
reader-facing answer for a resolved subject and presentation purpose.

The capability is a transient read model. It is not a new Brain, semantic
owner, Knowledge writer, Article writer, Capture path, or owner-specific
feature.

## Constraints and non-goals

- The capability is read-only and must not create or mutate Capture, Post,
  Article, Media, MediaUsage, Video, Authority, Knowledge, Claim,
  Source/Evidence, Graph, Proposal, Governance, Public Identity, SEO, or
  publication state.
- `GRAPH_REACHABLE` remains distinct from `FACTUALLY_APPLICABLE`.
- Public assertion specificity must not exceed support specificity.
- User-supplied observations/context remain contextual input and never become
  canonical Knowledge or Evidence through preview.
- Sibling Variant, broader Brand/Model, reverse-graph, provenance-only and
  control metadata must not be silently promoted to direct factual prose.
- Production code must not branch on fixture names, brand/model/variant names,
  UUIDs, YouTube IDs, WordPress post IDs, or fixture titles.
- No database migration, seed, import, deployment, push, staging mutation or
  production action is part of this slice.

## Existing architecture reused

The preview façade will compose existing transient services:

`subject locator → existing semantic resolver → UniversalInputEnvelope →
SharedEnrichmentBoundary/UniversalEnrichmentCore → EnrichmentPack →
ReaderJourneyPlanner → SharedEditorialComposer → EditorialQualityGate →
sanitized preview projection`.

The implementation will reuse `McpSemanticContextResolver` for canonical UUID,
typed UUID, stable-key and bounded textual resolution, `EditorialClaimRetrievalService`
and `EditorialKnowledgeSelector` for retrieval/applicability/selection, and the
existing Reader Journey, Composer and Quality objects for presentation and
quality diagnostics. No parallel retrieval, applicability, graph, deduplication,
composition, or quality engine will be introduced.

## Runtime boundary

Add one application façade, `KnowledgeWriterPreviewService`, with a single
read method accepting a normalized associative request and returning a
structured associative result. Its constructor receives only read/planning
dependencies: the existing semantic resolver, shared enrichment boundary,
journey planner, composer, quality gate, and a deterministic presentation
profile map. It receives no repository writer, Governance service, proposal
factory, Capture coordinator, or WordPress mutation gateway.

The façade will:

1. Normalize and validate the subject locator, instruction, purpose, facets,
   depth preference, observations and output constraints.
2. Resolve a canonical subject using the existing resolver. Preserve
   `resolved`, `ambiguous`, `unresolved`, conflict and diagnostic status rather
   than guessing.
3. Build a transient `UniversalInputEnvelope`; observations are copied only as
   contextual input and are not routed to the Knowledge enrichment proposal
   branch.
4. Call the shared enrichment boundary with the selected presentation profile,
   semantic needs and bounded retrieval options.
5. Plan the reader journey, compose prose, and evaluate readability,
   information gain, grounding, internal-language leakage and specificity
   safety through existing quality/copy guards.
6. Project the result into the required preview contract, preserving canonical
   Claim/Knowledge identifiers, revisions, subjects, facets, applicability,
   specificity, treatment, evidence/provenance trace and graph path for used
   material.
7. Emit bounded deterministic diagnostics and a no-mutation guarantee marker.

Purpose values are a thin presentation policy over the same pack. The initial
map is:

| Purpose | Existing presentation profile | Intent |
|---|---|---|
| `concise_answer` | concise/video-style | short direct answer |
| `collector_explanation` | article-style | explanatory reader journey |
| `article_section` | article-style | section-ready prose |
| `video_description` | video-style | concise descriptive prose |
| `media_caption` | media/image-style | short visual context |
| `media_alt` | media/image-style | concise accessible description |
| `entity_summary` | article-style | bounded entity overview |
| `technical_explanation` | article-style | explanatory technical context |

This map changes presentation only. It cannot alter Claim eligibility,
applicability, evidence, canonical subject, specificity or Governance rules.

## MCP and Ability exposure

Register `nhk.knowledge.writer.preview` through all current executable layers:

- `McpToolCatalog` as a non-governed `read` tool with a bounded JSON schema.
- `McpDispatchRegistry` with a canonical handler key.
- `McpTransport` as a read-only dispatch branch injected with the façade.
- `McpAbilityRegistration` in the normal public read-ability projection,
  marked `readonly: true`, `destructive: false`, and `idempotent: true`.
- Plugin composition root wiring using the already-created semantic engine
  and resolver dependencies.

The descriptor must be discoverable through `tools/list`, and local tests must
invoke the transport and Ability callback path. If a live connector cannot be
verified, that remains a separately reported environment/client exposure gate,
not a reason to substitute another writer.

## Input contract

Required:

- `instruction`: bounded reader question or editorial instruction.

Subject locator supports at least:

- `canonical_uuid`;
- `{type, uuid}`;
- `{type, stable_key}` when supported by the resolver;
- bounded textual `query`/subject hint through semantic resolution.

Optional:

- `purpose`, defaulting to `concise_answer`;
- `requested_facets`, bounded list of registered facet names;
- `depth`, bounded preference such as `concise` or `deep`;
- `observations`, structured contextual records;
- `output_constraints`, bounded length/language/format hints.

Unknown purpose/facet/depth values fail closed with deterministic input
diagnostics. Raw UUIDs, stable keys and orchestration terms are accepted as
input locators but never rendered in reader-facing `answer` prose.

## Output contract

The façade returns a structured result with these top-level fields:

- `status`: `available`, `sparse`, `unresolved`, `ambiguous`, `blocked` or
  `unavailable`;
- `subject`: canonical id, type, name and resolution trace/status;
- `answer`: reader-facing prose only;
- `depth`: effective purpose/profile and depth;
- `semantic_needs`: normalized needs and their statuses;
- `coverage`: overall and per-need/facet coverage;
- `used_knowledge`: bounded trace for every factual unit used, including
  canonical Claim/Knowledge id, revision, original/target subject, facet,
  applicability, specificity, treatment, evidence/provenance and graph path;
- `excluded_knowledge`: bounded diagnostic entries with exclusion reasons;
- `context_used`: observations/editorial context that influenced presentation
  without being promoted to facts;
- `gaps`: unresolved or uncovered needs;
- `warnings`: bounded safe warnings;
- `quality`: readability, repetition/information gain, factual grounding,
  internal-language leakage and specificity safety;
- `diagnostics`: deterministic, bounded and secret-safe diagnostics;
- `read_only`: explicit `true` marker and mutation-scope assertion.

Sparse Knowledge produces a short grounded answer or an empty answer with
gaps; it does not pad with provenance or repeat unsupported input. Rich
Knowledge produces a coherent bounded journey and avoids duplicate
propositions until marginal reader value becomes low.

## Error and safety behavior

- Unresolved or ambiguous subjects do not retrieve against a guessed subject.
- Missing runtime dependencies return an unavailable/blocked read result with
  a safe diagnostic, not a fabricated answer.
- Internal IDs/keys, evidence-control metadata and orchestration terms are
  retained only in trace/diagnostics and are scrubbed from `answer`.
- The service must not invoke any write-capable dependency. A mutation-safety
  regression will snapshot all relevant in-memory repositories and assert that
  one preview call leaves them unchanged.
- Diagnostics must use stable reason codes and bounded, sanitized text; raw
  exceptions, credentials and request secrets are not returned.

## Verification scope

Focused tests will cover canonical and textual resolution, Brand/Model/Variant/
Classification subjects, sparse/rich/duplicate/contextual/inapplicable and
reverse-graph material, direct facts, unsupported observations, requested and
uncovered facets, every purpose profile, deterministic replay, internal metadata
leak prevention, MCP catalog/dispatch/Ability exposure, and full mutation-state
invariance. Existing Universal Enrichment, Brain 2, V2.3 and V2.4 regressions
remain part of the repository verification run.

Documentation will receive a minimal active note identifying this capability as
a read-only consumer of Universal Enrichment + Editorial Intelligence, never an
alternate ingest or canonical-write route.
