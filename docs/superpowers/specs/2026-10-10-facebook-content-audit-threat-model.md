# Facebook Content Audit CLI — Threat Model

## Assets

- Exact Page scope and verified Page ID.
- Read-only audit rows, post URLs, text, media references and metrics.
- Checkpoint cursor state and workbook output.
- Meta credentials supplied to a live adapter.

## Trust boundaries

1. CLI arguments and fixture files are untrusted input.
2. Meta Graph responses are external, partial, permission-dependent input.
3. The normalizer/classifier is an internal read-only application boundary.
4. The workbook is consumed by desktop spreadsheet software and must be safe
   against formula injection.

## Threats and controls

| Threat | Control | Evidence |
|---|---|---|
| Scope escape to another Page | Exact URL canonicalization, verified Page ID binding, fail-closed mismatch | Scope tests |
| Guessed or stale Page ID | Page ID is accepted only from a successful identity response | Identity tests |
| Unauthorized group enumeration | Group rows are sourced only through the target Page's permitted read surface; inaccessible is retained as a status | Access/group tests |
| Token or secret leakage | Token only through environment lookup, never fixture/checkpoint/report/log; redaction assertions | Secret review/tests |
| Accidental mutation | Contract has no write methods; adapter implementation has no delete/hide/edit/publish calls | Interface/static tests |
| Pagination omission or loop | Cursor checkpoint, repeated-cursor rejection, bounded pages/attempts | Pagination tests |
| Transient API failure misreported as empty | Retryable error state and explicit unavailable/inaccessible result | Retry tests |
| Zero conflated with missing metric | Typed value-state normalization | Normalization/workbook tests |
| Formula injection via post text/title | Dangerous leading characters are escaped before cell serialization | Workbook security test |
| Hyperlink formula execution | URL is emitted as OOXML relationship, not a formula | Workbook XML test |
| Zip/path abuse in workbook output | Fixed internal OOXML paths and controlled output file path | Workbook test |
| Trademark false conclusion | Only emits `TRADEMARK_REVIEW`; report text explicitly says review, not infringement | Classification test |
| Delete side effect | `DELETE_CANDIDATE` is a row classification with no mutation boundary | Static/behavior test |
| PII or credential export | No auth headers/tokens in serialized values; report fields are limited to requested audit data | Fixture/report inspection |

## Residual limitations

- Meta may deny or change access independently of the adapter. The audit must
  report the current observed state, not infer absence.
- Group discovery can remain incomplete when the current permitted Meta surface
  does not expose group posts. `INACCESSIBLE_GROUPS` is therefore a valid result.
- Classification is a review aid, not legal advice or an automated deletion
  decision.

