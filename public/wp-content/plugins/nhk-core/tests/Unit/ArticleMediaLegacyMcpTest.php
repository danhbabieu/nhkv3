<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{ArticleMediaCandidateSelector, ArticleMediaLegacyAudit, ArticleMediaLegacyRepairPlan, MediaService, SemanticSuitabilityPolicy};
use NHK\Core\Application\Mcp\{ArticleMediaLegacyAuditHandler, McpAbilityRegistration, McpCapabilityManifest, McpDispatchRegistry, McpGovernanceHandler, McpReadHandler, McpToolCatalog, McpTransport, SingleEntryPointPolicy};
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository};
use NHK\Core\Contracts\Media\{ArticleMediaBlueprintRepository, ArticleMediaUsageInventory, MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaSeoBlueprint, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class ArticleMediaLegacyMcpTest extends TestCase
{
    public function test_article_media_audit_is_a_bounded_internal_read_only_mcp_ability(): void
    {
        $tool = array_values(array_filter(McpToolCatalog::tools(), static fn (array $tool): bool => $tool['name'] === 'nhk.article.media-legacy-audit'))[0] ?? null;

        self::assertIsArray($tool);
        self::assertSame('read', $tool['kind']);
        self::assertFalse($tool['governed']);
        self::assertSame('internal_admin_only', $tool['surface']);
        self::assertTrue(McpDispatchRegistry::hasHandler('nhk.article.media-legacy-audit'));
        self::assertSame('nhk-v3/article-media-legacy-audit', McpAbilityRegistration::abilityNameForTool('nhk.article.media-legacy-audit'));
        self::assertContains('nhk-v3/article-media-legacy-audit', McpAbilityRegistration::explicitInternalAdminReadOnlyAbilityAllowlist());
        self::assertContains('nhk.article.media-legacy-audit', McpCapabilityManifest::all()['article']['reads']);
        self::assertSame('wp_ability_nhk_v3_article_media_legacy_audit', McpAbilityRegistration::connectorToolNameForAbility('nhk-v3/article-media-legacy-audit'));
        self::assertSame(200, $tool['inputSchema']['properties']['limit']['maximum']);
        self::assertSame([], $tool['inputSchema']['required']);
        self::assertContains('nhk.article.media-legacy-repair-plan', McpToolCatalog::names());

        $names = array_column($this->transport(new ArticleMediaLegacyAuditHandler(...$this->auditServices()))->dispatch([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
        ])['body']['result']['tools'], 'name');
        self::assertContains('nhk.article.media-legacy-audit', $names);
        self::assertContains('nhk.article.media-legacy-repair-plan', $names);
    }

    public function test_subject_reverse_reconciliation_exposes_only_bounded_read_and_plan_tools(): void
    {
        foreach (['nhk.article.media-subject-reconciliation-audit', 'nhk.article.media-subject-reconciliation-plan'] as $name) {
            $tool = array_values(array_filter(McpToolCatalog::tools(), static fn (array $tool): bool => $tool['name'] === $name))[0] ?? null;
            self::assertIsArray($tool);
            self::assertSame('read', $tool['kind']);
            self::assertFalse($tool['governed']);
            self::assertSame('internal_admin_only', $tool['surface']);
            self::assertTrue(McpDispatchRegistry::hasHandler($name));
            self::assertSame(['endpoint_type', 'endpoint_key'], $tool['inputSchema']['required']);
            self::assertSame('^[1-9][0-9]*:[1-9][0-9]*$', $tool['inputSchema']['properties']['endpoint_key']['pattern']);
        }
    }

    public function test_audit_transport_returns_bounded_envelope_without_writes(): void
    {
        [$audit, $media, $assets, $usages, $blueprints, $service] = $this->fixture();
        $wrong = $service->create('mcp-audit-wrong', 'Wrong', 'ready', ['subject_ids' => ['subject-other']]);
        $this->publicAsset($service, $wrong, 'mcp-audit-wrong');
        $service->addUsage($wrong->canonicalId, 'wp_post', '1:901', 'featured_primary', 0, 'Alt', 'Caption', [], 'Title', 'featured_primary');
        $blueprints->items['901:featured_primary'] = MediaSeoBlueprint::forPost(901, 'featured_primary', ['subject_context' => ['subject_ids' => ['subject-target']]]);

        $transport = $this->transport(new ArticleMediaLegacyAuditHandler($audit, new ArticleMediaLegacyRepairPlan($audit, $usages)));
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'nhk.article.media-legacy-audit',
            'arguments' => ['limit' => 1],
        ]]);
        $result = $response['body']['result']['structuredContent'];

        self::assertSame(200, $response['status']);
        self::assertSame('AVAILABLE', $result['status']);
        self::assertTrue($result['read_only']);
        self::assertFalse($result['mutated']);
        self::assertArrayHasKey('items', $result);
        self::assertArrayHasKey('next_cursor', $result);
        self::assertArrayHasKey('has_more', $result);
        self::assertArrayHasKey('summary', $result);
        self::assertSame('REMOVE_TO_PLACEHOLDER', $result['items'][0]['disposition']);
        self::assertSame(0, $media->updates);
        self::assertSame(0, $usages->updates);
    }

    public function test_repair_preview_uses_audited_candidate_and_rejects_arbitrary_replacement(): void
    {
        [$audit, $media, $assets, $usages, $blueprints, $service] = $this->fixture();
        $wrong = $service->create('mcp-plan-wrong', 'Wrong', 'ready', ['subject_ids' => ['subject-other']]);
        $exact = $service->create('mcp-plan-exact', 'Exact', 'ready', ['subject_ids' => ['subject-target']]);
        $injected = $service->create('mcp-plan-injected', 'Injected', 'ready', ['subject_ids' => ['subject-target']]);
        $this->publicAsset($service, $wrong, 'mcp-plan-wrong');
        $this->publicAsset($service, $exact, 'mcp-plan-exact');
        $this->publicAsset($service, $injected, 'mcp-plan-injected');
        $usage = $service->addUsage($wrong->canonicalId, 'wp_post', '1:902', 'featured_primary', 0, 'Alt', 'Caption', [], 'Title', 'featured_primary');
        $blueprints->items['902:featured_primary'] = MediaSeoBlueprint::forPost(902, 'featured_primary', ['subject_context' => ['subject_ids' => ['subject-target']]]);

        $handler = new ArticleMediaLegacyAuditHandler($audit, new ArticleMediaLegacyRepairPlan($audit, $usages));
        $finding = $handler->audit(['limit' => 10])['items'][0];
        $plan = $handler->repairPlan(['finding' => [
            'usage_id' => $finding['usage_id'],
            'endpoint_type' => $finding['endpoint_type'],
            'endpoint_key' => $finding['endpoint_key'],
            'fingerprint' => $finding['fingerprint'],
            'dependency_fingerprint' => $finding['dependency_fingerprint'],
            'expected_usage_revision' => $usage->revision,
            'subject_id' => $finding['subject_id'],
            'subject_revision' => $finding['subject_revision'],
            'replacement_media_id' => $injected->canonicalId,
        ]]);

        self::assertSame('PREVIEW', $plan['status']);
        self::assertTrue($plan['read_only']);
        self::assertFalse($plan['mutated']);
        self::assertSame('replace', $plan['operation']);
        self::assertSame($exact->canonicalId, $plan['governed_operation']['arguments']['media']['id']);
        self::assertNotSame($injected->canonicalId, $plan['governed_operation']['arguments']['media']['id']);
        self::assertSame('nhk.media.usage', $plan['governed_operation']['tool']);
        self::assertSame(0, $usages->updates);
    }

    public function test_repair_preview_rejects_stale_usage_revision_and_protected_usage(): void
    {
        [$audit, $media, $assets, $usages, $blueprints, $service] = $this->fixture();
        $pinned = $service->create('mcp-plan-pinned', 'Pinned', 'ready');
        $this->publicAsset($service, $pinned, 'mcp-plan-pinned');
        $usage = $service->addUsage($pinned->canonicalId, 'wp_post', '1:903', 'featured_primary', 0, '', '', [], '', 'featured_primary', 'USER_EXPLICIT', 'PINNED');
        $handler = new ArticleMediaLegacyAuditHandler($audit, new ArticleMediaLegacyRepairPlan($audit, $usages));
        $finding = $handler->audit(['limit' => 10])['items'][0];

        $stale = $handler->repairPlan(['finding' => [
            'usage_id' => $finding['usage_id'], 'endpoint_type' => 'wp_post', 'endpoint_key' => '1:903',
            'fingerprint' => $finding['fingerprint'], 'dependency_fingerprint' => $finding['dependency_fingerprint'], 'expected_usage_revision' => $usage->revision + 1,
            'subject_id' => '', 'subject_revision' => '',
        ]]);
        self::assertSame('REVIEW_REQUIRED', $stale['status']);
        self::assertSame('USAGE_REVISION_CHANGED', $stale['reason']);
        self::assertSame(0, $usages->updates);

        $protected = $handler->repairPlan(['finding' => [
            'usage_id' => $finding['usage_id'], 'endpoint_type' => 'wp_post', 'endpoint_key' => '1:903',
            'fingerprint' => $finding['fingerprint'], 'dependency_fingerprint' => $finding['dependency_fingerprint'], 'expected_usage_revision' => $usage->revision,
            'subject_id' => '', 'subject_revision' => '',
        ]]);
        self::assertSame('PROTECTED', $protected['status']);
        self::assertArrayNotHasKey('governed_operation', $protected);
    }

    public function test_valid_system_auto_usage_is_reported_without_repair_operation(): void
    {
        [$audit, $media, $assets, $usages, $blueprints, $service] = $this->fixture();
        $valid = $service->create('mcp-valid-auto', 'Valid', 'ready', ['subject_ids' => ['subject-target']]);
        $this->publicAsset($service, $valid, 'mcp-valid-auto');
        $service->addUsage($valid->canonicalId, 'wp_post', '1:904', 'featured_primary', 0, 'Alt', 'Caption', [], 'Title', 'featured_primary');
        $blueprints->items['904:featured_primary'] = MediaSeoBlueprint::forPost(904, 'featured_primary', ['subject_context' => ['subject_ids' => ['subject-target']]]);

        $result = (new ArticleMediaLegacyAuditHandler($audit, new ArticleMediaLegacyRepairPlan($audit, $usages)))->audit(['limit' => 10]);

        self::assertSame('VALID', $result['items'][0]['disposition']);
        self::assertArrayNotHasKey('governed_operation', $result['items'][0]);
        self::assertSame(0, $usages->updates);
    }

    public function test_repair_preview_requires_fresh_candidate_dependency(): void
    {
        [$audit, $media, $assets, $usages, $blueprints, $service] = $this->fixture();
        $wrong = $service->create('mcp-stale-wrong', 'Wrong', 'ready', ['subject_ids' => ['subject-other']]);
        $exact = $service->create('mcp-stale-exact', 'Exact', 'ready', ['subject_ids' => ['subject-target']]);
        $this->publicAsset($service, $wrong, 'mcp-stale-wrong');
        $this->publicAsset($service, $exact, 'mcp-stale-exact');
        $usage = $service->addUsage($wrong->canonicalId, 'wp_post', '1:905', 'featured_primary', 0, 'Alt', 'Caption', [], 'Title', 'featured_primary');
        $blueprints->items['905:featured_primary'] = MediaSeoBlueprint::forPost(905, 'featured_primary', ['subject_context' => ['subject_ids' => ['subject-target']]]);

        $handler = new ArticleMediaLegacyAuditHandler($audit, new ArticleMediaLegacyRepairPlan($audit, $usages));
        $finding = $handler->audit(['limit' => 10])['items'][0];
        $media->items[$exact->canonicalId] = new Media($exact->canonicalId, $exact->stableKey, $exact->canonicalName, 'ready', $exact->provenance, false, $exact->revision + 1);

        $plan = $handler->repairPlan(['finding' => [
            'usage_id' => $finding['usage_id'], 'endpoint_type' => $finding['endpoint_type'], 'endpoint_key' => $finding['endpoint_key'],
            'fingerprint' => $finding['fingerprint'], 'dependency_fingerprint' => $finding['dependency_fingerprint'], 'expected_usage_revision' => $usage->revision,
            'subject_id' => $finding['subject_id'], 'subject_revision' => $finding['subject_revision'],
        ]]);

        self::assertSame('REVIEW_REQUIRED', $plan['status']);
        self::assertSame('AUDIT_FINGERPRINT_STALE', $plan['reason']);
        self::assertSame(0, $usages->updates);
    }

    public function test_inactive_media_is_invalid_and_never_repaired_without_safe_replacement(): void
    {
        [$audit, $media, $assets, $usages, $blueprints, $service] = $this->fixture();
        $inactive = $service->create('mcp-inactive', 'Inactive', 'ready', ['subject_ids' => ['subject-target']]);
        $media->items[$inactive->canonicalId] = new Media($inactive->canonicalId, $inactive->stableKey, $inactive->canonicalName, $inactive->readiness, $inactive->provenance, false, $inactive->revision + 1);
        $this->publicAsset($service, $inactive, 'mcp-inactive');
        $service->addUsage($inactive->canonicalId, 'wp_post', '1:906', 'featured_primary');
        $blueprints->items['906:featured_primary'] = MediaSeoBlueprint::forPost(906, 'featured_primary', ['subject_context' => ['subject_ids' => ['subject-target']]]);

        $finding = (new ArticleMediaLegacyAuditHandler($audit, new ArticleMediaLegacyRepairPlan($audit, $usages)))->audit(['limit' => 10])['items'][0];

        self::assertSame('MEDIA_INACTIVE', $finding['reason']);
        self::assertSame('REMOVE_TO_PLACEHOLDER', $finding['disposition']);
        self::assertArrayNotHasKey('replacement_media_id', $finding);
        self::assertSame(0, $usages->updates);
    }

    public function test_subject_revision_drift_invalidates_audit_finding_before_preview(): void
    {
        [$audit, $media, $assets, $usages, $blueprints, $service] = $this->fixture();
        $exact = $service->create('mcp-stale-subject', 'Stale subject', 'ready', ['subject_ids' => ['subject-target'], 'subject_revision' => 'v1']);
        $this->publicAsset($service, $exact, 'mcp-stale-subject');
        $usage = $service->addUsage($exact->canonicalId, 'wp_post', '1:907', 'featured_primary');
        $blueprints->items['907:featured_primary'] = MediaSeoBlueprint::forPost(907, 'featured_primary', ['subject_context' => ['subject_ids' => ['subject-target'], 'subject_revision' => 'v1']]);

        $handler = new ArticleMediaLegacyAuditHandler($audit, new ArticleMediaLegacyRepairPlan($audit, $usages));
        $finding = $handler->audit(['limit' => 10])['items'][0];
        $blueprints->items['907:featured_primary'] = MediaSeoBlueprint::forPost(907, 'featured_primary', ['subject_context' => ['subject_ids' => ['subject-target'], 'subject_revision' => 'v2']]);

        $plan = $handler->repairPlan(['finding' => [
            'usage_id' => $finding['usage_id'], 'endpoint_type' => $finding['endpoint_type'], 'endpoint_key' => $finding['endpoint_key'],
            'fingerprint' => $finding['fingerprint'], 'dependency_fingerprint' => $finding['dependency_fingerprint'], 'expected_usage_revision' => $usage->revision,
            'subject_id' => $finding['subject_id'], 'subject_revision' => $finding['subject_revision'],
        ]]);

        self::assertSame('VALID', $finding['disposition']);
        self::assertSame('REVIEW_REQUIRED', $plan['status']);
        self::assertSame('AUDIT_FINGERPRINT_STALE', $plan['reason']);
        self::assertSame(0, $usages->updates);
    }

    public function test_audit_handler_enforces_the_two_hundred_item_bound(): void
    {
        [$audit] = $this->fixture();
        $handler = new ArticleMediaLegacyAuditHandler($audit, new ArticleMediaLegacyRepairPlan($audit, $this->fixture()[3]));

        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('ARTICLE_MEDIA_LEGACY_AUDIT_LIMIT_INVALID');
        $handler->audit(['limit' => 201]);
    }

    private function transport(ArticleMediaLegacyAuditHandler $handler): McpTransport
    {
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
        );
        return new McpTransport(
            $read,
            new McpGovernanceHandler(new GovernanceService(new \NHK\Tests\Support\InMemoryProposalRepository())),
            static fn (string $capability): bool => in_array($capability, ['nhk_view_governance', SingleEntryPointPolicy::INTERNAL_CAPABILITY], true),
            articleMediaLegacyAudit: $handler,
        );
    }

    /** @return array{0:ArticleMediaLegacyAudit,1:object,2:object,3:object,4:object,5:MediaService} */
    private function fixture(): array
    {
        $media = new class implements MediaRepository {
            public array $items = []; public int $updates = 0;
            public function findByCanonicalId(string $id): ?Media { return $this->items[$id] ?? null; }
            public function findByStableKey(string $key): ?Media { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(Media $item): Media { return $this->items[$item->canonicalId] = $item; }
            public function update(Media $item, int $expectedRevision): Media { ++$this->updates; return $this->items[$item->canonicalId] = $item; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $assets = new class implements MediaAssetRepository {
            public array $items = [];
            public function findByAssetId(string $id): ?MediaAsset { return $this->items[$id] ?? null; }
            public function create(MediaAsset $asset): MediaAsset { return $this->items[$asset->assetId] = $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $this->items[$asset->assetId] = $asset; }
            public function listByMediaId(string $id): array { return array_values(array_filter($this->items, static fn (MediaAsset $asset): bool => $asset->mediaId === $id)); }
            public function findByChecksum(string $checksum): array { return []; }
        };
        $usages = new class implements MediaUsageRepository {
            public array $items = []; public int $updates = 0;
            public function create(MediaUsage $usage): MediaUsage { return $this->items[$usage->usageId] = $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->mediaId === $id && ($role === null || $usage->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->endpointType === $type && $usage->endpointKey === $key && ($role === null || $usage->role === $role))); }
        };
        $blueprints = new class implements ArticleMediaBlueprintRepository {
            public array $items = [];
            public function findByPostAndSlot(int $postId, string $slot): ?MediaSeoBlueprint { return $this->items[$postId . ':' . $slot] ?? null; }
            public function save(MediaSeoBlueprint $blueprint): MediaSeoBlueprint { return $this->items[$blueprint->postId . ':' . $blueprint->slot] = $blueprint; }
            public function listByPost(int $postId): array { return array_values(array_filter($this->items, static fn (MediaSeoBlueprint $blueprint): bool => $blueprint->postId === $postId)); }
        };
        $inventory = new class($usages) implements ArticleMediaUsageInventory {
            public function __construct(private object $usages) {}
            public function page(string $endpointType, ?string $afterUsageId, int $limit): array
            {
                $items = array_values(array_filter($this->usages->items, static fn (MediaUsage $usage): bool => $usage->endpointType === $endpointType));
                usort($items, static fn (MediaUsage $left, MediaUsage $right): int => $left->usageId <=> $right->usageId);
                if ($afterUsageId !== null && $afterUsageId !== '') $items = array_values(array_filter($items, static fn (MediaUsage $usage): bool => $usage->usageId > $afterUsageId));
                $page = array_slice($items, 0, $limit);
                return ['items' => $page, 'next_cursor' => count($items) > count($page) ? ($page[array_key_last($page)]->usageId ?? null) : null];
            }
        };
        $service = new MediaService($media, $assets, $usages);
        return [new ArticleMediaLegacyAudit($inventory, $media, $assets, $usages, $blueprints, new ArticleMediaCandidateSelector($media, $assets, $usages, new SemanticSuitabilityPolicy()), new SemanticSuitabilityPolicy()), $media, $assets, $usages, $blueprints, $service];
    }

    /** @return array{0:ArticleMediaLegacyAudit,1:ArticleMediaLegacyRepairPlan} */
    private function auditServices(): array
    {
        [$audit, $media, $assets, $usages] = $this->fixture();
        return [$audit, new ArticleMediaLegacyRepairPlan($audit, $usages)];
    }

    private function publicAsset(MediaService $service, Media $media, string $stem): void
    {
        $service->addAsset($media->canonicalId, 'original', 'uploads/' . $stem . '.webp', hash('sha256', $stem), 'image/webp', 10, 1200, 675, 'PUBLIC', ['canonical_filename' => $stem . '.webp']);
    }
}
