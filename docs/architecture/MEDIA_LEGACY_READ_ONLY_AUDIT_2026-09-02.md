# NHK V3 Legacy Media Read-Only Audit — 2026-09-02

> NON-NORMATIVE. The sole normative source is
> `docs/constitution/NHK_V3_CONSTITUTION.md`. This report authorizes no repair.

## Result

The requested legacy audit was not executed against a WordPress database in
this checkpoint. The local WordPress/bootstrap path is unavailable, so all
data counts below are `UNVERIFIED`, including Post 55. No Post, attachment,
Media, MediaUsage, Graph edge, slug, asset filename or semantic record was
changed.

| Audit measure | Result | Required follow-up |
|---|---:|---|
| Posts missing real Featured Media | UNVERIFIED | Read-only query after WordPress runtime is restored |
| Posts missing Inline Primary Media | UNVERIFIED | Read-only block/usage inspection |
| Posts reusing one Media for both mandatory roles | UNVERIFIED | Compare canonical Media IDs, never filenames |
| Posts complete under the two-slot law | UNVERIFIED | Reconcile against active/ready real Media |
| Camera-style filenames (`IMG_`, `DSC_`, `DSCF_`, `PXL_`) | UNVERIFIED | Candidate filename report only |
| Media missing contextual alt | UNVERIFIED | Usage-scoped report only |
| Low-resolution Media | UNVERIFIED | Dimensions/readiness report only |
| Checksum duplicate candidates | UNVERIFIED | Review candidates; never auto-merge |
| Orphan MediaAssets | UNVERIFIED | Storage/parent report only |
| Placeholder-required existing Posts | UNVERIFIED | Candidate queue only; no automatic placeholder insertion |
| Product-attached physical imagery | UNVERIFIED | Review MediaUsage/approved Specimen evidence only |

## Post 55

Post 55 was checked read-only after the local runtime was restored on both
`nhk_v3` and `nhk_v3_test`. The exact result on each database was: no Post 55
row, no status/title/content to inspect, zero `wp_post` MediaUsage rows and
zero Blueprint rows. The two Post 55 integration tests therefore remain
`EXPECTED_SKIP` because their published fixture is absent. No special-case
constant, placeholder, read-back mutation or automatic repair was added.

The broader legacy measures in this report remain `UNVERIFIED`; runtime
availability does not authorize a legacy inventory beyond the exact read-only
checks recorded here. The fresh Phase R fixtures were isolated and removed
from `nhk_v3_test` only by exact fixture identity.

## Safe audit boundary

When the runtime is available, the audit must read native WordPress post and
block state, canonical Media/Asset/Usage rows, Blueprint rows and public asset
metadata. It may produce candidate, reason and proposed-action records. It
must not call `MediaService::addUsage`, `MediaService::addAsset`, Graph writes,
Governance Apply, attachment rename, placeholder backfill or legacy body
import. Semantic candidates require the existing Evidence → Proposal → Human
Approval → Eligibility → Controlled Apply chain before any later apply task.
