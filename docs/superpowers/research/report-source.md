# NHK V3 Admin UI/UX Research Source Report

Date: 2026-09-06
Audience: NHK V3 product/engineering owner
Scope: Vietnamese law and NHK V3 Constitution implications for an Admin
control plane; public UI is out of scope except where publication compliance
crosses the Admin boundary.

## Executive answer

The Admin must be a governed operational control plane, not a semantic data
store. Its most important UX duty is to make identity, revision, provenance,
capability, diagnostics, approval binding, apply state and read-back evidence
visible while preventing unsafe actions. The first slice should therefore
center on Governance review and shared state primitives, then reuse them for
editorial, semantic and Media/Video workspaces.

## Primary local sources

- `AGENTS.md`: workspace safety, Constitution-first workflow, no destructive or
  live-data operations.
- `docs/constitution/NHK_V3_CONSTITUTION.md`: canonical architecture law,
  ownership boundaries, fail-closed registry rules, Governance lifecycle,
  Admin obligations and publication override law.
- `docs/constitution/READ_FIRST.md`: current-contract router.
- `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`: current MCP/Admin control-plane
  capability and workflow evidence.
- `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`: shared adapter and
  read-back boundaries.
- `docs/architecture/V3_FRONTEND_DESIGN_CONTRACT.md`: language, accessibility,
  tokens and public/presentation constraints.
- `docs/architecture/V3_EXECUTION_STATE.md` and
  `docs/architecture/V2_V3_PARITY_MATRIX.md`: dated implementation evidence;
  not authority for new vocabulary.

## Primary legal sources reviewed

1. Quốc hội, Luật Quảng cáo số 16/2012/QH13, Điều 8(11): unsupported use of
   “nhất”, “duy nhất”, “tốt nhất”, “số một” or equivalent expressions is
   prohibited. Official URL:
   https://vanban.chinhphu.vn/?docid=163008&pageid=27160
2. Quốc hội, Luật Bảo vệ dữ liệu cá nhân số 91/2025/QH15, effective
   2026-01-01. Official URL:
   https://vanban.chinhphu.vn/?docid=214590&pageid=27160&typegroupid=3
3. Chính phủ, Nghị định 356/2025/NĐ-CP, effective 2026-01-01, detailing
   implementation of the personal-data law. Official URL:
   https://vanban.chinhphu.vn/default.aspx?docid=216387&pageid=27160
4. Quốc hội, Luật Giao dịch điện tử số 20/2023/QH15, effective 2024-07-01.
   Official URL:
   https://vanban.chinhphu.vn/?classid=1&docid=208421&pageid=27160&typegroupid=3

## Claim-to-source ledger

| Claim | Source | Confidence | Product implication |
|---|---|---:|---|
| Admin is not a canonical owner and semantic writes require Governance | NHK V3 Constitution §§2, 20 | High | No direct SQL or UI-owned semantic persistence |
| Admin must show identity, revision, blockers, warnings, relation path, gaps, binding, apply status and reason codes | NHK V3 Constitution §20 | High | Proposal detail needs an operator evidence layout |
| Publication has exactly PASS, OWNER_REVIEW_REQUIRED and SYSTEM_BLOCKED outcomes | NHK V3 Constitution §14.2 and amendment 2026-09-03 | High | No generic “override” button; system blocks have no override |
| Absolute/leadership advertising claims require lawful support | Law on Advertising, Article 8(11) | High | Claim review must be evidence/scope-aware and not keyword-only |
| Personal-data handling law is effective from 2026-01-01 | Law 91/2025/QH15 and Decree 356/2025/NĐ-CP | High | Minimize, purpose-label and redact operator views |
| Electronic data integrity, accessibility and identity factors matter for evidence | Electronic Transactions Law 20/2023/QH15, §§10–14, 21–22 | High | Bind approvals to principal, fingerprints, timestamps and read-back |

## Limitations and unresolved questions

- This is an engineering design translation, not a legal opinion.
- Exact data-controller/processor roles, retention periods and incident
  response obligations require organizational/legal confirmation before live
  personal-data workflows are activated.
- Live runtime availability and exact capability catalog must be confirmed by
  wire/integration smoke; documentation counts are not current runtime truth.
- Accessibility targets should be verified against the project's chosen WCAG
  target during browser QA; the Constitution already requires keyboard and
  contrast-safe behavior.
