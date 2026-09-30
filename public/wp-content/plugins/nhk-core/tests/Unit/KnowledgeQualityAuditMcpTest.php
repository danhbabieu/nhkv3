<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\KnowledgeQualityAuditCoordinator;
use NHK\Core\Application\Knowledge\KnowledgeQualityAuditor;
use NHK\Core\Application\Mcp\KnowledgeQualityAuditHandler;
use NHK\Core\Application\Mcp\McpAbilityRegistration;
use NHK\Core\Application\Mcp\McpCapabilityManifest;
use NHK\Core\Application\Mcp\McpDispatchRegistry;
use NHK\Core\Application\Mcp\McpToolCatalog;
use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgePageReader, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class KnowledgeQualityAuditMcpTest extends TestCase
{
    public function test_handler_pages_without_duplicates_and_is_deterministic(): void
    {
        $claims = $this->claims(7);
        $repository = new QualityAuditMcpClaims($claims);
        $handler = $this->handler($repository);

        $firstRun = $this->collectPages($handler, 3);
        $secondRun = $this->collectPages($handler, 3);

        self::assertSame($firstRun, $secondRun);
        self::assertCount(7, $firstRun['ids']);
        self::assertSame($firstRun['ids'], array_values(array_unique($firstRun['ids'])));
        self::assertSame([3, 3, 1], $firstRun['page_sizes']);
        self::assertSame(0, $repository->writes);
    }

    public function test_handler_returns_privacy_safe_items_and_aggregates(): void
    {
        $subject = UuidCodec::newV7();
        $claim = new KnowledgeClaim(
            UuidCodec::newV7(),
            'nhk:quality:private-marker',
            'PRIVATE_CLAIM_TEXT_MUST_NOT_ESCAPE',
            'fact',
            ['metadata' => [
                'subject_id' => $subject,
                'subject_type' => 'variant',
                'scope' => 'variant',
                'facet' => 'configuration',
                'provenance_class' => 'LEGACY_UNKNOWN',
            ]],
        );
        $source = new Source(UuidCodec::newV7(), 'private.audit', 'PRIVATE_SOURCE_TITLE', 'catalog', 'https://example.test/private', ['visibility' => 'PRIVATE'], true, 1);
        $evidence = new Evidence(UuidCodec::newV7(), $claim->canonicalId, $source->canonicalId, 'supports', 'PRIVATE_EVIDENCE_EXCERPT', null, true, 1, ['visibility' => 'PRIVATE']);
        $repository = new QualityAuditMcpClaims([$claim]);
        $handler = $this->handler($repository, [$evidence], [$source]);

        $response = $handler->audit(['limit' => 10]);
        $serialized = json_encode($response, JSON_THROW_ON_ERROR);

        self::assertTrue($response['read_only']);
        self::assertFalse($response['mutated']);
        self::assertSame(1, $response['total']);
        self::assertSame(1, $response['aggregate']['counts_by_scope']['variant']);
        self::assertSame(1, $response['aggregate']['counts_by_readiness']['PARTIAL']);
        self::assertStringNotContainsString('PRIVATE_CLAIM_TEXT_MUST_NOT_ESCAPE', $serialized);
        self::assertStringNotContainsString('PRIVATE_SOURCE_TITLE', $serialized);
        self::assertStringNotContainsString('PRIVATE_EVIDENCE_EXCERPT', $serialized);
        self::assertSame(0, $repository->writes);
    }

    public function test_handler_applies_registered_filters_and_rejects_invalid_cursor(): void
    {
        $subject = UuidCodec::newV7();
        $claims = [
            new KnowledgeClaim(UuidCodec::newV7(), 'nhk:quality:filter-a', 'A', 'fact', ['metadata' => ['subject_id' => $subject, 'subject_type' => 'variant', 'scope' => 'variant', 'facet' => 'identity', 'provenance_class' => 'EXPLICIT_USER_KNOWLEDGE']]),
            new KnowledgeClaim(UuidCodec::newV7(), 'nhk:quality:filter-b', 'B', 'fact', ['metadata' => ['subject_id' => UuidCodec::newV7(), 'subject_type' => 'brand', 'scope' => 'brand', 'facet' => 'identity', 'provenance_class' => 'LEGACY_UNKNOWN']]),
        ];
        $handler = $this->handler(new QualityAuditMcpClaims($claims));

        $filtered = $handler->audit(['subject_id' => $subject, 'scope' => 'variant', 'readiness' => 'PARTIAL']);
        self::assertSame(['nhk:quality:filter-a'], array_column($filtered['items'], 'stable_key'));

        $this->expectExceptionMessage('KNOWLEDGE_QUALITY_AUDIT_CURSOR_INVALID');
        $handler->audit(['cursor' => 'INVALID CURSOR']);
    }

    public function test_catalog_dispatch_and_ability_contract_exposes_internal_read_only_operation(): void
    {
        $tool = array_values(array_filter(McpToolCatalog::tools(), static fn (array $tool): bool => $tool['name'] === 'nhk.knowledge.quality-audit'))[0] ?? null;

        self::assertIsArray($tool);
        self::assertSame('read', $tool['kind']);
        self::assertFalse($tool['governed']);
        self::assertSame('internal_admin_only', $tool['surface']);
        self::assertTrue(McpDispatchRegistry::hasHandler('nhk.knowledge.quality-audit'));
        self::assertSame('nhk-v3/knowledge-quality-audit', McpAbilityRegistration::abilityNameForTool('nhk.knowledge.quality-audit'));
        self::assertContains('nhk-v3/knowledge-quality-audit', McpAbilityRegistration::capabilityGatedReadAbilityNames());
        self::assertContains('nhk-v3/knowledge-quality-audit', McpAbilityRegistration::explicitInternalAdminAbilityAllowlist());
        self::assertContains('nhk.knowledge.quality-audit', McpCapabilityManifest::all()['knowledge']['reads']);
    }

    /** @return array{ids:list<string>,page_sizes:list<int>} */
    private function collectPages(KnowledgeQualityAuditHandler $handler, int $limit): array
    {
        $cursor = null;
        $ids = [];
        $pageSizes = [];
        do {
            $response = $handler->audit(['limit' => $limit, 'cursor' => $cursor]);
            $pageSizes[] = $response['total'];
            foreach ($response['items'] as $item) $ids[] = $item['stable_key'];
            $cursor = $response['next_cursor'];
        } while ($response['has_more']);

        return ['ids' => $ids, 'page_sizes' => $pageSizes];
    }

    /** @param list<KnowledgeClaim> $claims @param list<Evidence> $evidence @param list<Source> $sources */
    private function handler(QualityAuditMcpClaims $claims, array $evidence = [], array $sources = []): KnowledgeQualityAuditHandler
    {
        return new KnowledgeQualityAuditHandler(new KnowledgeQualityAuditCoordinator(
            new KnowledgeQualityAuditor($claims, new QualityAuditMcpEvidence($evidence), new QualityAuditMcpSources($sources), new StructuredSemanticInterpreter()),
            $claims,
        ));
    }

    /** @return list<KnowledgeClaim> */
    private function claims(int $count): array
    {
        return array_map(static fn (int $i): KnowledgeClaim => new KnowledgeClaim(UuidCodec::newV7(), 'nhk:quality:page-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'Claim ' . $i, 'fact', ['metadata' => ['subject_id' => UuidCodec::newV7(), 'subject_type' => 'variant', 'scope' => 'variant', 'facet' => 'identity', 'provenance_class' => 'EXPLICIT_USER_KNOWLEDGE']]), range(1, $count));
    }
}

final class QualityAuditMcpClaims implements KnowledgeRepository, KnowledgePageReader
{
    public int $writes = 0;

    /** @param list<KnowledgeClaim> $items */
    public function __construct(private array $items) {}
    public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $stableKey): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->stableKey === $stableKey) return $item; return null; }
    public function create(KnowledgeClaim $claim): KnowledgeClaim { $this->writes++; throw new \LogicException('quality audit must not write'); }
    public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { $this->writes++; throw new \LogicException('quality audit must not write'); }
    public function list(bool $includeRetired = false): array { return $this->items; }
    public function page(bool $includeRetired, ?string $afterStableKey, int $limit): array
    {
        $items = array_values(array_filter($this->items, static fn (KnowledgeClaim $item): bool => $afterStableKey === null || $item->stableKey > $afterStableKey));
        usort($items, static fn (KnowledgeClaim $a, KnowledgeClaim $b): int => strcmp($a->stableKey, $b->stableKey));
        return ['items' => array_slice($items, 0, $limit), 'has_more' => count($items) > $limit];
    }
}

final class QualityAuditMcpEvidence implements EvidenceRepository
{
    /** @param list<Evidence> $items */
    public function __construct(private array $items) {}
    public function findByCanonicalId(string $id): ?Evidence { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function create(Evidence $evidence): Evidence { throw new \LogicException('quality audit must not write'); }
    public function update(Evidence $evidence, int $expectedRevision): Evidence { throw new \LogicException('quality audit must not write'); }
    public function listByClaim(string $claimId, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (Evidence $item): bool => $item->claimId === $claimId)); }
    public function listBySource(string $sourceId, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (Evidence $item): bool => $item->sourceId === $sourceId)); }
}

final class QualityAuditMcpSources implements SourceRepository
{
    /** @param list<Source> $items */
    public function __construct(private array $items) {}
    public function findByCanonicalId(string $id): ?Source { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $stableKey): ?Source { foreach ($this->items as $item) if ($item->stableKey === $stableKey) return $item; return null; }
    public function create(Source $source): Source { throw new \LogicException('quality audit must not write'); }
    public function update(Source $source, int $expectedRevision): Source { throw new \LogicException('quality audit must not write'); }
    public function list(bool $includeRetired = false): array { return $this->items; }
}
