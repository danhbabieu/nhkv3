# Entity Hubs and Brand Graph Implementation Plan

Implementation checkpoint: Phase A–C code is now present in the working tree.
The six approved predicates are registered exactly as defined by the current
relationship contract; this supersedes the earlier staged-registry-gap wording
below. No physical Graph repair, semantic-data import or legacy article-body
operation has been performed.

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Vietnamese discovery hubs, public eligibility and derived Brand context share explicit query/route contracts while registering only the approved typed relationship vocabulary and leaving physical Graph repair untouched.

**Architecture:** Phase A introduces one Authority collection read boundary composed from `AuthorityRepository`, `PublicEntityEligibilityPolicy`, `PublicIdentityContract`, `PublicRouteResolver` and `EntityTypeRegistry`; routes, cards, search, REST and templates consume its read models. Phase B extends the existing typed Graph registry and adds read-only structural traversal and Brand aggregation. Phase C adds read-only diagnostics for orphan, multiple-parent, registry and compatibility states. No phase creates or repairs semantic data.

**Tech Stack:** PHP 8+, WordPress rewrite/template hooks, existing NHK Core Authority/Graph/Governance repositories, PHPUnit, guarded WordPress integration tests, Composer lint and read-only CLI audits.

**Spec:** `docs/architecture/V3_ENTITY_HUBS_BRAND_GRAPH_DESIGN_SPEC.md`

## Global Constraints

- Canonical discovery hubs are `/thuong-hieu/`, `/mau/`, `/bo-may/`, `/ban-nhac/`, `/linh-kien/`, `/phan-loai/`, `/hien-vat/`, `/san-pham/`, `/tri-thuc/`, `/video/` and `/so-sanh/` as defined in `docs/architecture/V3_PUBLIC_HUB_MATRIX.md`.
- Persist only direct child→parent structural facts: `Model --model_of--> Brand` and `Variant --variant_of--> Model`; never persist reverse edges or `Variant → Brand` shortcuts.
- Register only the six approved definitions: `model_of`, `variant_of`, `uses_movement`, `supports_music`, `configured_with_music` and `observed_playing_music`; no unapproved predicate is invented. Physical Graph rows remain untouched.
- Transitional `brand_uuid` and `model_uuid` payload fields are compatibility evidence, not canonical Graph truth; a clear parent may produce a `DATA_COMPATIBILITY_GAP` warning, while missing or ambiguous evidence blocks public structural completeness.
- Movement, Music, Component and Classification do not require Brand ancestry; Product remains a listing/offer and Specimen remains a concrete physical object.
- MediaAsset has no standalone semantic SEO page; Source and Evidence have no standalone public indexable page by default.
- Do not import article bodies, replace databases, seed/recreate semantic rows, edit V2/live data, change payload parents, create physical Graph edges or perform production cutover.
- Keep `nhk_v3` non-destructive. Destructive integration operations are allowed only in guarded `nhk_v3_test`.
- Every production-code change starts with a failing PHPUnit test; every task ends with focused tests, `git diff --check`, PHP lint and a secret review before its logical commit.

## File map

### Phase A — public discovery

- Create `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicIdentityContract.php` for the shared canonical display identity and route-input decision.
- Create `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEligibilityResult.php` and `PublicEntityEligibilityPolicy.php` for bounded eligible/reason/warning results.
- Create `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityCollectionQuery.php` for Authority collection read models and shared membership/URL/totals behavior.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityPageQuery.php` only to delegate Authority archive/detail eligibility to the new boundary without retaining a second policy.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicRouteResolver.php` only where the shared identity/structural-parent seam requires it; preserve the approved route shapes.
- Modify `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicEntityRoutes.php` to register Vietnamese hubs, nested details and one-hop legacy redirects.
- Modify `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicComparisonRoutes.php` to expose `/so-sanh/` and redirect `/comparison/` in one hop.
- Modify `public/wp-content/plugins/nhk-core/src/Plugin.php` to compose the shared query once and inject it into route/search/home consumers.
- Modify `public/wp-content/themes/nhk-v3/functions.php`, `front-page.php`, `sidebar.php`, `entity.php` and `comparison.php` to use canonical links and read models only.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Search/SearchSemanticQuery.php`, REST collection routes and home semantic wiring to consume the same Authority collection decisions.
- Modify `tools/frontend-route-smoke.php` after route behavior is proven so canonical hubs are 200 and legacy technical hubs are one-hop 301 checks.
- Test with `PublicIdentityContractTest.php`, `PublicEntityEligibilityPolicyTest.php`, `PublicEntityCollectionQueryTest.php`, `PublicRouteResolverTest.php`, `EntityPageQueryTest.php`, `FrontendContractTest.php` and `McpTransportIntegrationTest.php` where route/read contracts overlap.

### Phase B — Graph contract and Brand read model

- Modify `public/wp-content/plugins/nhk-core/src/Domain/Graph/PredicateRegistry.php` to register only the approved predicate definitions at the registry checkpoint.
- Create `public/wp-content/plugins/nhk-core/src/Application/Graph/StructuralContextQuery.php` for direct parent lookup and two-hop derived Brand context.
- Create `public/wp-content/plugins/nhk-core/src/Application/Graph/StructuralContext.php` as the immutable result value object returned by structural reads.
- Create `public/wp-content/plugins/nhk-core/src/Application/Graph/BrandAggregationQuery.php` for Brand read models with `DIRECT`/`DERIVED` origin and relation paths.
- Create `public/wp-content/plugins/nhk-core/src/Contracts/Graph/GraphDistributionReader.php` as the read-only adapter consumed by the distribution audit.
- Create `public/wp-content/plugins/nhk-core/src/Application/Graph/GraphDistributionAudit.php` and `tools/graph-distribution-audit.php` for a read-only source/predicate/target matrix; the tool must never call `GraphService::create`, `retire` or `reactivate`.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityEligibilityPolicy.php` to use structural context only through the explicit transition/cutover seam.
- Test with `GraphCoreContractTest.php`, `StructuralContextQueryTest.php`, `BrandAggregationQueryTest.php`, `GraphDistributionAuditTest.php` and guarded `GraphWpdbIntegrationTest.php`.

### Phase C — diagnostics

- Create `public/wp-content/plugins/nhk-core/src/Application/Graph/StructuralDiagnostics.php` for read-only orphan and multiple-parent findings.
- Create `public/wp-content/plugins/nhk-core/src/Application/Graph/RegistryGapReport.php` for declared approved requirements absent from the runtime registry.
- Create `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicExclusionReport.php` for bounded public exclusion reasons without changing persisted readiness/state.
- Create `tools/structural-diagnostics.php` as a read-only report command that emits JSON or a table and does not mutate Authority, Graph, redirects or migration ledgers.
- Update `docs/architecture/V3_EXECUTION_STATE.md`, `docs/architecture/V2_V3_PARITY_MATRIX.md` and the approved matrices with evidence only after the focused and guarded checks pass.
- Test with `StructuralDiagnosticsTest.php`, `RegistryGapReportTest.php`, `PublicExclusionReportTest.php` and read-only tool contract tests.

---

## Phase A — Public discovery

### Task 1: Define the shared public identity and eligibility value objects

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicIdentityContract.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEligibilityResult.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityEligibilityPolicy.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityEligibilityPolicyTest.php`

**Interfaces:**
- Consumes: `AuthorityEntity`, `EntityTypeRegistry`, `AuthorityRepository`, `PublicRouteResolver`, optional `MigrationStatus`.
- Produces: `PublicIdentityContract::resolve(AuthorityEntity $entity): ?array` returning `['id'=>string,'type'=>string,'stable_key'=>string,'name'=>string,'slug'=>string]`; `PublicEligibilityResult::eligible(): self`, `PublicEligibilityResult::blocked(string ...$reasons): self`, `PublicEligibilityResult::withWarning(string $warning): self`; `PublicEntityEligibilityPolicy::evaluate(AuthorityEntity $entity): PublicEligibilityResult`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_identity_returns_canonical_display_fields_for_a_registered_active_entity(): void
{
    $entity = $this->authority->create('brand', 'nhk:brand:odo', 'Ô Đô');
    self::assertSame([
        'id' => $entity->canonicalId,
        'type' => 'brand',
        'stable_key' => 'nhk:brand:odo',
        'name' => 'Ô Đô',
        'slug' => 'o-do',
    ], (new PublicIdentityContract($this->types))->resolve($entity));
}

public function test_policy_blocks_an_inactive_entity_and_does_not_invent_a_public_url(): void
{
    $entity = $this->authority->create('brand', 'retired', 'Retired');
    $this->authority->retire($entity->canonicalId, 1);
    $result = $this->policy->evaluate($entity);
    self::assertFalse($result->eligible);
    self::assertContains('INACTIVE', $result->reasons);
}
```

- [ ] **Step 2: Run the focused tests to verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityEligibilityPolicyTest.php`

Expected: FAIL because the new identity and policy classes do not exist.

- [ ] **Step 3: Implement the minimal contracts**

Use `EntityTypeRegistry` to reject unknown types, delegate slug generation to
`PublicRouteResolver::slug()`, and return `INACTIVE`, `UNKNOWN_TYPE`,
`INVALID_IDENTITY` or `UNAVAILABLE` only when an existing contract proves that
condition. Do not add a database field or infer a relation.

- [ ] **Step 4: Run the focused tests to verify success**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityEligibilityPolicyTest.php`

Expected: PASS with no warnings.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Entity/PublicIdentityContract.php public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEligibilityResult.php public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityEligibilityPolicy.php public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityEligibilityPolicyTest.php
git commit -m "feat: define shared public identity eligibility contract"
```

### Task 2: Add the single Authority collection query

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityCollectionQuery.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityCollectionQueryTest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityPageQuery.php`

**Interfaces:**
- Consumes: `AuthorityRepository`, `EntityTypeRegistry`, `PublicIdentityContract`, `PublicEntityEligibilityPolicy`, `PublicRouteResolver`.
- Produces: `PublicEntityCollectionQuery::archive(string $type, int $page = 1, int $perPage = 24, string $query = ''): array`; `PublicEntityCollectionQuery::detail(string $type, string $key): ?array`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_archive_counts_only_publicly_eligible_routeable_items_and_uses_canonical_urls(): void
{
    $brand = $this->authority->create('brand', 'brand-one', 'Brand One');
    $this->authority->create('brand', 'hidden', 'Video');
    $this->authority->create('brand', 'duplicate-a', 'Shared');
    $this->authority->create('brand', 'duplicate-b', 'Shared');

    $archive = $this->query->archive('brand', 1, 24);

    self::assertSame(1, $archive['total']);
    self::assertSame('/brand-one/', $archive['items'][0]['url']);
    self::assertSame($brand->canonicalId, $archive['items'][0]['id']);
}

public function test_detail_and_archive_apply_the_same_identity_and_eligibility_decisions(): void
{
    $entity = $this->authority->create('movement', 'cal-100', 'Cal 100');
    self::assertSame($this->query->detail('movement', 'cal-100')['url'], '/bo-may/cal-100/');
    self::assertSame('/bo-may/cal-100/', $this->query->archive('movement')['items'][0]['url']);
    self::assertNull($this->query->detail('movement', 'missing'));
}
```

- [ ] **Step 2: Run the focused test to verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityCollectionQueryTest.php`

Expected: FAIL because the collection boundary is not defined.

- [ ] **Step 3: Implement the minimal collection boundary**

Enumerate the registered type through `EntityTypeRegistry`, evaluate each
candidate once, require a non-null `PublicRouteResolver` path for public card
membership, filter registered public fields only, compute totals before slicing,
and resolve detail through the same evaluator. Keep transition warnings out of
visitor-facing payloads.

- [ ] **Step 4: Delegate `EntityPageQuery` without duplicating policy**

Replace its archive/detail eligibility branch with the collection boundary or
an internal adapter that calls the same evaluator. Preserve the existing
reader-safe payload shape and related-content fields. The adapter must not
reintroduce a second active/route filter.

- [ ] **Step 5: Run focused and regression tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityCollectionQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/EntityPageQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteResolverTest.php`

Expected: PASS; any old archive expectation that conflicts with the approved
matrix is recorded for Task 4 rather than hidden with a broad assertion change.

- [ ] **Step 6: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityCollectionQuery.php public/wp-content/plugins/nhk-core/src/Application/Entity/EntityPageQuery.php public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityCollectionQueryTest.php
git commit -m "feat: centralize public authority collection eligibility"
```

### Task 3: Wire canonical hubs, details, menu and templates

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicEntityRoutes.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicComparisonRoutes.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/themes/nhk-v3/functions.php`
- Modify: `public/wp-content/themes/nhk-v3/front-page.php`
- Modify: `public/wp-content/themes/nhk-v3/sidebar.php`
- Modify: `public/wp-content/themes/nhk-v3/entity.php`
- Modify: `public/wp-content/themes/nhk-v3/comparison.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/P5CanonicalDomainIntegrationTest.php`

**Interfaces:**
- Consumes: `PublicEntityCollectionQuery` and `V3_PUBLIC_HUB_MATRIX.md` route definitions.
- Produces: canonical Vietnamese archive/detail rewrite rules, canonical menu fallback links, and comparison route context using `/so-sanh/`.

- [ ] **Step 1: Write failing route and template contract tests**

```php
public function test_public_menu_and_home_discovery_links_use_approved_vietnamese_hubs(): void
{
    $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
    $functions = (string) file_get_contents($theme . '/functions.php');
    $home = (string) file_get_contents($theme . '/front-page.php');
    self::assertStringContainsString("'Thương hiệu' => '/thuong-hieu/'", $functions);
    self::assertStringContainsString("home_url('/mau/')", $home);
    self::assertStringNotContainsString("home_url('/brand/')", $home);
}

public function test_comparison_route_is_canonicalized_to_so_sanh(): void
{
    $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicComparisonRoutes.php');
    self::assertStringContainsString("'^so-sanh/?$'", $routes);
    self::assertStringContainsString("'/comparison/'", $routes);
}
```

- [ ] **Step 2: Run tests and verify the stale technical-route failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`

Expected: FAIL on the current `/brand/`, `/model/` and `/comparison/` menu
strings, demonstrating that the old expectation is obsolete under the
approved matrix.

- [ ] **Step 3: Implement rewrite and composition changes**

Register `/thuong-hieu/` and `/mau/` as archive contexts, retain parent-aware
bare Brand/Model/Variant details, map other types to their matrix namespaces,
and register `/so-sanh/`. Keep technical routes only as redirect inputs. Build
one `PublicEntityCollectionQuery` in `Plugin.php`; inject it into Authority
routes and any theme-facing Authority filter rather than constructing separate
policy instances.

- [ ] **Step 4: Implement canonical visitor links**

Change fallback menu, homepage quick links, sidebar, comparison form action,
archive links, pagination links and breadcrumbs to use matrix URLs. Keep
Classification out of primary navigation and Product conditional. Templates
must render query results and must not add active/state/route/parent filters.

- [ ] **Step 5: Run focused route tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php public/wp-content/plugins/nhk-core/tests/Integration/P5CanonicalDomainIntegrationTest.php`

Expected: PASS for canonical route strings and guarded route behavior; tests
that exercise a database are skipped only when the existing WordPress test
environment is unavailable.

- [ ] **Step 6: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicEntityRoutes.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicComparisonRoutes.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/themes/nhk-v3/functions.php public/wp-content/themes/nhk-v3/front-page.php public/wp-content/themes/nhk-v3/sidebar.php public/wp-content/themes/nhk-v3/entity.php public/wp-content/themes/nhk-v3/comparison.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php public/wp-content/plugins/nhk-core/tests/Integration/P5CanonicalDomainIntegrationTest.php
git commit -m "feat: route public discovery through Vietnamese hubs"
```

### Task 4: Prove one-hop legacy redirects and update stale route smoke

**Files:**
- Modify: `tools/frontend-route-smoke.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Integration/P5CanonicalDomainIntegrationTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteResolverTest.php`

**Interfaces:**
- Consumes: canonical rewrite behavior from Task 3 and matrix redirect rules.
- Produces: route smoke assertions that distinguish canonical 200 hubs from
  one-hop technical-route 301 compatibility aliases.

- [ ] **Step 1: Write failing redirect tests**

```php
public function test_technical_hubs_are_redirect_inputs_not_canonical_destinations(): void
{
    $smoke = (string) file_get_contents(dirname(__DIR__, 6) . '/tools/frontend-route-smoke.php');
    self::assertStringContainsString("'/thuong-hieu/' => 200", $smoke);
    self::assertStringContainsString("'/brand/' => 301", $smoke);
    self::assertStringContainsString("'/comparison/' => 301", $smoke);
    self::assertStringContainsString("'/so-sanh/' => 200", $smoke);
}
```

- [ ] **Step 2: Run the smoke contract test and verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`

Expected: FAIL because the smoke currently expects technical archives to be
200 and does not assert the Vietnamese replacement hubs.

- [ ] **Step 3: Update the smoke harness after runtime behavior is proven**

Replace canonical technical archive entries with the matrix hubs, add explicit
one-hop redirect checks and require the final `Location` to contain the matrix
destination. Do not follow redirects in the harness, so a chain remains
detectable. Keep the optional real-detail checks for valid IDs and add a direct
assertion that a second hop is not returned.

- [ ] **Step 4: Run route smoke and inspect failures**

Run: `php tools/frontend-route-smoke.php --base-url=http://localhost`

Expected: PASS when the local WordPress/Apache harness is available; otherwise
record the environment failure without changing expected statuses to make the
test pass.

- [ ] **Step 5: Commit**

```bash
git add tools/frontend-route-smoke.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php public/wp-content/plugins/nhk-core/tests/Integration/P5CanonicalDomainIntegrationTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteResolverTest.php
git commit -m "test: enforce canonical hub and legacy redirect routes"
```

### Task 5: Align search, REST and homepage modules with the collection boundary

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Search/SearchSemanticQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/EntityApi.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/HomeSemanticQuery.php` or the existing home semantic integration seam
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SearchSemanticQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/HomeSemanticQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/SearchPaginationIntegrationTest.php`

**Interfaces:**
- Consumes: `PublicEntityCollectionQuery`, `PublicIdentityContract`, `PublicEntityEligibilityPolicy`.
- Produces: identical eligible Authority membership, totals and canonical URLs across homepage cards, archives, search and REST collections.

- [ ] **Step 1: Write failing parity tests**

```php
public function test_search_excludes_an_active_authority_row_without_a_public_route(): void
{
    $this->authority->create('brand', 'reserved', 'Video');
    $result = $this->search->search('Video', 1, 20);
    self::assertSame([], array_values(array_filter($result['groups']['entities'] ?? [], static fn (array $item): bool => $item['title'] === 'Video')));
}

public function test_rest_collection_total_matches_the_shared_public_collection_total(): void
{
    $archive = $this->collection->archive('movement', 1, 20);
    $response = $this->restGet('/nhk/v1/entity/movement');
    self::assertSame($archive['total'], $response->get_data()['total']);
}
```

- [ ] **Step 2: Run focused tests and confirm divergence**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SearchSemanticQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/HomeSemanticQueryTest.php public/wp-content/plugins/nhk-core/tests/Integration/SearchPaginationIntegrationTest.php`

Expected: FAIL on the route-less active row or mismatched total, if the stale
consumer still bypasses the shared policy.

- [ ] **Step 3: Implement the shared consumer seam**

Inject or construct the collection query once at the plugin composition root;
make search and REST use its eligibility/identity decision for Authority
groups. Preserve separate Media, Video and Knowledge contracts. Home modules
must omit unavailable cards and never synthesize metrics or content.

- [ ] **Step 4: Run focused and full Unit tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite 'NHK Unit'`

Expected: PASS, with any unrelated existing MCP count mismatch separately
tracked for Task 6.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Search/SearchSemanticQuery.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/EntityApi.php public/wp-content/plugins/nhk-core/src/Application/Entity/HomeSemanticQuery.php public/wp-content/plugins/nhk-core/tests/Unit/SearchSemanticQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/HomeSemanticQueryTest.php public/wp-content/plugins/nhk-core/tests/Integration/SearchPaginationIntegrationTest.php
git commit -m "feat: share public authority eligibility across consumers"
```

## Phase B — Graph contract and Brand read model

### Task 6: Correct the intentional MCP catalog contract

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`
- Evidence: `docs/architecture/MCP_EXTERNAL_INTEROPERABILITY_EVIDENCE_2026-09-01.md`

**Interfaces:**
- Consumes: `McpToolCatalog::tools()`.
- Produces: an exact 19-name catalog assertion including read-only
  `nhk.semantic.resolve`.

- [ ] **Step 1: Write the failing contract assertion**

```php
public function test_catalog_contains_the_intentional_read_only_semantic_resolver(): void
{
    $names = array_column(McpToolCatalog::tools(), 'name');
    self::assertCount(19, $names);
    self::assertContains('nhk.semantic.resolve', $names);
    self::assertSame('read', McpToolCatalog::tools()[1]['kind']);
    self::assertFalse(McpToolCatalog::tools()[1]['governed']);
}
```

- [ ] **Step 2: Run the failing stale integration test**

Run: `NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php`

Expected: the two existing catalog assertions fail when the
guarded database is available; if unavailable, record the skip and run the
unit catalog assertion.

- [x] **Step 3: Update the stale expectation with the audited names**

Assert the full ordered list returned by `McpToolCatalog::tools()`, keeping
`nhk.semantic.resolve` explicitly read-only. Do not mechanically replace 18 by
19 without asserting the added name and its source commit `3c41bda`.

- [ ] **Step 4: Run MCP unit and guarded tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php` and then the guarded integration command from Step 2.

Expected: PASS or an environment-only skip; no mutation tool is invoked by
this contract test.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php docs/architecture/MCP_EXTERNAL_INTEROPERABILITY_EVIDENCE_2026-09-01.md
git commit -m "test: record intentional nineteen-tool MCP catalog"
```

### Task 7: Add the approved Graph predicate definitions

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Graph/PredicateRegistry.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GraphCoreContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/BrandRelationshipRegistryTest.php`

**Interfaces:**
- Consumes: `PredicateDefinition`, `EndpointTypeRegistry`, `GraphService`.
- Produces: exact registry entries for `model_of` and `variant_of`; later
  approved requirements remain explicit in the relationship matrix until their
  definitions are separately reviewed.

- [ ] **Step 1: Write failing registry tests**

```php
public function test_structural_registry_defines_only_child_to_parent_backbone_edges(): void
{
    $registry = new PredicateRegistry();
    self::assertSame(['model'], $registry->get('model_of')->allowed_source_types);
    self::assertSame(['brand'], $registry->get('model_of')->allowed_target_types);
    self::assertSame('ONE', $registry->get('model_of')->outbound_cardinality);
    self::assertSame('MANY', $registry->get('model_of')->inbound_cardinality);
    self::assertSame(['variant'], $registry->get('variant_of')->allowed_source_types);
    self::assertSame(['model'], $registry->get('variant_of')->allowed_target_types);
    self::assertNotContains('brand_of', array_column($registry->all(), 'key'));
}

public function test_invalid_backbone_endpoint_combinations_are_rejected_by_graph_service(): void
{
    [$service] = $this->serviceWithBackboneRegistry();
    $this->expectException(InvalidRelationSourceType::class);
    $service->create(new NodeReference('variant', 'variant-1'), 'model_of', new NodeReference('brand', 'brand-1'));
}
```

The test fixture reuses `InMemoryGraphRepository` and `FakeEndpointResolver`
from `tests/Support`, registering `brand-1`, `model-1` and `variant-1` in an
`EndpointTypeRegistry` before constructing `GraphService` with an audit sink.
No WordPress or database bootstrap is needed for this unit boundary.

- [ ] **Step 2: Run the focused tests and verify registry failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/GraphCoreContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/BrandRelationshipRegistryTest.php`

Expected: FAIL because the two predicates are not registered.

- [x] **Step 3: Register the approved predicate definitions**

Add the six approved `PredicateDefinition` entries beside the existing
definitions. Reuse the existing Graph tables and validation. Structural
predicates use child→parent endpoints with ONE outbound/MANY inbound
cardinality; the four semantic relation definitions remain MANY/MANY. No
physical Graph rows are created by registry registration.

- [ ] **Step 4: Run Graph unit tests and PHP lint**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/GraphCoreContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/BrandRelationshipRegistryTest.php` and `php -l public/wp-content/plugins/nhk-core/src/Domain/Graph/PredicateRegistry.php`.

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Domain/Graph/PredicateRegistry.php public/wp-content/plugins/nhk-core/tests/Unit/GraphCoreContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/BrandRelationshipRegistryTest.php
git commit -m "feat: register approved brand backbone predicates"
```

### Task 8: Add read-only structural traversal and derived Brand explanations

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Graph/StructuralContext.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Graph/StructuralContextQuery.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Graph/BrandAggregationQuery.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/StructuralContextQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/BrandAggregationQueryTest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityEligibilityPolicy.php`

**Interfaces:**
- Consumes: `GraphService`, `AuthorityRepository`, `EndpointTypeRegistry`, `PredicateRegistry`, `AuthorityEntity`.
- Produces: `StructuralContext::__construct(string $entityType, string $entityId, ?string $modelId, ?string $brandId, array $relationPath = [], array $reasons = [])`; `StructuralContextQuery::forModel(string $modelId): StructuralContext`; `StructuralContextQuery::forVariant(string $variantId): StructuralContext`; `BrandAggregationQuery::forBrand(string $brandId): array`.

- [ ] **Step 1: Write failing traversal tests**

```php
public function test_variant_brand_context_is_derived_through_two_active_direct_edges(): void
{
    $edgeModel = $this->graph->create($this->ref('model', $modelId), 'model_of', $this->ref('brand', $brandId));
    $edgeVariant = $this->graph->create($this->ref('variant', $variantId), 'variant_of', $this->ref('model', $modelId));
    $context = $this->query->forVariant($variantId);
    self::assertSame($brandId, $context->brandId);
    self::assertSame(['variant_of', 'model_of'], $context->relationPath);
}

public function test_missing_or_multiple_active_parent_is_incomplete_and_never_guessed(): void
{
    $context = $this->query->forVariant($variantId);
    self::assertNull($context->brandId);
    self::assertContains('STRUCTURAL_PARENT_MISSING', $context->reasons);
}
```

- [ ] **Step 2: Run focused tests and verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/StructuralContextQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/BrandAggregationQueryTest.php`

Expected: FAIL because no structural traversal boundary exists.

- [ ] **Step 3: Implement read-only traversal**

Query active incoming/outgoing Graph edges through the existing Graph service,
require exactly one active direct parent for Model and Variant, reject retired,
missing, invalid or multiple active parents, and derive Variant Brand context
only through `variant_of` then `model_of`. The query must not read payload parent
fields after the explicit transition seam and must not call a mutation method.

- [ ] **Step 4: Implement Brand aggregation**

Return independently eligible items with an origin object such as
`['kind'=>'DERIVED','path'=>['variant_of','model_of']]` or
`['kind'=>'DIRECT','path'=>['model_of']]`. Do not persist aggregate results or
shortcut relations. Shared Movement, Music, Component and Classification may
appear only through valid registered/evidenced paths and never receive a fake
Brand parent.

- [ ] **Step 5: Run focused and Graph regression tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/StructuralContextQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/BrandAggregationQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/GraphCoreContractTest.php`

Expected: PASS with zero Graph writes in test fixtures beyond the explicit
in-memory test setup.

- [ ] **Step 6: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Graph/StructuralContextQuery.php public/wp-content/plugins/nhk-core/src/Application/Graph/BrandAggregationQuery.php public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityEligibilityPolicy.php public/wp-content/plugins/nhk-core/tests/Unit/StructuralContextQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/BrandAggregationQueryTest.php
git commit -m "feat: derive brand context through direct graph parents"
```

### Task 9: Add the read-only 241-edge distribution audit

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Graph/GraphDistributionAudit.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Graph/GraphDistributionReader.php`
- Create: `tools/graph-distribution-audit.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Support/InMemoryGraphDistributionReader.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GraphDistributionAuditTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`

**Interfaces:**
- Consumes: read-only `GraphDistributionReader::rows(): array` adapter data.
- Produces: rows with `source_type`, `predicate`, `target_type`, `edge_count`,
  plus total active edges and registered predicate count.

The new interface is `GraphDistributionReader::rows(): array`, where each row
contains `source_type:string`, `predicate:string`, `target_type:string` and
`edge_count:int`. The WPDB adapter may return grouped rows directly; the unit
reader returns the same normalized shape.

- [ ] **Step 1: Write failing audit tests**

```php
public function test_audit_groups_active_edges_by_source_predicate_and_target_without_mutation_calls(): void
{
    $reader = new InMemoryGraphDistributionReader([
        ['source_type' => 'wp_post', 'predicate' => 'about', 'target_type' => 'brand'],
        ['source_type' => 'wp_post', 'predicate' => 'about', 'target_type' => 'brand'],
    ]);
    $audit = (new GraphDistributionAudit($reader))->read();
    self::assertSame([[
        'source_type' => 'wp_post', 'predicate' => 'about', 'target_type' => 'brand', 'edge_count' => 2,
    ]], $audit['distribution']);
    self::assertSame(2, $audit['active_edge_total']);
}
```

- [ ] **Step 2: Run the focused test and verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/GraphDistributionAuditTest.php`

Expected: FAIL because the read-only audit boundary is absent.

- [ ] **Step 3: Implement the grouped reader and CLI**

Use the active WordPress table prefix, group by source endpoint type, predicate
key and target endpoint type, and emit stable sorted JSON/table output. The
CLI must fail closed when WordPress is unavailable and must identify that the
exact matrix is unverified rather than substituting the aggregate 241 count.

- [ ] **Step 4: Run the audit when the database is available**

Run: `php tools/graph-distribution-audit.php --format=json`

Expected: either a complete matrix whose counts sum to the active edge total,
or a non-zero environment error stating that the WordPress database is
unavailable. Never run a migration or mutation command as a fallback.

- [ ] **Step 5: Run tests and commit the read-only tool**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/GraphDistributionAuditTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Graph/GraphDistributionAudit.php tools/graph-distribution-audit.php public/wp-content/plugins/nhk-core/tests/Unit/GraphDistributionAuditTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php
git commit -m "feat: add read-only graph distribution audit"
```

## Phase C — Diagnostics

### Task 10: Report structural orphans and multiple parents

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Graph/StructuralDiagnostics.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/StructuralDiagnosticsTest.php`

**Interfaces:**
- Consumes: Authority collections and `StructuralContextQuery`.
- Produces: deterministic findings with `entity_type`, `entity_id`,
  `status`, `reason_code`, `parent_candidates`, and no mutation command.

- [ ] **Step 1: Write the failing tests**

```php
public function test_active_model_without_one_safe_parent_is_reported_as_structural_parent_missing(): void
{
    $findings = $this->diagnostics->read();
    self::assertSame('STRUCTURAL_PARENT_MISSING', $findings[0]['reason_code']);
    self::assertSame('model', $findings[0]['entity_type']);
}

public function test_active_variant_with_two_parent_candidates_is_reported_as_ambiguous(): void
{
    $findings = $this->diagnostics->read();
    self::assertSame('STRUCTURAL_PARENT_AMBIGUOUS', $findings[0]['reason_code']);
    self::assertCount(2, $findings[0]['parent_candidates']);
}
```

- [ ] **Step 2: Run the test to verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/StructuralDiagnosticsTest.php`

Expected: FAIL because diagnostics are not implemented.

- [ ] **Step 3: Implement deterministic read-only diagnostics**

Inspect only active registered entities and active Graph edges. Sort findings by
entity type and canonical UUID. Do not create proposals or edges from a
finding, and do not treat a payload parent as a Graph edge.

- [ ] **Step 4: Run focused tests and commit**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/StructuralDiagnosticsTest.php`

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Graph/StructuralDiagnostics.php public/wp-content/plugins/nhk-core/tests/Unit/StructuralDiagnosticsTest.php
git commit -m "feat: report structural graph compatibility findings"
```

### Task 11: Report registry gaps and public exclusion reasons

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Graph/RegistryGapReport.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicExclusionReport.php`
- Create: `tools/structural-diagnostics.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/RegistryGapReportTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicExclusionReportTest.php`

**Interfaces:**
- Consumes: `PredicateRegistry`, approved relationship matrix, `PublicEntityEligibilityPolicy`.
- Produces: bounded reports distinguishing `REGISTRY_GAP`, `CODE_GAP`,
  `DATA_COMPATIBILITY_GAP`, `STRUCTURAL_PARENT_MISSING`,
  `STRUCTURAL_PARENT_AMBIGUOUS` and `CONSTITUTION_CONFLICT`.

- [ ] **Step 1: Write failing tests**

```php
public function test_report_keeps_missing_predicate_separate_from_missing_data(): void
{
    $report = $this->gaps->read();
    self::assertSame('REGISTERED', $report['model_of']['classification']);
    self::assertSame('REGISTERED', $report['uses_movement']['classification']);
}

public function test_public_exclusion_report_does_not_rewrite_entity_state(): void
{
    $before = $this->entity->revision;
    $result = $this->exclusions->evaluate($this->entity);
    self::assertContains('STRUCTURAL_PARENT_MISSING', $result['reasons']);
    self::assertSame($before, $this->entity->revision);
}
```

- [ ] **Step 2: Run focused tests and verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/RegistryGapReportTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicExclusionReportTest.php`

Expected: FAIL because the reports do not exist.

- [ ] **Step 3: Implement bounded reports and CLI**

Compare the explicitly approved predicate list with `PredicateRegistry::all()`;
do not infer new predicates from fixtures. Emit public exclusion reasons from
the shared policy, keep transition warnings distinct from blocking reasons and
preserve all entity revisions/states. The CLI must support read-only JSON output
and return non-zero only for environment/contract errors, not because findings
exist.

- [ ] **Step 4: Run focused tests and commit**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/RegistryGapReportTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicExclusionReportTest.php`

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Graph/RegistryGapReport.php public/wp-content/plugins/nhk-core/src/Application/Entity/PublicExclusionReport.php tools/structural-diagnostics.php public/wp-content/plugins/nhk-core/tests/Unit/RegistryGapReportTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicExclusionReportTest.php
git commit -m "feat: classify registry and public eligibility gaps"
```

### Task 12: Final verification and architecture checkpoint

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/V2_V3_PARITY_MATRIX.md`
- Modify: `docs/architecture/V3_PUBLIC_HUB_MATRIX.md`
- Modify: `docs/architecture/V3_BRAND_RELATIONSHIP_MATRIX.md`
- Test: existing Unit and guarded Integration suites

**Interfaces:**
- Consumes: all Phase A–C read-only evidence and route/Graph reports.
- Produces: an evidence-backed checkpoint; no runtime mutation beyond the
  separately approved predicate registry migration if that migration is part
  of the accepted contract milestone.

- [ ] **Step 1: Run the complete verification set**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite 'NHK Unit'
NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite 'NHK Integration'
find public/wp-content/plugins/nhk-core/src -name '*.php' -print0 | xargs -0 -n1 php -l
php tools/frontend-route-smoke.php --base-url=http://localhost
php tools/graph-distribution-audit.php --format=json
git diff --check
```

Expected: Unit and available guarded tests pass; environment-gated checks state
their blocker explicitly; no command performs DB reset, import or Graph repair.

- [ ] **Step 2: Run a secret review**

Run: `git diff --cached -- . ':!public/wp-content/uploads/**' | rg -n "(BEGIN (RSA|OPENSSH|EC) PRIVATE KEY|api[_-]?key|secret|token|password|DB_PASSWORD)"`

Expected: no newly introduced credential or private-key material. Existing
local configuration and test fixtures remain uncommitted where policy requires.

- [ ] **Step 3: Update execution state with exact evidence**

Record the canonical hub result, route divergence resolution, MCP 19-tool
finding, registry-gap list, diagnostic counts, exact Graph matrix if available,
or the database-unavailable status if not. Preserve the separate
`CONSTITUTION_CONFLICT` and `DATA_COMPATIBILITY_GAP` entries. State explicitly
that no physical Graph repair occurred.

- [ ] **Step 4: Commit the documentation checkpoint**

```bash
git add docs/architecture/V3_EXECUTION_STATE.md docs/architecture/V2_V3_PARITY_MATRIX.md docs/architecture/V3_PUBLIC_HUB_MATRIX.md docs/architecture/V3_BRAND_RELATIONSHIP_MATRIX.md
git commit -m "docs: checkpoint entity hubs and brand graph evidence"
```

## Explicitly excluded work

The following are not tasks in this plan: physical `model_of`, `variant_of`,
`uses_movement`, `supports_music`, `configured_with_music` or
`observed_playing_music` backfill; payload-parent rewriting; identity merges;
legacy article-body import/parsing; source/evidence publication decisions;
MediaAsset recovery/publication; V2/live changes; production cutover; and any
bulk write performed outside Governance, evidence, human approval, eligibility,
Controlled Apply, Graph and durable audit.

## Plan self-review

- Public hub, detail, menu, redirect, query, REST, search and template work is
  covered by Tasks 1–5.
- MCP catalog evidence and the stale 18-tool assertions are covered by Task 6.
- The two structural registry requirements, direct cardinality, two-hop Brand
  derivation, path explanations and read-only 241-edge audit are covered by
  Tasks 7–9.
- Orphan, multiple-parent, registry-gap and public exclusion reporting are
  covered by Tasks 10–11.
- Final parity/execution-state evidence and no-mutation verification are covered
  by Task 12.
- No step authorizes a physical relationship repair or legacy article-body
  migration. Existing payload parent fields are explicitly transitional and
  `CONSTITUTION_CONFLICT` when treated as canonical Graph truth.
- Every step is concrete; no guessed predicate or unbounded catch is required by the plan.
