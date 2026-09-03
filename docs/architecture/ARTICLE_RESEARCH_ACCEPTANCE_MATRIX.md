# Article Semantic/SEO Research Preflight — acceptance matrix

Status: implementation-ready, runtime unverified (2026-09-03).

This is an evidence matrix, not a new contract or capability. The preflight is
read-only; `nhk_v3_test` is required for integration evidence.

| Approved requirement | Implementation | Unit evidence | Integration evidence | Status / blocker |
|---|---|---|---|---|
| Canonical identity; ambiguity; unknown not guessed | `McpSemanticContextResolver`, `ArticleResearchPreflight` | `ArticleResearchPreflightTest`, resolver tests | WP bootstrap required | PASS / runtime unverified |
| Duplicate intent; overlap; complementary intent | `ArticleResearchPreflight::overlap` | `ArticleResearchPreflightTest` | WP post inventory required | PASS / runtime unverified |
| Claim inventory and scope | Article inventory reader; `KnowledgeClaim` | Article research + Knowledge tests | `nhk_v3_test` required | PASS / runtime unverified |
| Source/Evidence; supports/qualifies/contradicts; missing/unavailable | bounded claim evidence projection | Knowledge/Evidence tests | `nhk_v3_test` required | PASS / runtime unverified |
| Direct/derived relations; max two hops; direction/cycle; direct precedence | `RelatedSemanticQuery`, `PredicateTraversalPolicy` | related traversal tests | Graph/WP bootstrap required | PASS / runtime unverified |
| Unsupported predicate and honest empty relation | registry-driven traversal and blockers | related traversal + Article research tests | Graph/WP bootstrap required | PASS / runtime unverified |
| Post references are Graph-only | incoming Graph read projection | Article research tests | WP/Graph bootstrap required | PASS / runtime unverified |
| Public internal links | `PublicEndpointEligibilityResolver` | resolver test (all 15 families) | public route sweep + WP bootstrap required | PASS / runtime unverified |
| Private/retired/draft/invalid/no-route/unavailable excluded | shared active/readiness/visibility/identity/route gates | table-driven endpoint gate tests | WP runtime required | PASS / runtime unverified |
| Category plan without category writer | research category section | Article research tests | WP taxonomy read required | PASS / runtime unverified |
| Media reuse; placeholder incomplete | Article media planning and readiness gate | media/article research tests | WP media read required | PASS / runtime unverified |
| Video reuse | validated Video reference projection | Video + Article research tests | WP video read required | PASS / runtime unverified |
| SEO blueprint consumes research | `ArticleResearchPreflight` blueprint | Article research/SEO tests | WP projection read-back required | PASS / runtime unverified |
| No meta keywords/fabricated metrics; structured data planning only | blueprint vocabulary and projection contract | SEO projection tests | public render required | PASS / runtime unverified |
| Claim compliance warnings | shared compliance planning state | compliance tests | publication/runtime evidence required | PASS / runtime unverified |
| No Post/taxonomy/Authority/Knowledge/Graph/Governance/Media/Video writes | read-only callbacks and MCP research path | no-write research tests | guarded write-observation test required | PASS / runtime unverified |
| Capability status truthful | execution state remains PARTIAL | capability manifest tests | runtime dependency check required | BLOCKED: DB/bootstrap |

## Registered endpoint route audit

`wp_post`, `brand`, `model`, `variant`, `movement`, `music`, `component`,
`classification`, `specimen`, `product`, `media`, `video`, `knowledge`,
`source`, and `evidence` are explicitly evaluated by the shared resolver.
Authority routes use the canonical public route policy; `wp_post` requires an
existing supplied route; `video` uses its validated public route; `media`,
`knowledge`, `source`, and `evidence` remain `NO_PUBLIC_ROUTE` until a shared
public route contract exists. No UUID/stable-key URL is synthesized.

## Integration diagnosis

`NHK_WP_TEST_PATH=public` is the correct bootstrap setting. With it set,
WordPress fails with `Error establishing a database connection` before guarded
tests can run. Integration tests require the exact database `nhk_v3_test` via
`TestDatabaseGuard`; they must never use development database `nhk_v3`. No
fallback, credential change, service creation, or data mutation was performed.
