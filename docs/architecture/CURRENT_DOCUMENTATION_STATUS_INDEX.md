# NHK V3 Current Documentation Status Index

> **NON-NORMATIVE ROUTER / STATUS INDEX — 2026-09-06.**
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
| Dictionary / lexical curation | dedicated Concept/Label/Candidate/Mention lexical stores under `DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` | lexical lookup/curation only; search first, reuse existing owner, unknown terms become private candidates; no Authority/Knowledge/Evidence/Graph truth; research preview is read-only and stored Article body is never rewritten by auto-link projection |
| Authority | nine registered canonical types | canonical UUID/stable key/revision; no prose/URL/checksum-derived identity |
| Graph | only semantic relation persistence | current executable predicate vocabulary includes `about`, `depicts`, `model_of`, `variant_of`, `uses_movement`, `supports_music`, `configured_with_music`, `observed_playing_music`; governed relation commands now preserve explicit endpoint UUIDs, bounded direct/inverse reads and a read-only semantic-neighborhood MCP seam exist; `classified_as` remains a documented `REGISTRY_GAP` pending approved Authority vocabulary, and physical row completeness/backfill is a separate runtime/data question |
| Product–Specimen | no approved canonical persistence relation | payload fields, taxonomy, post meta or broad `about` are not ownership substitutes; contract/registry extension required before canonical linkage |
| Public Identity | persisted identity/history implementation plus shared public-slug policy exist in code | `PublicIdentityService`, `CanonicalPublicSlugPolicy`, repository/WPDB boundary, migration 014 and exact one-hop history resolver are implemented; compatibility routes now reuse the shared normalizer/collision candidates, while guarded migration/data allocation/current-route durable consumer parity and live re-projection remain runtime-unverified |
| Knowledge / Source / Evidence | atomic canonical claim + provenance/support contexts | governed writes only; reuse canonical IDs/revisions; Article prose, Video transcript, OCR, captions and generated copy are not automatic Evidence |
| Living Knowledge | read/plan/resolve then governed mutation | no silent semantic rewrite; downstream reuse must preserve scope and provenance; Dictionary labels may assist lexical matching but never mint claims/evidence |
| Video | canonical external reference | Video → Living Knowledge planning seam implemented; explicit validated `about` target is preserved as enrichment subject; Dictionary observation after canonical write is lexical/non-blocking and does not broaden the target |
| Media | `Media` identity separate from `MediaAsset`, `MediaUsage` and WP attachment | source-original retained private/protected; derivatives remain under same Media; checksum does not auto-merge identity; caption/alt/filename observations may feed Dictionary candidates only |
| Media → Living Knowledge | no approved automatic adapter yet | MediaUsage/`depicts`/OCR/recognition do not become Knowledge/Evidence implicitly |
| Article → Living Knowledge body update | suggestion/governed boundary only | Knowledge changes never auto-rewrite a published WordPress Article body |
| MCP | transport/orchestration over existing owners | fixed tool counts in historical docs are snapshots only; use current `McpToolCatalog` plus fresh runtime discovery when availability matters; no dedicated Dictionary MCP surface should be claimed unless current catalog/runtime exposes it |
| WordPress Abilities | discoverability/adapter projection of supported MCP/application operations | historical limited allowlists are not current truth; inspect current registration + fresh discovery; multipart Media ingest remains on its approved custom MCP boundary |
| SEO/Public Projection | `docs/seo/NHK_V3_SEO_CORE_CONTRACT.md`, `PUBLIC_URL_SLUG_CONTRACT.md`, `ENTITY_SEO_PROJECTION_CONTRACT.md`, `MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md`, `SITEMAP_INDEXABILITY_CONTRACT.md` plus existing Article/Video/Living Knowledge/Dictionary contracts | read/projection-only layer; one title/name-derived public-slug policy is reused by NHK-managed semantic generators; canonical/OpenGraph/schema/sitemap/internal-link surfaces consume the resolved canonical path rather than independently slugifying |

## 3. Current storage and writer rule

Every domain has one canonical owner and authorized writer boundary:

- Article editorial state → WordPress editorial gateways/read-back.
- Dictionary lexical state → dedicated Concept/Label/Candidate/Mention repository;
  automatic content detection may persist only lexical observations/candidates
  after the owning content write, while curation uses its dedicated authorized
  boundary. Dictionary persistence is not a semantic writer.
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
context. Dictionary owner delegation must be revalidated at read time; a stale
stored destination is not permission to publish/link an invalid canonical URL.

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

- Public Identity runtime activation/data coverage/current-route durable consumer
  parity and target-runtime re-projection are not proven. The current canary
  projection is intentionally read-only; no bulk persisted-identity rewrite or
  governed re-projection executor is claimed by code presence alone;
- Dictionary migration 015/runtime activation, initial curated data, dry-run
  legacy backfill and target-environment public-route/read-back are not proven
  until executed in the target WordPress runtime; code presence alone is not
  live acceptance;
- dedicated Dictionary MCP tools are not current capability truth unless they
  are added to the executable catalog and confirmed by fresh runtime discovery;
- dedicated Product–Specimen canonical relation;
- approved Classification membership predicate (`classified_as`) and live Graph relation backfill runtime;
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

For Dictionary work specifically, detection is not semantic identity: resolve
approved labels/current canonical owners first, create a private candidate only
when unresolved, and keep ambiguous terms unlinked until human curation.

This index should remain compact. Detailed law belongs in the Constitution or
approved domain contracts, not duplicated here.
