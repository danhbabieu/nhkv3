<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\CurrentTruthResolver;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source, KnowledgeFacetProfile};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CurrentTruthResolverTest extends TestCase
{
    public function test_resolver_preserves_qualifiers_and_contradictions(): void
    {
        $subject = UuidCodec::newV7(); $source = UuidCodec::newV7();
        $a = new KnowledgeClaim(UuidCodec::newV7(), 'a', 'Cọc đen là dạng thường gặp.', 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant']]);
        $b = new KnowledgeClaim(UuidCodec::newV7(), 'b', 'Một số ít mẫu có cọc trắng.', 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant']]);
        $e1 = new Evidence(UuidCodec::newV7(), $a->canonicalId, $source, 'qualifies', 'thường gặp', null, true, 1, ['visibility' => 'PUBLIC']);
        $e2 = new Evidence(UuidCodec::newV7(), $b->canonicalId, $source, 'contradicts', 'một số ít', null, true, 1, ['visibility' => 'PUBLIC']);
        $claims = new class([$a, $b]) implements KnowledgeRepository { public function __construct(private array $items) {} public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; } public function findByStableKey(string $key): ?KnowledgeClaim { return null; } public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; } public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { return $claim; } public function list(bool $includeRetired = false): array { return $this->items; } };
        $evidence = new class([$e1, $e2]) implements EvidenceRepository { public function __construct(private array $items) {} public function findByCanonicalId(string $id): ?Evidence { return null; } public function create(Evidence $item): Evidence { return $item; } public function update(Evidence $item, int $revision): Evidence { return $item; } public function listByClaim(string $claimId, bool $includeRetired = false): array { return array_values(array_filter($this->items, fn(Evidence $e): bool => $e->claimId === $claimId)); } public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; } };
        $sources = new class($source) implements SourceRepository { public function __construct(private string $id) {} public function findByCanonicalId(string $id): ?Source { return $id === $this->id ? new Source($id, 'source', 'Source', 'website', null, ['visibility' => 'PUBLIC']) : null; } public function findByStableKey(string $key): ?Source { return null; } public function create(Source $s): Source { return $s; } public function update(Source $s, int $revision): Source { return $s; } public function list(bool $includeRetired = false): array { return []; } };
        $packet = (new CurrentTruthResolver($claims, $evidence, $sources))->resolve($subject, new KnowledgeFacetProfile('recognition', 'variant'));
        self::assertCount(2, $packet->claims); self::assertCount(1, $packet->qualifiers); self::assertCount(1, $packet->contradictions);
    }
}
