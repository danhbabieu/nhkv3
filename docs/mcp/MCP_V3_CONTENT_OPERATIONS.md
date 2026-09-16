# MCP V3 CONTENT OPERATIONS

Status: runtime audit and contract-safe documentation checkpoint, 2026-09-02.

This document is the shared operating guide for ChatGPT/Codex clients talking
to NHK V3. It describes the runtime that actually exists. It does not authorize
new semantic types, predicates, fields, operations, taxonomy, metadata or data
population.

## 1. MCP architecture

The MCP endpoint is the WordPress REST route `/wp-json/nhk/v1/mcp` using JSON-RPC
2.0 and Streamable HTTP negotiation. `McpTransport` validates the protocol and
tool arguments; `McpReadHandler` performs read orchestration; governed writes
are handed to `McpGovernanceHandler` and then to Governance's
Submit → Approve → Eligibility → Controlled Apply lifecycle.

MCP is not a second domain store. The application/domain layer owns validation,
canonical identity, registry checks, optimistic revision, idempotency,
provenance/readiness and Graph rules. WordPress `wp_posts` remains the editorial
source of truth for title, body, author, dates, URL and publish status.

Current wire requirements:

- modern protocol version: `2026-07-28`;
- `Accept` must contain both `application/json` and `text/event-stream` for
  modern requests;
- optional `Origin`, `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name` are
  checked when present;
- malformed tool arguments fail before business dispatch with JSON-RPC
  `-32602`;
- governed tools require their WordPress capability;
- notification `notifications/initialized` returns HTTP 202 with no body.

## 2. Tool catalog thực tế

Runtime `McpToolCatalog::tools()` exposes exactly 19 tools. `kind=mutation` is
always `governed=true`; read tools do not mutate semantic state. “Revision” in
the table means whether the current tool accepts or enforces an optimistic
revision directly; proposal lifecycle revisions are separate from target
entity/edge revisions.

| TOOL | DOMAIN | READ/WRITE | GOVERNED | REVISION | GRAPH | STATUS |
|---|---|---|---|---|---|---|
| `nhk.search` | Post + public semantic search | READ | No; read-only | N/A | No | READY, bounded page/limit and public/readiness filters |
| `nhk.semantic.resolve` | Authority context lookup | READ | No; read-only | N/A | No; returns empty relation bucket | READY for Authority UUID/stable-key/exact name/alias; ambiguity fails closed |
| `nhk.entity.get` | Authority | READ | No; read-only | N/A | No raw edges | READY by registered type + canonical UUID |
| `nhk.media.get` | Media + public assets/usages | READ | No; read-only | N/A | No raw Graph edge | READY only for active `ready` Media and deliverable PUBLIC assets |
| `nhk.media.ingest` | Media, MediaAsset, MediaUsage | WRITE | Yes | Proposal lifecycle; entity revision is created by apply | Usage is placement, not Graph | READY for governed metadata ingest; binary upload is not included |
| `nhk.video.ingest` | Video external reference | WRITE | Yes | Proposal lifecycle; entity revision is created by apply | Video is a registered endpoint; no edge is created by this tool | READY for validated YouTube identity |
| `nhk.video.get` | Video | READ | No; read-only | N/A | No raw Graph edge | READY for active valid public reference |
| `nhk.knowledge.get` | Knowledge Claim + public Evidence | READ | No; read-only | N/A | No raw Graph edge | READY for active/public claim and evidence chain |
| `nhk.source.get` | Source + public Evidence | READ | No; read-only | N/A | No raw Graph edge | READY for active/PUBLIC Source chain |
| `nhk.evidence.get` | Evidence + public Claim/Source | READ | No; read-only | N/A | No raw Graph edge | READY for active/PUBLIC endpoints |
| `nhk.knowledge.ingest` | Knowledge Claim | WRITE | Yes | Proposal lifecycle; target revision applies to updates through generic proposal | No edge by this tool | READY for governed claim creation |
| `nhk.source.ingest` | Source | WRITE | Yes | Proposal lifecycle; target revision applies to updates through generic proposal | No edge by this tool | READY for governed Source creation |
| `nhk.evidence.ingest` | Evidence | WRITE | Yes | Proposal lifecycle; target revision applies to updates through generic proposal | Claim/Source are Evidence endpoints, not Graph edges | READY for governed Evidence creation |
| `nhk.proposal.create` | Governance command envelope | WRITE | Yes | `expected_revision` and proposal revision are explicit | `relation_create` can carry existing Graph relation operation | PARTIAL; generic schema defers final operation/type validation to apply |
| `nhk.proposal.submit` | Governance | WRITE | Yes | Proposal revision increments | N/A | READY |
| `nhk.proposal.approve` | Governance | WRITE | Yes | Approval binds proposal revision/fingerprints | N/A | READY |
| `nhk.proposal.reject` | Governance | WRITE | Yes | Proposal revision increments | N/A | READY |
| `nhk.proposal.eligibility` | Governance | READ | Capability-gated (`nhk_view_governance`) | Checks target/dependency revisions | N/A | READY |
| `nhk.proposal.apply` | Governance + target domain | WRITE | Yes | Controlled Apply checks expected target revision and fingerprints | Relation operations execute through GraphService | READY for currently implemented executor branches |

Tool 19 is `nhk.semantic.resolve`. It is contract-compliant, read-only and
must remain in the catalog. The two integration assertions that expected 18
were stale and are now corrected to 19.

The catalog order is also the current wire order:
`nhk.search`, `nhk.semantic.resolve`, `nhk.entity.get`, `nhk.media.get`,
`nhk.media.ingest`, `nhk.video.ingest`, `nhk.video.get`, `nhk.knowledge.get`,
`nhk.source.get`, `nhk.evidence.get`, `nhk.knowledge.ingest`,
`nhk.source.ingest`, `nhk.evidence.ingest`, `nhk.proposal.create`,
`nhk.proposal.submit`, `nhk.proposal.approve`, `nhk.proposal.reject`,
`nhk.proposal.eligibility`, `nhk.proposal.apply`.

## 3. Use-case capability matrix

| ChatGPT use case | Current capability | Result |
|---|---|---|
| Find canonical entity | `nhk.semantic.resolve` for Authority; UUID-only `get` for Media/Video/Claim/Source/Evidence | PARTIAL |
| Read canonical entity | `nhk.entity.get` and domain `get` tools | READY for exposed read boundaries |
| Create/update entity | Create through ingest tools or generic governed proposal; update/retire/reactivate through generic proposal | PARTIAL; no dedicated typed update tools |
| Read Source/Evidence | `nhk.source.get`, `nhk.evidence.get` | READY for public chain |
| Create Knowledge claim | `nhk.knowledge.ingest` | READY |
| Read/create relation | Create through governed `relation_create` proposal; raw read exists only in administrator-only REST Graph API | PARTIAL |
| Create draft Post | No MCP Post tool or Post application command | BLOCKED |
| Update Post | No MCP Post tool | BLOCKED |
| Upload/find Media | Ingest accepts storage/asset metadata; no binary upload and no lookup-by-name/checksum tool | PARTIAL/BLOCKED for upload |
| Attach MediaUsage | Nested in `nhk.media.ingest`; no standalone add-usage MCP operation | PARTIAL |
| Product | Registered Authority type, generic `entity.get`/proposal path | PARTIAL |
| Specimen | Registered Authority type, generic `entity.get`/proposal path | PARTIAL |
| Album | No V3 type, registry or contract | `SEMANTIC_GAP` |
| Video | Governed ingest, UUID read, validated YouTube external identity, optional thumbnail Media UUID | READY for current contract; public populated detail remains data-gated |
| Publish | Controlled Apply publishes semantic proposal; no Post publish operation in MCP | PARTIAL/BLOCKED for editorial publish |
| Read-back verification | Domain `get` after apply; no unified post/graph/read-back tool | PARTIAL |
| Frontend verification | External `tools/frontend-route-smoke.php` and browser QA; no MCP tool | PARTIAL |

## 4. Post workflow

The required editorial sequence cannot currently be executed end-to-end through
MCP. The safe boundary that does exist is:

1. resolve Authority context with `nhk.semantic.resolve`;
2. read claims/sources/evidence and existing semantic entities;
3. use the native WordPress editorial interface/API for Post title/body/excerpt
   and draft/publish status;
4. use governed proposals for semantic entities and Graph links;
5. verify semantic records with their domain `get` tools and verify the Post
   through native WordPress read APIs/browser checks.

There is no Post endpoint contract in the MCP catalog for create/update/publish,
and no MCP orchestration that atomically combines native Post editing with
Governance and Graph. Adding one requires an explicit Post application contract
and tool design; it is not implemented by this audit.

The existing `wp_post` Graph endpoint contract is narrower: its key is
`<blog_id>:<post_id>`, normalization accepts only positive numeric components,
and existence is checked against the current site's `WP_Post` record. This
allows semantic Graph links to an existing Post (including a draft) but does
not create, update or publish the Post.

## 5. Authority workflow

Authority types are resolved by canonical UUID first, stable key second, then
exact canonical name/alias. A name match with more than one active candidate
returns candidates and an ambiguity marker; it is never auto-resolved.

Reads use `nhk.entity.get` and only registered public payload fields are
returned. Creation/update/lifecycle changes use `nhk.proposal.create` with an
existing operation and the normal Governance lifecycle. The target entity's
`expected_revision` is required for update/lifecycle operations.

Current Authority registry:

| TYPE | GRAPH | ALLOWED FIELDS | FIELD TYPES / FORMATS |
|---|---:|---|---|
| `brand` | yes | `aliases`, `description`, `country`, `founded_year` | array, string, string, int |
| `model` | yes | `brand_uuid`, `aliases`, `description`, `launch_year` | `brand_uuid` UUID; array, string, int |
| `variant` | yes | `model_uuid`, `aliases`, `description`, `reference` | `model_uuid` UUID; array, string, string |
| `movement` | yes | `manufacturer`, `caliber`, `description`, `frequency_hz`, `jewels` | string, string, string, float, int |
| `music` | yes | `artist`, `album`, `description`, `release_year` | string, string, string, int |
| `component` | yes | `kind`, `manufacturer`, `description` | string, string, string |
| `classification` | yes | `family`, `description` | string, string |
| `specimen` | yes | `model_uuid`, `serial_number`, `acquired_at`, `notes` | `model_uuid` UUID; string, string, string |
| `product` | yes | `specimen_uuid`, `vendor`, `url`, `price`, `currency`, `availability` | `specimen_uuid` UUID, `url` HTTP(S); string, float, string, string |

## 6. Knowledge / Source / Evidence workflow

`nhk.source.ingest`, `nhk.knowledge.ingest` and `nhk.evidence.ingest` create
Governance proposals. The client submits, approves with the returned content
and dependency fingerprints, checks eligibility, then applies. Evidence
creation requires existing Claim and Source UUIDs and preserves the
Claim–Source boundary. Public reads require active records and explicit public
Source/Evidence visibility; Knowledge claims with unverified/private/draft
status are hidden from public reads.

Profiles currently in code are the enumerated values, not an open-ended client
vocabulary:

- Claim types: `fact`, `specification`, `history`, `technical`, `provenance`,
  `other`.
- Source types: `publication`, `website`, `archive`, `catalog`, `interview`,
  `other`.
- Evidence relations: `supports`, `contradicts`, `qualifies`.
- Source/Evidence visibility metadata: `PUBLIC`, `PRIVATE`, `HIDDEN`.

## 7. Graph workflow

Graph is the only relation persistence. Relation creation, retirement and
reactivation are governed proposal operations and execute through `GraphService`.
`GraphApi` is an administrator-only raw read surface because it returns endpoint
keys, state and revisions. No MCP Graph read tool is currently registered.

### Runtime endpoint types

The full MCP boot registers 15 Graph endpoint types:
`wp_post`; the nine registry-backed Authority types (`brand`, `model`, `variant`,
`movement`, `music`, `component`, `classification`, `specimen`, `product`);
and `media`, `video`, `knowledge`, `source`, `evidence`.

### Relationship matrix

| SOURCE | PREDICATE | TARGET | CARDINALITY | DIRECT/DERIVED | EVIDENCE | GOVERNED OPERATION | MCP READ TOOL | MCP WRITE TOOL |
|---|---|---|---|---|---|---|---|---|
| all 15 registered endpoint types | `about` | all 15 registered endpoint types | outbound MANY / inbound MANY | DIRECT | None enforced in Graph edge; provenance is separate | `relation_create`, `relation_retire`, `relation_reactivate` | None; admin-only REST Graph API | `nhk.proposal.create` + proposal lifecycle |
| `media` | `depicts` | all 15 registered endpoint types | outbound MANY / inbound MANY | DIRECT | None enforced in Graph edge; provenance is separate | `relation_create`, `relation_retire`, `relation_reactivate` | None; admin-only REST Graph API | `nhk.proposal.create` + proposal lifecycle |

No third predicate is present. The registry has no predicate-specific evidence
requirement, no derived relation materialization and no Album relation.

## 8. Media workflow

Media identity, MediaAsset binary metadata and MediaUsage placement are separate:

1. `nhk.media.ingest` validates stable key, name, readiness, asset packet and
   usage packet before creating a governed proposal;
2. Controlled Apply calls `MediaService::ingest`;
3. MediaAsset stores storage key/checksum/MIME/size/dimensions/visibility;
4. MediaUsage stores endpoint type/key, role and sort order;
5. `nhk.media.get` returns only active ready Media, deliverable PUBLIC assets and
   reader-safe usage fields.

The current tool does not upload bytes, resolve a local file, search by checksum,
or add a usage independently after ingest. Checksum detects a duplicate candidate
but never merges semantic Media identities. Asset publication remains explicit
and fail-closed.

## 9. Product / Specimen workflow

`specimen` is a concrete physical object. `product` is a listing/offer and is
never the physical object's identity. Both are registered Authority types with
UUID identity and optimistic revision.

The current Product contract has a `specimen_uuid` payload field, while the
generic `about` predicate also permits `product → specimen`. The architecture
documents these as alternative representations, but runtime validation does
not enforce mutual exclusion or prove equivalence. A client can therefore
persist the same Product–Specimen fact in payload and Graph, creating duplicate
semantic truth.

`CONSTITUTION_CONFLICT`: `CanonicalEntityTypeCatalog` permits `product.specimen_uuid`
and `PredicateRegistry` permits `product --about--> specimen`, without a
contract-level invariant selecting exactly one canonical owner. Do not create or
repair Product–Specimen links automatically. An architecture decision must name
the canonical owner, relation semantics, cardinality, provenance and migration
rule before this flow is expanded.

## 10. Album status / workflow

Album has no canonical V3 entity type, no Authority registry entry, no Graph
endpoint resolver, no predicate, no repository, no application service, no MCP
tool and no public contract. Search found only generic gallery/collection
presentation references and the `music.album` field, which is not an Album
entity.

`SEMANTIC_GAP`: Album cannot be safely classified as a V3 editorial container,
Media collection, legacy CPT or projection from the current runtime. Do not
create an Album entity, taxonomy, relation or MCP tool. A future contract must
first decide its owner and identity boundary.

## 11. Video workflow

`nhk.video.ingest` accepts a URI, title, metadata and optional thumbnail Media
UUID. The domain currently supports YouTube watch, short, embed and `youtu.be`
forms and stores one canonical watch URL plus platform/external ID. The
external reference is the Video identity; Media is not substituted for it.

Video is a Graph endpoint and can participate in the two registered predicates
through a governed relation proposal. Thumbnail Media is currently a typed
Video field, not an implicit Graph edge. Public REST/MCP/theme reads expose only
validated external-reference display fields. No local MP4 is downloaded.

## 12. Governance

There is no standalone `OperationRegistry` class in the current runtime. The
effective operation allowlist is distributed across `AuthorityProposalExecutor`,
the domain services and Governance lifecycle:

| DOMAIN | EFFECTIVE EXISTING OPERATIONS |
|---|---|
| Authority | `create`, `ingest`, `rename`, `update`, `retire`, `reactivate` |
| Media | `ingest` |
| Video | `ingest`, `update`, `retire`, `reactivate` |
| Knowledge/Source/Evidence | `create`, `ingest`, `update`, `retire`, `reactivate` |
| Graph | `relation_create`, `relation_retire`, `relation_reactivate` |
| Proposal lifecycle exposed by MCP | `create`, `submit`, `approve`, `reject`, `eligibility`, `apply` |

The generic proposal schema accepts strings for `operation` and `entity_type`; the
executor/domain service is the final guard and rejects unsupported combinations
at apply. Do not treat a draft proposal's acceptance as authorization for a new
operation. All semantic writes must retain idempotency key, fingerprints,
expected revision, capability checks, durable audit and controlled transaction.

## 13. Error codes and fail-closed behavior

Protocol-level errors currently exposed by `McpTransport`:

| CODE | MEANING |
|---:|---|
| `-32600` | Invalid JSON-RPC request |
| `-32601` | Unknown MCP method or tool |
| `-32602` | Missing/unknown/malformed tool argument, UUID, URI, enum or bound |
| `-32003` | Origin or capability denied |
| `-32020` | Streamable HTTP Accept or assertion-header mismatch |
| `-32022` | Unsupported protocol version |

Known domain/governance exceptions are caught as an MCP tool error result with
`isError=true`; the underlying application/domain exception remains typed in
the server. Clients must treat `isError=true`, null reads, `404/503` read
boundaries and ambiguity reports as non-success. No client should retry a
revision/idempotency conflict with altered content under the same key.

## 14. Read-back verification

For an applied proposal, use the `result_entity_uuid` returned by
`nhk.proposal.apply` and call the domain read tool:

- Authority: `nhk.entity.get` with the registered type and UUID;
- Media: `nhk.media.get` and confirm only expected public/deliverable assets;
- Video: `nhk.video.get` and confirm canonical URL/external ID;
- Knowledge/Source/Evidence: corresponding `get` tool and public chain;
- Graph: currently use administrator-only REST Graph read; there is no MCP
  Graph read tool;
- Post: currently use native WordPress read/browser verification; there is no
  MCP Post read-back tool.

Verify identity, state, revision-sensitive result, relation direction and
visibility. A successful proposal apply is not proof that a public projection
is available; public readiness/visibility and URL checks remain separate.

## 15. End-to-end example: “Biên soạn và đưa bài lên web, xây chặt các quan hệ liên quan”

The following is the maximum contract-safe workflow today. The Post portion is
explicitly native WordPress because the MCP Post contract does not exist.

```text
1. nhk.semantic.resolve
   context: { brand: { name: "Odo" }, model: { stable_key: "..." } }
   Stop if a type is missing, conflicting or ambiguous.

2. nhk.entity.get / nhk.knowledge.get / nhk.source.get / nhk.evidence.get
   Read canonical facts and public evidence. Do not copy an article body into
   Knowledge or Graph.

3. Native WordPress editorial API/UI
   Create a draft Post and later publish its native title/body/excerpt/status.
   This step is outside the current MCP catalog.

4. nhk.knowledge.ingest, nhk.source.ingest, nhk.evidence.ingest
   Create governed proposals with explicit provenance/visibility. For each:
   submit → approve with returned fingerprints → eligibility → apply.

5. nhk.proposal.create
   Build a `relation_create` proposal using only `about` or `depicts`, with
   resolver-valid source/target endpoint types and keys. Submit → approve →
   eligibility → apply. Do not invent a third predicate.

6. nhk.media.ingest / nhk.video.ingest
   Use only the existing Media asset/usage packet and Video external-reference
   fields. Binary upload and Album are not implied.

7. Read back
   Call the relevant domain get tools, native Post read, and administrator-only
   Graph REST read where authorized. Then run frontend route/browser verification.
```

If the requested article workflow requires MCP-native Post create/update/publish,
binary upload, Graph read, independent MediaUsage mutation, Album, or a
Product–Specimen canonical fact, stop with the recorded status rather than
inventing a contract.
