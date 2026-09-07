# NHK V3 Current Documentation Status Index

> **NON-NORMATIVE ROUTER / CURRENT STATUS — 2026-09-07.**
> This file is not a second Constitution. It routes later agents to current law,
> executable boundaries and the latest verified runtime state. If anything here
> conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution
> controls.

## 1. Authority and read order

Use this precedence when deciding current behavior:

1. `docs/constitution/NHK_V3_CONSTITUTION.md` — sole supreme normative authority.
2. Current approved contracts referenced by `docs/constitution/READ_FIRST.md`.
3. Executable registries/catalogs and application boundaries for vocabulary and
   capabilities actually present in the checked-out runtime.
4. Fresh runtime discovery and canonical owner read-back when the question is
   whether a capability or mutation actually works in that environment.
5. This index — compact current-status router.
6. `docs/architecture/V3_EXECUTION_STATE.md` and current machine-readable audit
   ledgers — dated execution evidence; later entries/overrides supersede older
   checkpoints where they conflict.
7. Numbered phase docs, plans/specs, old audits, V2 material and fixed
   test/tool-count statements — historical/reference evidence only unless a
   current contract explicitly incorporates them.

A newer timestamp alone never overrides the Constitution. An older present-tense
checkpoint never overrides a later executable registry or verified canonical
read-back.

## 2. Current semantic write and completion law

Semantic writes are exposed and in use for the registered Authority operations,
Knowledge, Source, Evidence, Media, Video, Graph relations and Governance
proposal lifecycle. The executable MCP catalog is the source of truth for the
exact currently registered tools; do not reuse a historical fixed tool count.

A create/ingest request may create only a governed Proposal/Draft. It is not a
canonical entity merely because the request returned successfully.

The canonical lifecycle is:

`proposal_create / ingest → submit → review → approval with binding fingerprints
→ eligibility → Controlled Apply → canonical owner read-back → idempotency
verification`.

`DRAFT`, `SUBMITTED`, `APPROVED`, `ready=true`, an apply response or HTTP 2xx is
not by itself `COMPLETED`. Completion requires the canonical owner to return the
expected identity, state/revision and mutation. Repeating the same durable intent
must not duplicate the canonical record, active relation or proposal.

### Reconcile before create

Before any new Authority node or Knowledge claim, read current canonical state
and classify the intent:

- `EXACT_EXISTING` → reuse/update/enrich the existing canonical record;
- `MERGE_CANDIDATE` → governed merge/rekey/update after identity review;
- `RELATED_BUT_DISTINCT` → preserve separate identities and use only a
  registered relation when justified;
- `NO_EXISTING_CANONICAL_RECORD` → only then may creation proceed;
- `UNCERTAIN` → defer with evidence/reason/owner action.

Lexical/fuzzy/keyword/name similarity is not canonical identity proof.

### No orphan semantic data

A new Knowledge claim must have its intended canonical subject/context resolved
before creation. If a genuinely missing Authority node is required, create it
through Governance, apply it, and read it back before creating the claim. If the
subject cannot be resolved or safely created, defer the claim. A detached claim
that is intended to be attached later is not a completed semantic ingest.

## 3. Canonical owner and current capability snapshot

| Area | Canonical owner / current boundary | Current status |
|---|---|---|
| WordPress Article/Post | native `wp_posts` owns editorial title/body/excerpt/category/order/public editorial URL | implemented editorial boundary; Article workflow references canonical semantic owners and never turns body/prose into semantic truth automatically |
| Editorial/research Note | no separate canonical semantic Note owner registered | workspace/editorial context only unless explicitly promoted through canonical resolution, Source/Evidence/Knowledge and Governance |
| Authority | nine registered types: brand, model, variant, movement, music, component, classification, specimen, product | governed reads/writes implemented; create probe for Classification is verified through approval + eligibility, actual new-node apply intentionally untested |
| Knowledge | atomic canonical claims | writer exposed/used; reconcile existing claims first; no orphan claims; canonical read-back required |
| Source | canonical provenance source + locator | writer exposed/used; separate from Authority and Knowledge; canonical read-back required |
| Evidence | canonical Claim↔Source support link using supports/contradicts/qualifies | writer exposed/used; requires existing canonical Claim+Source; visibility/public-read policy separate from governed internal verification |
| Graph | only semantic relation persistence | governed create/retire/reactivate implemented; canonical relation read-back and bounded neighborhood available; relation source binding defect resolved |
| Media | canonical Media identity | separate from MediaAsset/MediaUsage/WP attachment; governed `nhk.media.ingest` current boundary; source-original private/protected and eligible derivatives remain under same Media |
| Video | canonical external Video reference | governed YouTube ingest/read implemented; same external ID reuses canonical Video; guided relation workflow can resolve/reuse/create provenance chain and does not require manual proposal/Evidence UUID entry |
| Specimen | one physical object | separate canonical Authority family; physical truth owner |
| Product | one listing/offer/context | separate canonical Authority family; not Specimen identity |
| Governance | proposal, review/approval binding, eligibility, Controlled Apply, audit | implemented for registered operations; completion remains owner read-back dependent |
| Public Projection / Frontend | read model/presentation only | never creates canonical truth; Graph/owner data is projected after eligibility |

## 4. Authority create runtime status

Authority creation is not a generic blocker. Runtime probe proposal
`01a07c4e-14b2-734e-8264-3f04b37e5fe4` for
`operation=create, entity_type=classification` verified:

- proposal create: PASS;
- submit: PASS;
- review/fingerprint binding: PASS;
- approval: PASS;
- eligibility: PASS (`ready=true`, `reasons=[]`).

The probe was intentionally **not applied** to avoid creating a junk canonical
Classification. Therefore current evidence does **not** yet claim the complete
new-node sequence `Controlled Apply → generated canonical UUID → entity_get /
resolver read-back → immediate relation use`. That remaining runtime proof is
performed only when a real needed Authority node must be created.

A create Proposal may use an entity-type subject marker before a new canonical
UUID exists. That is not the old `relation_create` source-binding defect.

## 5. Graph relation and registry status

Current executable predicates are exactly the current registry entries:

- `about`;
- `depicts`;
- `model_of`;
- `variant_of`;
- `uses_movement` (Variant → Movement);
- `supports_music`;
- `configured_with_music`;
- `observed_playing_music`.

`relation_create` preserves typed canonical endpoints:
`source_type/source_uuid`, registered `predicate`,
`target_type/target_uuid`. The historical repository-hydration defect that could
hydrate `subject_id` as an entity-type string instead of the relation source UUID
is **RESOLVED**. Current `WpdbProposalRepository` hydrates relation subjects from
`payload.source_uuid` (legacy source-key fallback only for compatibility), and
runtime relation flows have completed through canonical Graph read-back.

Do not retain “Graph relation source binding is a global blocker”, “all Graph
mutation must stop”, or “Graph canonical read-back is unavailable” as current
status. Those are historical descriptions where dated evidence requires them.

Current registry gaps remain fail-closed:

- `classified_as` — **REGISTRY_GAP**. Do not use `about` to fake
  Model/Variant→Classification membership.
- dedicated Product↔Specimen persistence relation — **REGISTRY_GAP**.
- Model→Movement is not separately authorized when the registry only permits
  Variant→`uses_movement`→Movement; do not invent or substitute a predicate.

Broad `about` must not impersonate classification membership, structural
parentage, configuration, movement use, Product–Specimen ownership or another
unregistered semantic meaning.

## 6. Cuckoo current runtime correction

Canonical Classification:

- UUID: `01a07614-832d-7f27-959c-74eb0cd63f3e`
- stable key: `nhk:classification:clock-type.cuckoo-clock`
- canonical name: `Đồng hồ chim cúc cu`

The 10 core Cuckoo Knowledge claims have completed the real governed relation
flow `Knowledge → about → Classification Cuckoo`, including create/submit/review/
approval/eligibility/Controlled Apply and canonical Graph read-back. For this
specific group the former Knowledge→Classification `RELATION_GAP` is
**COMPLETED / RESOLVED**.

This does **not** prove or authorize
`Model/Variant → classified_as → Classification`; `classified_as` remains a
registry gap. Do not confuse Knowledge `about` with classification membership.

The exact individual claim/proposal/edge IDs are not invented in this router;
consult fresh canonical inventory/runtime receipts when item-level identity is
required.

## 7. Knowledge / Source / Evidence ingest lifecycle

The required current sequence for new factual semantic material is:

`Source/Evidence research → canonical subject/target resolution → reconcile
existing canonical entities/claims → create/update/merge Authority if genuinely
needed → Authority canonical read-back → Knowledge ingest → Knowledge canonical
read-back → governed Graph attachment → Source/Evidence attachment → Graph +
Knowledge + Evidence canonical read-back → idempotency check`.

Source is the canonical provenance source, not an Authority shortcut. Evidence
links an existing Claim to an existing Source with `supports`, `contradicts` or
`qualifies`. Do not stuff arbitrary provenance into Knowledge as a substitute
for a Source/Evidence chain where the contract requires those owners.

Public visibility and internal validity are separate. Active PRIVATE/HIDDEN
Source/Evidence may be used by governed internal verification according to the
current policy without exposing their raw payload publicly. Public-safe
Knowledge projection remains independently policy-gated.

## 8. Video workflow status

Video canonical identity is separate from Media, thumbnail, Knowledge, Source,
Evidence and Article. YouTube intake retains normalized canonical source URL,
platform/external ID, provenance/source state, user hint, editorial instruction,
optional thumbnail Media binding and resolved intended relations.

Current guided relation workflow:

`canonical Video → resolve canonical target → resolve/reuse/create deterministic
private YouTube Source → resolve/reuse provenance Claim → resolve/reuse Evidence
→ relation proposal → submit/review/approval/eligibility → Controlled Apply →
canonical Graph/Video read-back → projection/frontend`.

Normal operators are not required to type a Video proposal UUID or Evidence UUID;
the orchestration resolves them. Generic transcript/Knowledge extraction remains
a separate planning seam until a candidate is actually governed and applied.

Replay of the same YouTube external identity must reuse the canonical Video and
stable intent; it must not create a duplicate Video merely because title,
Shorts/watch URL form or tracking query differs.

## 9. Media / Image workflow status

`Media`, `MediaAsset`, `MediaUsage` and WordPress attachment are distinct.
Current intake uses the governed Media application boundary and must reconcile a
reusable canonical Media before creating another semantic identity. Checksum,
filename, URL and upload time are duplicate/presentation signals, not canonical
merge proof.

Conceptual view/detail context may include values such as `front`, `back`,
`movement`, `dial`, `hands`, `pendulum`, `gong`, `hammer`, `plate`, `marking`,
`logo` and `case_detail`, but only the executable role/detail registry decides
which identifiers are valid. Unknown values fail closed; a view/detail label is
not itself Knowledge or a Graph relation.

The flow is binary/storage validation → one canonical Media → MediaAsset
source/derivatives → contextual MediaUsage/role → resolved canonical subject →
registered Graph/projection only when that separate contract requires it.
WordPress attachment is storage/projection, not semantic authority.

## 10. Article and Note status

WordPress remains the owner of Article editorial content and URLs. Article
research must resolve/reuse canonical Authority, Knowledge, Source/Evidence,
Media and Video. New facts discovered while drafting remain research input until
they independently pass reconcile-before-create and the governed semantic
lifecycle. Frontend templates and Article payloads cannot mint semantic truth.

No separate canonical Note domain was found in the current registered owner
map. Editorial notes and research notes therefore remain context/workspace data
unless explicitly promoted through the existing semantic owners.

## 11. MCP / Admin and frontend/read-model status

MCP/Admin are orchestration/control-plane adapters, not canonical owners. Current
executable capabilities include canonical/Graph inventory, relation dry-run,
authenticated deterministic relation batch apply, semantic resolver, bounded
`nhk.entity.neighborhood`, and the currently registered semantic ingest/proposal
writers. Fresh runtime discovery still determines environment availability.

Frontend uses canonical Graph/read models and must not keyword-search a fake
relationship. Related reads distinguish direct, inbound/outbound direction and
derived bounded neighborhood. The Graph bounded neighborhood infrastructure is
implemented. Where a specific frontend dossier/section does not yet consume the
full eligible neighborhood/path policy, classify that as a
`PARTIAL_FRONTEND_GAP`, not “Graph unavailable”.

## 12. Deferred / unfinished ledger law

Current work may use intermediate states such as `PENDING_RESEARCH`,
`EVIDENCE_GAP`, `REGISTRY_GAP`, `RELATION_GAP`, `LEXICAL_GAP`, `FRONTEND_GAP`,
`RUNTIME_BLOCKED` and `NEEDS_REVIEW`. Final outcomes are:

- `COMPLETED`;
- `DEFERRED_WITH_REASON`;
- `BLOCKED_WITH_OWNER_ACTION`.

A deferred entry should retain the source/provenance, proposed canonical
subject and entity type, proposed relation, evidence, reason, registry blocker,
existing proposal ID, canonical IDs already resolved, and deterministic rerun
instructions. After a rate limit/runtime interruption, reuse the existing
proposal/idempotency binding whenever the durable intent is unchanged; do not
mint a duplicate proposal just to retry.

## 13. Historical-document interpretation

Historical statements must remain available when audit history matters, but they
must not be read as current blockers. Explicitly treat as dated/resolved when a
later executable/runtime source proves otherwise:

- “WRITE semantic is not exposed”;
- “Source/Evidence/Knowledge writer does not exist”;
- “relation `subject_id` is still globally broken”;
- “all Graph mutation is blocked”;
- “Cuckoo core Knowledge is not related to Classification”;
- “Graph canonical read-back has never run”;
- fixed MCP/Ability counts from older checkpoints.

Do not rewrite historical events to pretend they never happened; mark them
`HISTORICAL`, `RESOLVED`, or superseded by this index/current runtime.

## 14. Intentional remaining gaps

- `classified_as` Classification membership predicate is not registered.
- dedicated Product–Specimen canonical persistence relation is not registered.
- actual creation/apply/read-back of a genuinely new Authority node remains to
  be runtime-proven when a real node is needed; the proposal lifecycle through
  eligibility is already verified.
- full physical Graph completeness/backfill remains data/runtime-specific even
  though governed relation creation/apply is implemented.
- Media→Living Knowledge automatic enrichment is not authorized merely from
  MediaUsage/OCR/recognition.
- automatic Article body rewrite from Knowledge remains prohibited; use
  suggestion/governed editorial flow.
- Public Identity and individual frontend dossiers still have target-environment
  activation/coverage gates described in their current contracts/ledger.
- capability availability in a specific target environment still requires fresh
  discovery/read-back even when code exists.

A gap is never permission to invent a shortcut or overload `about`.

## 15. Downstream operating rule

Before implementation or data mutation:

1. follow `READ_FIRST.md`;
2. resolve the canonical owner and executable registry/capability;
3. research and reconcile existing canonical data before any create;
4. resolve subject/context before creating Knowledge;
5. fail closed on ambiguity or missing relation/owner;
6. use the full Governance lifecycle for semantic mutation;
7. read back from the canonical owner;
8. verify second-run idempotency before claiming completion.

This index remains a compact router. Detailed normative law belongs in the
Constitution and approved contracts.
