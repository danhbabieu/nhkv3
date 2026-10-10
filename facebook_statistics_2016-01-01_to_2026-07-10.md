# Facebook fanpage statistics manifest

## Requested range

- Fanpage: Đồng Hồ Nhà Kho
- From: `01/01/2016`
- Through: `10/07/2026` (inclusive)
- Operation: read/export/statistics only; no deletion performed
- Keyword rule: hashtags are excluded before matching. For example, `#DongHoOdo` is not a match; `odo` in normal content is a match.

## Export limitation

Meta Content Library permits a maximum custom export range of 366 days. Therefore the requested 2016–2026 interval cannot be exported as one native CSV report. The final aggregate must be assembled from bounded reports and must retain each report's coverage and missing-data status.

## Current evidence

The currently available exports contain 1,776 source rows, but their parsed post dates begin at `06/09/2025`; they do not prove coverage of `01/01/2016–05/09/2025` or `11/07/2025–12/07/2025` where applicable. Existing exports are therefore partial evidence, not a complete historical census.

Meta report history now records the requested bounded report `01/01/2025–05/09/2025` as `Không tìm thấy kết quả nào` (no results). It also confirms the yearly reports for `2021`, `2022`, `2023`, and `2024` as `Không tìm thấy kết quả nào`. The 2020 and 2019 reports have been submitted and are still processing; they are not yet counted as covered.

The next report `01/01/2024–31/12/2024` is visible in Meta report history but remains `Đang xuất 0%`. Meta permits only one report in this queue, so later yearly reports cannot be started until this queued report resolves.

| Interval | Status | Evidence |
|---|---|---|
| 01/01/2016–31/12/2016 | NOT_EXPORTED | No CSV evidence |
| 01/01/2017–31/12/2017 | NOT_EXPORTED | No CSV evidence |
| 01/01/2018–31/12/2018 | NOT_EXPORTED | No CSV evidence |
| 01/01/2019–31/12/2019 | REPORT_PENDING | Submitted; Meta still processing |
| 01/01/2020–31/12/2020 | REPORT_PENDING | Submitted; Meta still processing |
| 01/01/2021–31/12/2021 | CONFIRMED_NO_RESULTS | Meta report history |
| 01/01/2022–31/12/2022 | CONFIRMED_NO_RESULTS | Meta report history |
| 01/01/2023–31/12/2023 | CONFIRMED_NO_RESULTS | Meta report history |
| 01/01/2024–31/12/2024 | CONFIRMED_NO_RESULTS | Meta report history |
| 01/01/2025–05/09/2025 | NOT_EXPORTED | Current earliest parsed date is 06/09/2025 |
| 06/09/2025–10/07/2026 | PARTIAL_EXPORTS | Existing CSV exports; date windows are split and do not constitute one full native report |

## Current partial counts

- Source rows in available exports: `1,776`
- Unique IDs matching age/view rule through 10/07/2026: `687`
- Matching age/view rule: `679`
- Matching keyword rule after excluding hashtags: `16`
- No post has been deleted by this work.

These counts must not be presented as the complete 2016–10/07/2026 result until the missing intervals above have successful Meta exports and are merged/deduplicated.

## Final status

`PARTIAL_CLEANUP` — historical coverage is incomplete because Meta's 366-day export limit applies and the older intervals have not yet produced accessible CSV evidence.
