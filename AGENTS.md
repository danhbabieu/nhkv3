# NHK V3 Autonomous Engineering Rules

## Constitution — mandatory first read

Before any NHK V3 architectural or implementation work, every Codex session MUST
read the following chain directly and in order:

AGENTS.md
→ docs/constitution/READ_FIRST.md
→ docs/constitution/NHK_V3_CONSTITUTION.md
→ relevant normative contracts

The session MUST also read the canonical Constitution directly:

docs/constitution/NHK_V3_CONSTITUTION.md

This is the only normative Constitution. Specs, plans, audits, execution state,
parity matrices, READMEs and historical V2 material are subordinate evidence or
implementation guidance. If any source conflicts with the Constitution, mark it
CONSTITUTION_CONFLICT; do not weaken the Constitution to legalize code.

`docs/constitution/READ_FIRST.md` is a non-normative router only; it is not a
second Constitution. For Article Ingest work, read
`docs/architecture/ARTICLE_INGEST_CONTRACT.md` and the relevant MCP contracts
after the Constitution.

## Scope and workspace

- Official workspace: /Users/imac24-2125d/Developer/nhk-v3
- Repository: https://github.com/danhbabieu/nhkv3.git
- Default branch: main
- This repository is the only implementation workspace for NHK V3.

## Non-negotiable architecture

- Structure first. Relationships first. Data later.
- WordPress native wp_posts is the sole source of truth for editorial title,
  body, author, dates, categories, archives, homepage, search, RSS, sitemap and
  editorial URLs.
- Authority owns canonical semantic entities; Knowledge owns atomic claims;
  Source/Evidence owns provenance/support; Graph is the single relation system;
  Governance owns durable semantic mutations; Media, MediaAsset, MediaUsage and
  Video retain distinct boundaries.
- Specimen means one concrete physical object. Product means a listing/offer and
  is never the physical object's identity.
- Runtime registries/contracts are executable boundaries. Never invent an entity
  type, endpoint type, predicate, relation type, canonical field, attribute,
  operation or knowledge profile from fixtures, UI, V2 or legacy structures.
- Preserve canonical UUID/stable-key, optimistic revision, typed relation,
  provenance, readiness, idempotency, public identity and fail-closed
  invariants.

## Data and database safety

- Do not migrate, import, parse or populate legacy article bodies under the
  current Constitution scope.
- Do not mutate V2 or production data.
- Staging semantic mutation is normally forbidden, except for an explicitly user-approved, bounded NHK V3 live acceptance on existing objects.
- A staging acceptance mutation is allowed only when all of the following are true:
  - fresh documentation bootstrap passes;
  - the deployed build identity has been verified;
  - exact existing object IDs are supplied;
  - a duplicate/read-only audit is completed first;
  - only canonical NHK V3 governed workflows are used;
  - no generic WordPress writer, direct database write, or Governance bypass is used;
  - the operation is idempotent and canonical read-back is performed after mutation;
  - if runtime state differs from the approved scope, stop fail-closed.
- `STAGING_ACCEPTANCE_SCOPE` is the bounded, fail-closed project-local
  authorization contract for an explicitly approved staging run. It is scoped
  by exact existing IDs and operation families; it never grants unrestricted
  semantic writes or direct-writer access.
- Current approved staging acceptance scope for this run supersedes the prior
  Video-only package:
  - environment: `staging`
  - allowed_capture_ids:
    - `01a09aa3-59a2-74c0-9c6a-1a6867eb7f59`
    - `01a09aa4-9ecc-758b-854d-5d44bb176267`
  - allowed_owner_ids:
    - `01a09e44-539a-7f1a-938a-d7d91bb689a3`
    - `01a09f73-0aad-79b3-9aaf-5f02cb33a9d1`
  - allowed_post_ids: `[485, 487]`
  - allowed_media_ids: `[01a0a36c-3332-7083-85fd-43dbc2a80810]`
  - allowed_attachment_ids: `[489]`
  - allowed_asset_ids: `[01a0a36c-3338-7ac0-ba72-a7c87c4e6b5e]`
  - allowed_relation_ids:
    - `01a0a344-da4d-7653-b7e8-75ffdd80ac38`
  - allowed_operation_families:
    - capture_continuation
    - knowledge_delta
    - source_evidence_reconciliation
    - semantic_subject_binding
    - governed_relation_reconciliation
    - article_reconciliation
    - article_body_correction
    - category_slug_seo_reconciliation
    - article_publication
    - media_usage_reconciliation
    - presentation_readiness
    - frontend_projection_readback
    - governed_proposal_lifecycle
    - canonical_readback
  - fail_closed_outside_scope: `true`

- Additional bounded staging acceptance scope for the Atherton Authority plan
  (explicitly authorized 2026-09-18):
  - environment: `staging`
  - allowed_capture_ids:
    - `01a0b162-9cd5-7989-aa08-cec3322bd45f`
  - allowed_request_fingerprints:
    - `06ede91a4097f27c1001f07be919f0f1c01f69a34e4d5f921ac6aa37c19ac142`
  - allowed_plan_fingerprints:
    - `6b69927f676867d2023df620149f1c93331ae81b89d622d6bfa20d28fafcb736`
  - allowed_candidates:
    - `candidate-831c785e8e84398ce3c7` (`model`, `create`, `Atherton`)
    - `candidate-43e3d1452693c18a7119` (`relation`, `relation_create`, `model_of`)
  - allowed_relation_target:
    - type: `brand`
    - uuid: `01a090fd-9a71-7665-af5f-08f6e25b533e`
    - revision: `2`
  - allowed_operation_families:
    - `governed_authority_plan`
  - restrictions:
    - exact Capture/request/plan fingerprints and candidate IDs only
    - Model create is limited to `Atherton` with the exact `brand_uuid` above
    - relation create is limited to `model_of` and the exact Brand target/revision
    - no wildcard, global Authority, direct writer, direct DB or direct Graph path
  - fail_closed_outside_scope: `true`
- The previously approved Video Capture `01a096c0-97cc-7192-acf2-4735f9bf6582`
  package remains historical evidence and is not part of this Public Clock
  run. Future content families require a newly authorized bounded package.

- Additional bounded staging acceptance scope for the existing 400-day Clock
  Capture continuation:
  - environment: `staging`
  - allowed_capture_ids:
    - `01a0ae4c-0fe7-72b1-8222-ece526ce0faa`
  - allowed_owner_ids:
    - `01a0a868-2918-7dac-81dc-bfc25e710068`
  - allowed_post_ids: `[575]`
  - allowed_media_ids: `[01a0ae48-1008-7213-a91f-dde21d36e66b]`
  - allowed_attachment_ids: `[574]`
  - allowed_operation_families:
    - capture_continuation
    - governed_relation_reconciliation
    - media_usage_reconciliation
    - article_reconciliation
    - article_body_correction
    - article_publication
    - presentation_readiness
    - frontend_projection_readback
    - canonical_readback
  - fail_closed_outside_scope: `true`

- Additional bounded staging acceptance scope for Media binding case 567:
  - environment: `staging`
  - host: `https://demo.1945.vn`
  - allowed_media_ids: `[01a0ab0c-fde0-7c01-a89d-fc5eef832c89]`
  - allowed_attachment_ids: `[567]`
  - allowed_target_ids: `[01a07614-832d-7f27-959c-74eb0cd63f3e]`
  - allowed_target_types: `[classification]`
  - allowed_target_stable_keys: `[nhk:classification:clock-type.cuckoo-clock]`
  - allowed_target_names: `[Đồng hồ chim cúc cu]`
  - allowed_binding:
    - operation: `nhk.media.bind`
    - receipt_operation: `nhk.media.binding.get`
    - role: `representative`
    - selection_source: `USER_EXPLICIT`
    - selection_policy: `PINNED`
  - allowed_operation_families:
    - media_usage_reconciliation
    - presentation_readiness
    - frontend_projection_readback
    - canonical_readback
  - restrictions:
    - exact IDs only; no fuzzy resolution
    - no Media, attachment or Classification creation
    - no duplicate binary, entity mutation, Graph relation or Knowledge mutation
    - no `media_ingest`, manual SQL, direct table writer or unrelated staging mutation
  - fail_closed_outside_scope: `true`

- Additional bounded staging acceptance scope for Media binding case 576:
  - environment: `staging`
  - host: `https://demo.1945.vn`
  - allowed_media_ids: `[01a0aefd-7e93-772c-98df-33f7abbc11e8]`
  - allowed_attachment_ids: `[576]`
  - allowed_asset_ids: `[01a0aefd-7e9c-757a-a728-4ca3e968b60f]`
  - allowed_target_ids: `[01a09e44-539a-7f1a-938a-d7d91bb689a3]`
  - allowed_target_types: `[classification]`
  - allowed_target_stable_keys: `[nhk:classification:clock-type.dong-ho-cong-cong]`
  - allowed_target_names: `[Đồng hồ công cộng]`
  - allowed_binding:
    - operation: `representative_bind`
    - entrypoint: `nhk.capture.ingest`
    - receipt_operation: `nhk.media.binding.get`
    - role: `representative`
    - selection_source: `USER_EXPLICIT`
    - selection_policy: `PINNED`
  - allowed_presentation:
    - title: `Đồng hồ công cộng`
    - alt_text: `Đồng hồ công cộng cổ với bộ máy cơ khí và hai chuông lớn`
    - caption: `Đồng hồ công cộng – bộ máy cơ khí với hai chuông lớn, gợi lại kỹ nghệ đo và báo giờ trong không gian cộng đồng.`
  - allowed_operation_families:
    - `media_usage_reconciliation`
    - `presentation_readiness`
    - `frontend_projection_readback`
    - `canonical_readback`
  - dynamic_scope_requirements:
    - server-issued Capture `capture_id` and exact `capture_fingerprint`
    - server-issued scope `fingerprint`, HMAC `signature`, `issued_at` and `expires_at`
    - exact binding packet matching the IDs, target, operation, role and selection fields above
    - runtime staging environment, signing secret, capability context and canonical final readback
  - restrictions:
    - exact IDs and exact stable key only; no wildcard or fuzzy target resolution
    - no new Media, attachment, MediaAsset or Classification
    - no duplicate binary, Graph mutation, Knowledge mutation or Source/Evidence mutation
    - no generic WordPress writer, direct SQL/DB, Governance bypass, Proposal Apply or second approval queue
    - no hard delete; replacement/removal semantics remain logical and out of this acceptance
  - fail_closed_outside_scope: `true`



- Development database is nhk_v3; integration database is nhk_v3_test.
- nhk_v3 permits health, smoke checks, schema inspection, non-destructive
  additions and UP migrations only. Never run DOWN, DROP, TRUNCATE or reset
  there.
- Destructive integration operations are allowed only on exact nhk_v3_test,
  guarded by TestDatabaseGuard.
- Never seed entities, backfill Graph edges, repair identity, assign public
  slugs or alter semantic records unless the separately governed contract and
  user-authorized task explicitly allow it.

## Workflow and quality gates

- Inspect git status before work and preserve existing changes. Never use
  git reset --hard, git clean -fd, git checkout -- ., or destructive restore.
- Read docs/architecture/V3_EXECUTION_STATE.md before each checkpoint and update
  it after the checkpoint. Read docs/architecture/V2_V3_PARITY_MATRIX.md before
  claiming parity.
- Use the smallest constitution-compliant vertical slice. Add relevant tests,
  run PHP lint, migration checks for schema changes, git diff --check and a
  secret review before a checkpoint commit.
- Do not downgrade tests or hide failures with broad catches that report
  success. Empty data, unavailable runtime, hydration loss and infrastructure
  failure must remain distinguishable.
- Do not commit local env files, credentials, dumps containing secrets, private
  keys, tokens or API secrets.
- Frontend work must follow the frontend law in the single Constitution:
  Vietnamese-first public copy, controlled typography/tokens, accessible
  semantic HTML, responsive layouts, real query services and honest empty/error
  states.

## Stop conditions

Stop and ask the user before irreversible real-data deletion, destructive
production migration, modifying V2 production, changing a locked architectural
invariant, resolving severe identity ambiguity, merging identities, proceeding
through unresolved CONSTITUTION_CONFLICT, or continuing without required
external credentials/infrastructure.

Never perform final production cutover autonomously; produce a Cutover Readiness
Report first. Pushes and merges remain subject to platform policy and quality
gates. Do not claim an external publish occurred unless verified.

V2 and demo.1945.vn may be read-only behavioral, structural, route and sample
references. They are not schema authority, normative architecture or
authorization to migrate article bodies. Tinhte may inform information
architecture and interaction patterns only; do not copy its branding, assets,
markup, styles or proprietary content.

## Autonomous execution

The repository may proceed through approved phases automatically only while the
work remains inside the Constitution. Continue through coherent vertical slices
with evidence, tests, lint, diff checks, secret review, execution-state updates
and logical commits. Human gates remain binding, including final production
cutover, destructive real-data operations, identity-risking merges, missing
credentials and unresolved constitutional conflicts.

The public experience is an editorial NHK discovery surface: Posts remain
editorial truth while Authority, Knowledge, Graph, Media and Video are queried
through application services. Public UI must not expose internal terms, fixtures,
unavailable modules or invented metrics/content. Projection work must not create
semantic types, relations or fields outside the runtime registry/contract.
