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
| P7 Knowledge + Source + Evidence + Post Graph | NOT STARTED | Atomic claims, provenance and Post semantic links. |
| P8 Admin + MCP operational layer | NOT STARTED | Governed read/mutation workflows. |
| P9 Frontend/UI parity | NOT STARTED | V2 route/function inventory and V3 assembly. |
| P10 V2 → V3 Data Migration | NOT STARTED | Backup, restore verification and dry-run gates required. |
| P11 Reconciliation + parity + cutover readiness | NOT STARTED | Count/semantic/UI/logic reconciliation and readiness report; stop before cutover. |

## Locked direction

WordPress owns article content; Authority owns canonical entities; Knowledge
owns atomic claims; one Graph connects semantic endpoints; Governance controls
durable semantic mutation; Media is first-class; Video is an external reference.
V2 implementation and schema are reference material, not a template.
