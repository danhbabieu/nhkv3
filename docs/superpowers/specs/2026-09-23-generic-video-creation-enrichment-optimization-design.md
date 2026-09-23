# Generic Video Creation, Enrichment, Optimization and Recovery Design

**Date:** 2026-09-23

**Status:** Design approved in chat; written-spec review pending

## Goal

Make the canonical NHK V3 Video lifecycle generic for every future Video:
short input enters through Capture, canonical subject and external identity
remain authoritative, existing Knowledge is reused when available, optional
enrichment does not deadlock owner creation, public copy is reader-safe and
bounded repair converges, uncertain mutations reconcile idempotently, and
optimization/publication are proven by canonical and public read-back.

The Odo Jacquemart and public-clock incidents remain regression fixtures only.
No production branch may depend on their title, UUID, URL, YouTube ID, or
language.

## Constitutional boundaries

- `nhk.capture.ingest` remains the only normal new-submission boundary.
- Capture owns request identity and lifecycle orchestration; Video owns its
  canonical Video package; WordPress owns Article editorial content; Knowledge,
  Source/Evidence, Graph, Governance, Public Identity and SEO retain their
  existing owners.
- Direct Video, Knowledge, relation, publication and database writers remain
  internal guarded boundaries and cannot be used as operator shortcuts.
- No legacy Article-body migration, semantic backfill, production mutation,
  destructive database operation, or staging mutation outside an explicitly
  supplied signed exact scope is authorized.
- No new entity type, field, predicate, relation type, identity system or
  parallel persistence owner is introduced.

## Problem and failure model

The current system has most of the domain boundaries in place, but the final
generic contract requires one coherent lifecycle across them. Two concrete
failures define the acceptance suite:

1. A public-copy repair path allowed internal NHK terminology to survive a
   compose/repair/revalidation cycle.
2. A canonical Capture mutation returned `{}` at the connector boundary. A
   later empty search could not establish whether persistence occurred, so the
   caller must not interpret it as either confirmed failure or permission to
   create a duplicate.

Mutation outcomes are therefore explicitly classified as:

- `SUCCESS_WITH_READBACK`: the response contains a usable canonical identity,
  revision, status/readiness and the required read-back evidence.
- `FAILED_CONFIRMED`: the operation failed before persistence or the canonical
  read-back proves no requested mutation was accepted.
- `OUTCOME_UNKNOWN`: response loss, empty/malformed response, timeout,
  connection reset or serialization failure after dispatch leaves canonical
  state unresolved.

`OUTCOME_UNKNOWN` always invokes bounded reconciliation with the original
identity. It never creates a second Capture, generates a new idempotency key,
manually creates a Video, or bypasses Governance.

## Canonical lifecycle

The implementation exposes or preserves these conceptual phases, even where
existing adapters combine internal calls:

```text
SOURCE → UNDERSTAND → CORE → STRATEGY → ENRICH → COMPOSE → VALIDATE
→ REPAIR → REVALIDATE → GOVERN/APPLY → OWNER READ-BACK → OPTIMIZE
→ FINAL VALIDATE → PROJECT/PUBLISH → PUBLIC READ-BACK → LEARN
```

The lifecycle must distinguish at least `OWNER_CREATED`,
`EDITORIALLY_READY`, `SEO_READY`, `PUBLICATION_READY`, `PUBLISHED` and
`PUBLIC_READBACK_VERIFIED` (or the repository's equivalent vocabulary).
Owner existence alone is never publication success.

## Identity and reconciliation design

The existing identity boundaries are reused in this precedence order:

1. persisted/user-confirmed authoritative subject;
2. explicit canonical UUID or stable key;
3. strong semantic resolution;
4. canonical alias resolution;
5. title/body lexical candidates.

Once a subject packet is resolved, downstream stages consume that immutable
packet and do not re-resolve the primary subject from weaker text or
enrichment signals.

External Video identity uses the existing normalized external-artifact
abstraction and YouTube normalizer. Watch URLs, short URLs, Shorts URLs and
query-parameter variants resolve to one stable external identity. The
normalization is generic and is not implemented as a YouTube-only second
identity store.

On uncertain outcome, reconciliation uses the original idempotency key,
Capture ID when known, request fingerprint, normalized external identity,
canonical source ID, owner identity, source URL and subject relation through
existing repositories/ports. It distinguishes these states:

- Capture and Video exist: reuse both and continue at the missing phase.
- Capture exists and Video is absent: resume the same Capture and create only
  its missing Video child through the canonical path.
- Capture and Video are partial: reconcile the same owner lifecycle using
  current revisions and state tokens.
- No Capture and no owner after authoritative checks: replay with the same
  deterministic identity.
- Conflicting owners: fail closed and return a Governance/repair diagnostic.

## Enrichment and Knowledge safety

After authoritative subject resolution, the system retrieves bounded canonical
Knowledge/Claims through subject context and registered Graph reachability,
then applies Claim/Evidence/scope eligibility. Graph discovery supplies
candidates; it never grants truth by itself.

Dependency classification remains:

- `CRITICAL_IDENTITY`;
- `REQUIRED_FACTUAL_DEPENDENCY`;
- `OPTIONAL_ENRICHMENT`;
- `PUBLICATION_ONLY`.

Sparse or unavailable optional Knowledge permits the canonical Video owner to
exist. Unsupported facts required by public copy remain a factual/public
readiness blocker. Generated prose, summaries, transcripts and user hints are
never automatically persisted as Knowledge. New supported information may
enter the existing Source/Evidence → evaluation → Governance → Knowledge
workflow only as a separate governed operation.

## Public composition and bounded repair

Internal identifiers, diagnostics, claim IDs, governance IDs, readiness
packets, provenance vocabulary and resolution machinery are not public copy.
The public composer consumes a reader-safe projection containing only facts and
editorial expressions that are valid at the requested scope.

Quality findings retain distinct classes:

- `REPAIRABLE_PRESENTATION_DEFECT`;
- `STRUCTURAL_INTERNAL_LEAK`;
- `FACTUAL_SUPPORT_DEFECT`;
- `CANONICAL_IDENTITY_CONFLICT`;
- `GOVERNANCE_REQUIRED`;
- `PUBLICATION_ONLY_DEFECT`.

Repairable defects follow a bounded loop:

```text
compose → full quality → structured findings → bounded repair
→ regenerate dependent projections → full quality again
```

The next quality pass always reads the repaired package, never a stale
pre-repair object. The existing maximum repair-round bound remains in force.
Unresolved identity, integrity, governance or indispensable factual support
issues fail closed; sparse optional context does not become an artifact-wide
hard block.

## Owner creation, optimization and publication

Before owner creation, SEO work is transient planning only. After canonical
Video creation and owner read-back, optimization operates on that canonical
owner and includes only supported fields: reader-facing title, summary,
description/body, SEO title/description, public identity, category, subject
relation, Knowledge context and supported related-content metadata.

Optimization is applied through the existing controlled Governance/update
boundary with optimistic revision/idempotency checks. The result is then read
back and compared field-by-field for identity, subject, copy, SEO, category,
relations, readiness and revision. Publication requires canonical Video
read-back, public identity, publication state and the public route/projection
read-back where available. A successful transport response alone is
insufficient.

## Test strategy

Tests are generic and parameterized over unrelated subjects, brands, models,
variants, languages, title wording, external IDs and rich/sparse Knowledge.
The required matrix covers:

- fresh Video with rich and sparse Knowledge;
- aliases and secondary canonical entities in titles;
- optional enrichment unavailable;
- presentation repair, structural leak and unsupported factual assertion;
- lost response after owner creation and after Capture creation;
- empty/malformed response after persistence;
- same idempotency replay and normalized URL variants;
- existing owner and existing relation reuse;
- stale SEO projection after repair;
- final publication/public read-back;
- generated prose not creating Knowledge;
- authoritative subject surviving optimization;
- a future unknown subject with no production special case.

The public-clock convergence fixture and Jacquemart uncertain-outcome fixture
are evidence rows only. Tests must prove the same path without embedding their
identities in production code.

## Runtime and deployment gates

Local implementation must pass focused Video/Capture/identity/enrichment/
quality/repair/SEO/relation/Knowledge tests, relevant CP1–CP6 tests, Unit,
Contract and guarded Integration/P4 suites, PHP lint, `git diff --check` and
secret review. Infrastructure failures remain explicit.

Staging acceptance is allowed only after fresh documentation bootstrap,
verified deployed build identity, exact existing IDs, duplicate/read-only audit,
server-issued signed scope, governed canonical workflow and canonical
read-back. Deployment uses the established local commit → remote push → server
pull mechanism only; no server hotfix or direct file edit is permitted.

After deployment, the MCP documentation manifest and runtime tuple are read
back and must agree on source revision, documentation version, manifest hash
and build identity. Missing credentials, runtime identity or signed scope
produce a fail-closed report rather than a simulated acceptance.

## Acceptance definition

The work is complete only when local generic architecture and regression tests
prove safe creation, enrichment, repair, optimization, publication and
idempotent recovery, and when any authorized runtime acceptance proves the
canonical owner, optimized package, subject relation, SEO/public identity and
public read-back. If deployment or live acceptance is unavailable, the final
report must state the exact external blocker and must not claim runtime
verification.

