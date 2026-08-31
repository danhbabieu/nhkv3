# NHK V3 Master Plan

Status is based on code and test evidence, not commit titles.

| Phase | Status | Evidence / next gate |
|---|---|---|
| P0 Bootstrap | ACCEPTED/CLOSED | Repository and V3 boundaries established. |
| P1 Legacy Audit | ACCEPTED/CLOSED | `09_P1_SOURCE_AUDIT.md`, `07_LEGACY_INHERITANCE_MATRIX.md`. |
| P2 Graph Core | ACCEPTED/CLOSED | `12_P2_ACCEPTANCE_MATRIX.md`. |
| P3 Authority Core | ACCEPTED/CLOSED | `14_P3_ACCEPTANCE_MATRIX.md`, `15_P3_INTEGRATION_ACCEPTANCE.md`. |
| P4 Governance Core | ACCEPTED/CLOSED | All acceptance rows pass; Migration003 UP-only applied to `nhk_v3`; health is 3/3. |
| P5 Canonical Domain Foundation | ACCEPTED/CLOSED | Nine registry-backed canonical types, typed payload validation, generic persistence/lifecycle/update and Graph endpoint resolution are covered by unit/integration evidence. |
| P6 Media + Video | IN PROGRESS | Build Media identity/asset/usage and external Video reference vertical slices. |
| P7 Knowledge + Source + Evidence + Post Graph | IN PROGRESS | Atomic claims, provenance, persistence and Post semantic links. |
| P8 Admin + MCP operational layer | IN PROGRESS | Admin health surface, governed proposal REST workflow, semantic read/search APIs and MCP tool contract; external transport pending. |
| P9 Frontend/UI parity | IN PROGRESS | Responsive NHK editorial theme scaffold; semantic/entity routes and smoke QA pending. |
| P10 V2 → V3 Data Migration | IN PROGRESS | Read-only route inventory and no-write dry-run tooling added; export, backup/restore evidence and real migration remain gated. |
| P11 Reconciliation + parity + cutover readiness | NOT STARTED | Count/semantic/UI/logic reconciliation and readiness report; stop before cutover. |

## Locked direction

WordPress owns article content; Authority owns canonical entities; Knowledge
owns atomic claims; one Graph connects semantic endpoints; Governance controls
durable semantic mutation; Media is first-class; Video is an external reference.
V2 implementation and schema are reference material, not a template.

## Autonomous delivery direction

The remaining phases are executed as tested vertical slices and are not paused
for per-phase confirmation. The public target is functional, data and UI/UX
parity or better with V2, while retaining the clean V3 boundaries. The NHK
frontend is a discovery-oriented editorial surface with warm, restrained,
classic visual cues and modern responsive interaction. It uses real query and
application services, never hard-coded public fixtures or raw database access
from templates.

P6 must finish Media/MediaAsset/MediaUsage and canonical external Video
persistence plus shared Graph endpoint coverage. P7 adds Knowledge, Source and
Evidence and Post semantic links. P8 adds daily-operational Admin and governed
read/mutation APIs including MCP. P9 builds the responsive frontend and route
inventory. P10 performs read-only V2 inventory and dry-run first, then only a
backup-gated resumable migration. P11 reconciles counts, semantics, routes,
logic, UI, SEO and URLs and produces Cutover Readiness; production cutover
remains human-gated.
