# Video Relationship Contract

Video relation proposals use the registered Graph only. The current registry
allows Video outbound `about` relations; `depicts` is Media-only. No predicate
is invented for a Hub, thumbnail, CTA, album or UI component.

Each candidate contains `target_id` (canonical UUID), `target_type`, registered
predicate, `EXPLICIT_USER_RELATION` or `INFERRED_RELATION`, evidence references,
confidence and a reason. Ambiguous or unknown targets fail closed. Unknown
predicate, endpoint or target identity is a typed gap/conflict.

`evidence_refs` is a non-empty array of exact objects shaped as
`{"evidence_id":"<canonical Evidence UUID>"}`. The Evidence must resolve
through the Knowledge/Evidence repository, remain active and be publicly usable
under the existing Evidence read policy. Arbitrary objects, string references,
missing IDs and inactive/unusable Evidence fail closed. This reference is
preserved unchanged through Proposal and Controlled Apply.

Before relation proposal creation and again immediately before Controlled Apply,
each `evidence_id` must resolve to canonical active Evidence whose Claim and
Source dependencies are also canonical and active. Visibility is separate:
PRIVATE/HIDDEN Evidence is checked through governed owner/internal read-back and
is never rewritten as PUBLIC. A public evidence-get may still return no record.

`proposal_id` is the Governance command identity only. It is not a valid
`evidence_id`, target UUID or canonical relation identity. Downstream relation
apply is allowed only after upstream Evidence and Video canonical owner
read-backs pass.

Apply is governed. Every approved Video ingest proposal must create the Video
with at least one approved attachment through `GraphService` in the same
Controlled Apply transaction. Zero candidates returns
`NO_SEMANTIC_ATTACHMENT`; a Hub never satisfies this requirement.

Public related sections reuse `RelatedContentQuery`, direct before derived,
with derived traversal bounded at two hops. Derived output is never persisted
as a shortcut. The current shared traversal engine remains a documented
implementation gap until its direction policy/path result is fully converged.

## Public relation and Admin boundary — 2026-09-07

Public relation rendering is allowed only after Graph/public eligibility. A
public-safe knowledge projection does not promote a relation, and PRIVATE
Source/Evidence must not leak through relation payloads. Guided Admin relation
flows resolve/reuse canonical provenance when the contract permits; normal
users do not copy proposal or Evidence UUIDs. The existing Governance lifecycle
remains the only relation writer.

## Post-ingest reconciliation — 2026-09-09

After Video canonical owner read-back, MCP completion continues through
canonical search, bounded neighborhood/Graph inspection, duplicate/reuse
analysis, relation candidate discovery, evidence/provenance validation,
Governed application of every useful registered relation and final read-back.
“Maximize relations” means maximize justified useful relations, not edge count;
weak or speculative candidates remain unapplied.

The relation candidate retains one explicit provenance class:
`OBSERVED_FROM_MEDIA`, `EXPLICIT_USER_KNOWLEDGE`, `CATALOG_SUPPORTED`,
`EXTERNAL_RESEARCH` or `SYSTEM_INFERENCE`. The class does not replace
`evidence_refs`; it records how the candidate was sourced. User input, source
metadata and visual observation do not become universal facts or relation
evidence without support at the exact subject and scope.
