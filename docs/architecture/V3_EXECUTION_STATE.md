# NHK V3 Execution State

> **NON-NORMATIVE CURRENT EXECUTION LEDGER — 2026-09-07.**
> This file records the latest verified runtime/code status. It does not replace
> `docs/constitution/NHK_V3_CONSTITUTION.md`. Older checkpoints remain available
> in Git history and in dated audit artifacts; they must not override this
> current section when a blocker has since been resolved.

## Current source precedence

For current behavior read:

1. `AGENTS.md`;
2. `docs/constitution/READ_FIRST.md`;
3. `docs/constitution/NHK_V3_CONSTITUTION.md`;
4. `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`;
5. current domain/MCP contracts and executable registries/catalogs;
6. fresh canonical owner/runtime read-back.

Fixed test/tool counts, old `BLOCKED`/`ENVIRONMENT_BLOCKED` statements and dated
migration/deployment probes in older commits are historical evidence only.

## Current verified semantic write status

Semantic write surfaces are implemented/exposed for registered Authority
operations, Knowledge, Source, Evidence, Media, Video, Graph relations and
Governance proposal lifecycle. MCP/Admin remain orchestration/control-plane
adapters, not canonical owners.

Current semantic completion lifecycle is:

`proposal create/ingest → submit → review → approval with content/dependency
binding fingerprints → eligibility → Controlled Apply → canonical owner
read-back → idempotency verification`.

Proposal/draft/submitted/approved/eligible/apply-response state is not canonical
completion. `COMPLETED` requires matching owner read-back and replay without a
duplicate side effect.

## Reconcile-before-create and no-orphan state

The 2026-09-07 Constitution amendment requires canonical research before every
new semantic node/Knowledge claim and classifies intent as:

- `EXACT_EXISTING`;
- `MERGE_CANDIDATE`;
- `RELATED_BUT_DISTINCT`;
- `NO_EXISTING_CANONICAL_RECORD`;
- `UNCERTAIN`.

Only `NO_EXISTING_CANONICAL_RECORD` allows new canonical create. Fuzzy/lexical/
keyword/display-name/checksum/URL similarity is not identity proof.

Knowledge may not be deliberately created detached from its intended canonical
subject. Resolve the subject first; if a genuinely required Authority node is
missing, create/apply/read it back first; otherwise defer the claim.

## Graph relation source binding — RESOLVED

Historical defect: a persisted `relation_create` proposal could hydrate
`subject_id` from `entity_type` (for example `knowledge`) instead of the real
relation source UUID.

Current code in `WpdbProposalRepository::hydrate()` resolves relation subject
identity from `payload.source_uuid`, with legacy `source_key` as compatibility
fallback. Relation commands preserve:

`source_type/source_uuid → registered predicate → target_type/target_uuid`.

After this fix, real governed relation flows have passed proposal create,
submit, review, approval, eligibility, Controlled Apply and canonical Graph
read-back. Therefore relation source binding is **not a current global Graph
blocker**.

Create proposals for not-yet-existing Authority nodes are different: before
create there is no canonical node UUID, so an entity-type pre-create subject
marker is not evidence of the old relation bug.

## Current Graph registry

Executable predicates are:

- `about`;
- `depicts`;
- `model_of`;
- `variant_of`;
- `uses_movement`;
- `supports_music`;
- `configured_with_music`;
- `observed_playing_music`.

Current known gaps:

- `classified_as` = `REGISTRY_GAP`;
- dedicated Product↔Specimen relation = `REGISTRY_GAP`.

`about` must not be used to fake classification membership, structural
parentage, configuration, movement use, Product–Specimen ownership or another
missing relation meaning.

## Cuckoo current runtime state — COMPLETED for 10 core Knowledge relations

Canonical Classification:

- UUID `01a07614-832d-7f27-959c-74eb0cd63f3e`;
- stable key `nhk:classification:clock-type.cuckoo-clock`;
- name `Đồng hồ chim cúc cu`.

Ten core Cuckoo Knowledge claims have completed
`Knowledge → about → Classification Cuckoo` through the full Governance
lifecycle and canonical Graph read-back. Older checkpoints that report an empty
Cuckoo neighborhood or `RELATION_GAP` for these ten claims are superseded.

This does not close Model/Variant Classification membership. That semantic
meaning needs `classified_as`, which remains unregistered.

## Authority create probe

Proposal `01a07c4e-14b2-734e-8264-3f04b37e5fe4`,
`operation=create`, `entity_type=classification`:

- proposal create: PASS;
- submit: PASS;
- review/fingerprint binding: PASS;
- approval: PASS;
- eligibility: PASS (`ready=true`, `reasons=[]`);
- Controlled Apply: intentionally NOT RUN.

The proposal was not applied to avoid creating a junk canonical Classification.
Do not claim actual new-node generated UUID, `entity_get`/resolver read-back or
immediate relation use until a real needed Authority node is created and that
chain is observed.

## Knowledge / Source / Evidence

Knowledge, Source and Evidence are separate canonical owners and their governed
writers are exposed. Source is provenance/locator. Evidence links an existing
Claim and existing Source with `supports`, `contradicts` or `qualifies`.

Required factual semantic flow is:

`Source/Evidence research → canonical subject resolution → reconcile current
canonical entities/claims → create/update/merge Authority if genuinely needed →
Authority read-back → Knowledge ingest/read-back → governed Graph attachment →
Source/Evidence attachment → Graph/Knowledge/Evidence read-back → idempotency
verification`.

Active PRIVATE/HIDDEN Source/Evidence may be used by governed internal
verification where policy permits without exposing raw private data publicly.

## Video current state

Video remains a canonical external-reference domain. Same YouTube external ID
reuses the same canonical Video; thumbnail Media, Knowledge, Source/Evidence and
Article remain separate identities.

Generic transcript/factual enrichment is planning-first. The current guided
Video relation workflow is more capable: after canonical Video + canonical
target resolution, `VideoRelationAdminService` can deterministically
resolve/reuse or create and read back:

`private canonical YouTube Source → provenance-scoped Claim → private Evidence`.

It then creates the governed Video `about` relation proposal with canonical
`evidence_refs`, content/dependency fingerprints and stable idempotency. Normal
Admin users do not manually enter Video proposal UUID or Evidence UUID. Wrong
provenance fails closed.

Canonical public Video route is `/video/{slug}/`; external YouTube URL is source/
provenance/embed only.

## Media / Image current state

Media, MediaAsset, MediaUsage and WordPress attachment remain separate.
`nhk.media.ingest` is the current governed Media metadata/direct multipart image
boundary. New binary intake reconciles/reuses canonical Media, retains the
source-original PRIVATE/protected and keeps eligible optimized derivatives under
the same Media identity.

Checksum, filename, URL and upload time are duplicate/presentation signals, not
canonical merge proof. MediaUsage/detail context does not create Knowledge,
Evidence or Graph truth. Any semantic `depicts`/other relation requires separate
canonical target resolution and the registered Graph/Governance boundary.

## Article / Note current state

WordPress `wp_posts` owns Article editorial title/body/URL. Article preflight is
read-only canonical research/reconciliation; Article payload/frontend/template
does not mint semantic truth. Newly discovered facts must independently pass the
canonical subject, reconcile, Source/Evidence/Knowledge and Governance flow.

No separate canonical semantic Note owner is currently registered. Editorial or
research notes are workspace context unless explicitly promoted through the
existing canonical semantic owners.

## Graph retrieval and frontend status

Current application/MCP has:

- outgoing/incoming canonical Graph reads;
- `nhk.graph.inventory`;
- `nhk.relation.backfill.dry_run`;
- authenticated deterministic `nhk.relation.backfill.apply`;
- bounded `nhk.entity.neighborhood` with max two hops under registered profiles;
- frontend/dossier related projections over canonical Graph state.

Therefore historical statements that no reusable neighborhood/read seam exists
are superseded. A specific frontend/dossier that does not yet consume every
eligible path/profile is `PARTIAL_FRONTEND_GAP`, not Graph absence. Keyword,
taxonomy or postmeta relation fallback remains prohibited.

## Governance automation

Current automation policy modes are `REVIEW_REQUIRED`, `AUTO_APPROVE` and
`AUTO_PUBLISH`, defaulting to `REVIEW_REQUIRED`. Automation uses the same
Governance bindings/eligibility/read-back laws. `AUTO_APPROVE` does not imply
Apply; `AUTO_PUBLISH` cannot report publication success without canonical and
frontend/publication verification required by its contract.

## Deferred/retry state

Current intermediate classifications include `PENDING_RESEARCH`,
`EVIDENCE_GAP`, `REGISTRY_GAP`, `RELATION_GAP`, `LEXICAL_GAP`, `FRONTEND_GAP`,
`RUNTIME_BLOCKED`, `NEEDS_REVIEW`.

Final outcomes are `COMPLETED`, `DEFERRED_WITH_REASON`,
`BLOCKED_WITH_OWNER_ACTION`.

Deferred entries retain source/provenance, proposed subject/type/relation,
evidence, resolved canonical IDs, registry/runtime blocker, existing proposal ID
and deterministic rerun instructions. Rate-limit/runtime interruption reuses the
existing proposal/idempotency binding when intent is unchanged.

## Current remaining gaps

1. `classified_as` remains unregistered.
2. Product–Specimen canonical persistence relation remains unregistered.
3. The first genuinely needed new Authority node still requires real Controlled
   Apply → generated canonical UUID → entity/resolver read-back → immediate
   relation-use proof; the create lifecycle is already verified through
   eligibility.
4. Full physical Graph completeness/backfill remains target-data specific even
   though governed relation apply exists.
5. Media→Living Knowledge automatic semantic enrichment is not authorized from
   MediaUsage/OCR/recognition alone.
6. Automatic Knowledge→Article-body rewrite remains prohibited.
7. Public Identity and individual dossier/frontend path coverage still have
   target-environment gates documented by their current contracts.

## Historical checkpoint interpretation

The former long chronological checkpoint journal contained useful evidence but
also many present-tense statements that became contradictory as runtime evolved,
including fixed MCP counts, “no related Graph read”, “Video does not create
Source”, “governed batch surface unavailable”, “Cuckoo neighborhood empty”, and
several environment-specific blockers.

Those events remain recoverable in Git history. Machine-readable earlier Graph
counts and blockers are also retained under the explicitly historical section of
`docs/architecture/GRAPH_DATA_AUDIT_2026-09-07.json`. They must not be promoted
back into current status after a later executable/runtime proof supersedes them.

## Documentation reconciliation checkpoint — 2026-09-07

This documentation sync changes documentation only. It does not modify runtime
PHP/JS, semantic data, WordPress content, Graph edges, migrations, V2,
staging/production data or deployment state.

Current canonical docs now agree on:

- reconcile before create;
- no orphan Knowledge;
- full Governance lifecycle including Submit/Review/binding/read-back;
- canonical owner completion/idempotency;
- resolved relation source binding;
- Cuckoo 10 Knowledge `about` relations completed;
- honest remaining registry gaps;
- guided Video provenance orchestration;
- Media/Asset/Usage separation;
- Article/Note editorial boundary;
- bounded Graph neighborhood and frontend-gap classification.
