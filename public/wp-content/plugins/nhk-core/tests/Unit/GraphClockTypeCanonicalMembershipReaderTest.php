<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\EntityProfileResolver;
use NHK\Core\Application\Graph\{ClassifiedAsPolicy, GraphService};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\{GraphClockTypeCanonicalMembershipReader, InMemoryAuditSink};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class GraphClockTypeCanonicalMembershipReaderTest extends TestCase
{
    public function test_reads_only_registered_source_types_and_validates_clock_type_family(): void
    {
        $source = $this->entity('variant', 'Variant X');
        $clock = $this->entity('classification', 'Đồng hồ vai bò', ['family' => 'clock_type']);
        $caseForm = $this->entity('classification', 'Dáng vai bò', ['family' => 'case_form']);
        [$graph, $repo] = $this->graph([$source, $clock, $caseForm]);
        $graph->create(new NodeReference('variant', $source->canonicalId), 'classified_as', new NodeReference('classification', $clock->canonicalId));
        $graph->create(new NodeReference('variant', $source->canonicalId), 'classified_as', new NodeReference('classification', $caseForm->canonicalId));

        $result = (new GraphClockTypeCanonicalMembershipReader($graph, $repo))->readClockTypeMemberships('variant', $source->canonicalId);

        self::assertSame('AVAILABLE', $result->status);
        self::assertSame([$clock->canonicalId], array_column($result->members, 'canonicalId'));
        self::assertContains('CLASSIFICATION_FAMILY_NOT_CLOCK_TYPE', $result->diagnostics);
        self::assertSame([], (new GraphClockTypeCanonicalMembershipReader($graph, $repo))->listClockTypesForSubject('brand', $source->canonicalId));
    }

    public function test_preserves_legacy_family_as_compatibility_read_without_mutation(): void
    {
        $source = $this->entity('specimen', 'Specimen X');
        $legacy = $this->entity('classification', 'Đồng hồ vai bò', ['family' => 'clock-type']);
        [$graph, $repo] = $this->graph([$source, $legacy]);
        $graph->create(new NodeReference('specimen', $source->canonicalId), 'classified_as', new NodeReference('classification', $legacy->canonicalId));

        $result = (new GraphClockTypeCanonicalMembershipReader($graph, $repo))->readClockTypeMemberships('specimen', $source->canonicalId);

        self::assertSame('AVAILABLE', $result->status);
        self::assertSame([$legacy->canonicalId], array_column($result->members, 'canonicalId'));
        self::assertContains('DATA_COMPATIBILITY_GAP', $result->diagnostics);
        self::assertSame('clock-type', $legacy->payload['family']);
    }

    public function test_multiple_memberships_are_retained_and_no_writer_is_called(): void
    {
        $source = $this->entity('product', 'Product X');
        $first = $this->entity('classification', 'Đồng hồ vai bò', ['family' => 'clock_type']);
        $second = $this->entity('classification', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        [$graph, $repo] = $this->graph([$source, $first, $second]);
        $graph->create(new NodeReference('product', $source->canonicalId), 'classified_as', new NodeReference('classification', $first->canonicalId));
        $graph->create(new NodeReference('product', $source->canonicalId), 'classified_as', new NodeReference('classification', $second->canonicalId));

        $result = (new GraphClockTypeCanonicalMembershipReader($graph, $repo))->readClockTypeMemberships('product', $source->canonicalId);

        self::assertCount(2, $result->members);
        self::assertSame([], $result->diagnostics);
    }

    public function test_inactive_target_is_not_accepted_as_clock_type_membership(): void
    {
        $source = $this->entity('model', 'Model X');
        $inactive = $this->entity('classification', 'Đồng hồ đã nghỉ', ['family' => 'clock_type'], AuthorityState::RETIRED);
        [$graph, $repo] = $this->graph([$source, $inactive]);
        $graph->create(new NodeReference('model', $source->canonicalId), 'classified_as', new NodeReference('classification', $inactive->canonicalId));

        $result = (new GraphClockTypeCanonicalMembershipReader($graph, $repo))->readClockTypeMemberships('model', $source->canonicalId);

        self::assertSame([], $result->members);
        self::assertContains('INACTIVE_CLASSIFIED_AS_TARGET', $result->diagnostics);
    }

    /** @return array{0:GraphService,1:ReaderAuthorityRepository} */
    private function graph(array $entities): array
    {
        $repo = new ReaderAuthorityRepository($entities);
        $endpoints = new EndpointTypeRegistry();
        $byType = [];
        foreach ($entities as $entity) $byType[$entity->entityType][] = $entity->canonicalId;
        foreach ($byType as $type => $ids) $endpoints->register($type, new FakeEndpointResolver($type, $ids));
        return [new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy()), $repo];
    }

    private function entity(string $type, string $name, array $payload = [], AuthorityState $state = AuthorityState::ACTIVE): AuthorityEntity
    {
        return new AuthorityEntity(UuidCodec::newV7(), $type, $type . ':' . strtolower((string) random_int(1000, 9999)), $name, 1, $payload, $state);
    }
}

final class ReaderAuthorityRepository implements AuthorityRepository
{
    /** @param list<AuthorityEntity> $entities */
    public function __construct(private array $entities) {}
    public function findByCanonicalId(string $id): ?AuthorityEntity { foreach ($this->entities as $entity) if ($entity->canonicalId === $id) return $entity; return null; }
    public function findByStableKey(string $type, string $key): ?AuthorityEntity { foreach ($this->entities as $entity) if ($entity->entityType === $type && $entity->stableKey === $key) return $entity; return null; }
    public function create(AuthorityEntity $entity): AuthorityEntity { throw new \LogicException('reader must not write Authority'); }
    public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { throw new \LogicException('reader must not write Authority'); }
    public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { throw new \LogicException('reader must not write Authority'); }
    public function listByType(string $type, bool $includeRetired = false): array { return array_values(array_filter($this->entities, static fn (AuthorityEntity $entity): bool => $entity->entityType === $type && ($includeRetired || $entity->active()))); }
}
