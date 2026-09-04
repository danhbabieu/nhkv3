# NHK V3 Current Documentation Status Index

> **NON-NORMATIVE ROUTER / STATUS INDEX — 2026-09-04.**
> This file is not a second Constitution and does not create semantic vocabulary,
> operations, predicates, storage, routes or data. Its purpose is to tell
> downstream systems which sources are current law/contract, which sources are
> executable/runtime truth, and which documents are historical evidence.
>
> If anything here conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`,
> the Constitution controls.

## 1. Authority and read order

Use this precedence when deciding current behavior:

1. `docs/constitution/NHK_V3_CONSTITUTION.md` — sole supreme normative authority.
2. Current approved contracts referenced by `docs/constitution/READ_FIRST.md`.
3. Executable registries/catalogs and application boundaries for the vocabulary
   and capabilities actually present in the checked-out runtime.
4. Fresh runtime discovery/read-back when the question is whether a tool,
   Ability, route, record or integration is actually available in that
   environment.
5. `docs/architecture/V3_EXECUTION_STATE.md` — dated execution ledger. Newer
   checkpoints may supersede older entries; fixed historical counts are not
   timeless contracts.
6. Numbered P-phase documents, parity matrices, audits and dated implementation
   checkpoints — implementation/historical evidence unless a current contract
   explicitly incorporates them.
7. Plans/specs under `docs/superpowers/` and legacy/V2 material — plan/reference
   or migration evidence only.

A newer timestamp alone never overrides the Constitution or an approved
contract. Conversely, an old checkpoint must not override a later executable
registry/catalog merely because its wording is present tense.

## 2. Current boundary snapshot

| Area | Current boundary | Current status / reuse rule |
|---|---|---|
| Article | WordPress `wp_posts` owns editorial title/body/excerpt/order/public editorial URL | semantic truth remains separate; Article completion is cross-boundary and runtime-gated; no body copy into Knowledge/Graph/receipts |
| Authority | nine registered canonical types | canonical UUID/stable key/revision; no prose/URL/checksum-derived identity |
| Graph | only semantic relation persistence | current executable predicate vocabulary includes `about`, `depicts`, `model_of`, `variant_of`, `uses_movement`, `supports_music`, `configured_with_music`, `observed_playing_music`; physical row completeness/backfill is a separate runtime/data question |
| Product–Specimen | no approved canonical persistence relation | payload fields, taxonomy, post meta or broad `about` are not ownership substitutes; contract/registry extension required before canonical linkage |
| Public Identity | persisted identity/history implementation now exists in code | `PublicIdentityService`, repository/WPDB boundary, migration 014 and exact one-hop history resolver are implemented locally; guarded migration/data allocation/current-route consumer parity remain runtime-unverified, so do not claim durable public identity is live without read-back |
| Knowledge / Source / Evidence | atomic canonical claim + provenance/support contexts | governed writes only; reuse canonical IDs/revisions; Article prose, Video transcript, OCR, captions and generated copy are not automatic Evidence |
| Living Knowledge | read/plan/resolve then governed mutation | no silent semantic rewrite; downstream reuse must preserve scope and provenance |
| Video | canonical external reference | Video → Living Knowledge planning seam implemented; explicit validated `about` target is preserved as enrichment subject; planning packet itself performs no Knowledge/Evidence/Graph mutation |
| Media | `Media` identity separate from `MediaAsset`, `MediaUsage` and WP attachment | source-original retained private/protected; derivatives remain under same Media; checksum does not auto-merge identity |
| Media → Living Knowledge | no approved automatic adapter yet | MediaUsage/`depicts`/OCR/recognition do not become Knowledge/Evidence implicitly |
| Article → Living Knowledge body update | suggestion/governed boundary only | Knowledge changes never auto-rewrite a published WordPress Article body |
| MCP | transport/orchestration over existing owners | fixed tool counts in historical docs are snapshots only; use current `McpToolCatalog` plus fresh runtime discovery when availability matters |
| WordPress Abilities | discoverability/adapter projection of supported MCP/application operations | historical limited allowlists are not current truth; inspect current registration + fresh discovery; multipart Media ingest remains on its approved custom MCP boundary |
| SEO/Public Projection | `docs/seo/NHK_V3_SEO_CORE_CONTRACT.md`, `ENTITY_SEO_PROJECTION_CONTRACT.md`, `MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md`, `SITEMAP_INDEXABILITY_CONTRACT.md` plus existing Article/Video/Living Knowledge contracts | read/projection-only layer; shared readiness/indexability must be reused; may not invent facts, identity or semantic writes |

## 3. Current storage and writer rule

Every domain has one canonical owner and authorized writer boundary:

- Article editorial state → WordPress editorial gateways/read-back.
- Authority → Authority service/repository through Governance where semantic.
- Public Identity → dedicated Public Identity service/repository/history boundary;
  compatibility route derivation is not a second durable identity writer.
- Knowledge/Source/Evidence → their canonical services/repositories through
  Governance/Controlled Apply.
- Graph relations → `GraphService` through governed relation lifecycle.
- Media/MediaAsset/MediaUsage → governed Media application boundary; WordPress
  attachment is storage/public projection, not semantic owner.
- Video → governed Video intake/apply boundary.
- MCP/Admin/WordPress adapters → orchestration/input adapters only; never a
  second semantic store or writer.

Downstream systems should resolve/reuse canonical UUID/stable key/revision and
read back from the owning store instead of cloning semantic data into a new
context.

## 4. Historical-document interpretation

The following kinds of statements are snapshots unless explicitly reaffirmed by
current contracts/runtime:

- exact MCP tool or Ability counts;
- fixed test/assertion counts;
- environment-specific connector exposure counts;
- migration/runtime probe outcomes tied to a date;
- statements such as “not implemented”, “no Article Ability”, “registry gap”,
  “READY” or “BLOCKED” inside an older checkpoint;
- route/data counts captured before later implementation slices.

Preserve such text as historical evidence when useful, but label it historical
or route current downstream readers through this index/current contracts.
Do not rewrite history merely to make old checkpoints look current.

## 5. Known current gaps that remain intentional

- Public Identity runtime activation/data coverage/current-route consumer parity:
  implementation exists locally, but guarded migration execution and live
  allocation/read-back are not proven by the current checkpoint;
- dedicated Product–Specimen canonical relation;
- full physical Graph completeness/backfill where not runtime-proven;
- Media → Living Knowledge automatic enrichment adapter;
- automatic Article body rewrite from Knowledge (prohibited by design; only
  suggestion/governed editorial flow is allowed);
- exact integration/runtime gates wherever current execution evidence reports
  `ENVIRONMENT_BLOCKED` or unavailable infrastructure;
- any capability whose availability has not been confirmed by current runtime
  discovery/read-back in the target environment.

A gap is not permission to invent a shortcut.

## 6. Downstream operating rule

Before implementing or mutating data:

1. follow `READ_FIRST.md`;
2. resolve the responsible bounded context and canonical owner;
3. inspect current executable registry/catalog when vocabulary/capability is in
   question;
4. distinguish historical evidence from current contract;
5. fail closed on ambiguity or a missing approved writer/relation;
6. use Governance for semantic mutation;
7. read back from the canonical owner before claiming completion.

This index should remain compact. Detailed law belongs in the Constitution or
approved domain contracts, not duplicated here.
