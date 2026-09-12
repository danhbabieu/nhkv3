<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Audit\ClockTypeClassificationAudit;
use NHK\Core\Application\Entity\EntityProfileResolver;
use NHK\Core\Application\Graph\{ClassifiedAsPolicy, GraphService};
use NHK\Core\Contracts\Audit\ClockTypeAuditEvidenceReader;
use NHK\Core\Contracts\Authority\{AuthorityRepository, CursorAuthorityInventoryReader};
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class ClockTypeClassificationAuditTest extends TestCase
{
    public function test_exact_canonical_evidence_is_ready_only_for_owner_review_and_never_writes(): void
    {
        $source = $this->entity('variant', 'Variant X', '00000000-0000-4000-8000-000000000001');
        $target = $this->entity('classification', 'Đồng hồ vai bò', '00000000-0000-4000-8000-000000000002', ['family' => 'clock_type']);
        [$audit, $repo] = $this->audit([$source, $target], new FixtureEvidenceReader([$source->canonicalId => [$this->evidence($source, $target, 'B')]]));

        $report = $audit->audit();
        self::assertSame(ClockTypeClassificationAudit::READY_FOR_OWNER_REVIEW, $report->results[0]['status']);
        self::assertSame($target->canonicalId, $report->results[0]['target_uuid']);
        self::assertSame('clock_type', $report->results[0]['target_family']);
        self::assertSame(0, $repo->writes);
        self::assertCount(1, $report->samples[ClockTypeClassificationAudit::READY_FOR_OWNER_REVIEW]);
    }

    public function test_brandless_specimen_is_a_valid_review_candidate(): void
    {
        $source = $this->entity('specimen', 'Specimen không rõ hãng', '00000000-0000-4000-8000-000000000011');
        $target = $this->entity('classification', 'Đồng hồ công cộng', '00000000-0000-4000-8000-000000000012', ['family' => 'clock_type']);
        [$audit] = $this->audit([$source, $target], new FixtureEvidenceReader([$source->canonicalId => [$this->evidence($source, $target, 'B')]]));

        $row = $audit->audit()->results[0];
        self::assertSame(ClockTypeClassificationAudit::READY_FOR_OWNER_REVIEW, $row['status']);
        self::assertArrayNotHasKey('brand_uuid', $row);
    }

    public function test_lexical_hint_is_discovery_only(): void
    {
        $source = $this->entity('model', 'Vai bò Odo', '00000000-0000-4000-8000-000000000021');
        $target = $this->entity('classification', 'Đồng hồ vai bò', '00000000-0000-4000-8000-000000000022', ['family' => 'clock_type']);
        $reader = new FixtureEvidenceReader([$source->canonicalId => [['tier' => 'E', 'kind' => 'lexical', 'target_uuid' => $target->canonicalId]]]);
        [$audit] = $this->audit([$source, $target], $reader);

        $row = $audit->audit(['discovery_hints' => [$source->canonicalId => ['vai bò']]])->results[0];
        self::assertSame(ClockTypeClassificationAudit::DISCOVERY_HINT_ONLY, $row['status']);
        self::assertNotSame(ClockTypeClassificationAudit::READY_FOR_OWNER_REVIEW, $row['status']);
    }

    public function test_brand_and_movement_are_not_membership_sources(): void
    {
        $brand = $this->entity('brand', 'Odo', '00000000-0000-4000-8000-000000000031');
        $movement = $this->entity('movement', 'Movement X', '00000000-0000-4000-8000-000000000032');
        [$audit] = $this->audit([$brand, $movement]);

        self::assertSame([], $audit->audit()->results);
    }

    public function test_wrong_family_never_becomes_clock_type_candidate(): void
    {
        $source = $this->entity('variant', 'Variant X', '00000000-0000-4000-8000-000000000041');
        $wrong = $this->entity('classification', 'Dáng vai bò', '00000000-0000-4000-8000-000000000042', ['family' => 'case_form']);
        [$audit] = $this->audit([$source, $wrong], null, true);

        $row = $audit->audit()->results[0];
        self::assertSame(ClockTypeClassificationAudit::INVALID_CLOCK_TYPE_MEMBERSHIP_TARGET, $row['status']);
        self::assertContains('CLASSIFICATION_FAMILY_NOT_CLOCK_TYPE', $row['warnings']);
    }

    public function test_existing_active_membership_is_already_canonical_and_multiple_are_retained(): void
    {
        $source = $this->entity('product', 'Product X', '00000000-0000-4000-8000-000000000051');
        $first = $this->entity('classification', 'Đồng hồ vai bò', '00000000-0000-4000-8000-000000000052', ['family' => 'clock_type']);
        $second = $this->entity('classification', 'Đồng hồ công cộng', '00000000-0000-4000-8000-000000000053', ['family' => 'clock_type']);
        [$audit, , $graph] = $this->audit([$source, $first, $second]);
        $graph->create(new NodeReference('product', $source->canonicalId), 'classified_as', new NodeReference('classification', $first->canonicalId));
        $graph->create(new NodeReference('product', $source->canonicalId), 'classified_as', new NodeReference('classification', $second->canonicalId));

        $row = $audit->audit()->results[0];
        self::assertSame(ClockTypeClassificationAudit::ALREADY_CANONICAL, $row['status']);
        self::assertCount(2, $row['targets']);
        self::assertContains('MULTIPLE_ACTIVE_CANONICAL_MEMBERSHIPS', $row['warnings']);
    }

    public function test_legacy_membership_is_review_only_and_not_normalized(): void
    {
        $source = $this->entity('specimen', 'Specimen X', '00000000-0000-4000-8000-000000000061');
        $legacy = $this->entity('classification', 'Đồng hồ vai bò', '00000000-0000-4000-8000-000000000062', ['family' => 'clock-type']);
        [$audit, $repo, $graph] = $this->audit([$source, $legacy]);
        $graph->create(new NodeReference('specimen', $source->canonicalId), 'classified_as', new NodeReference('classification', $legacy->canonicalId));

        $row = $audit->audit()->results[0];
        self::assertSame(ClockTypeClassificationAudit::LEGACY_TARGET_REQUIRES_REVIEW, $row['status']);
        self::assertContains('LEGACY_FAMILY_NOT_WRITE_ELIGIBLE', $row['blockers']);
        self::assertSame('clock-type', $repo->findByCanonicalId($legacy->canonicalId)->payload['family']);
        self::assertSame(0, $repo->writes);
    }

    public function test_retired_relation_is_not_resurrected(): void
    {
        $source = $this->entity('model', 'Model X', '00000000-0000-4000-8000-000000000071');
        $target = $this->entity('classification', 'Đồng hồ vai bò', '00000000-0000-4000-8000-000000000072', ['family' => 'clock_type']);
        [$audit, , $graph] = $this->audit([$source, $target]);
        $edge = $graph->create(new NodeReference('model', $source->canonicalId), 'classified_as', new NodeReference('classification', $target->canonicalId));
        $graph->retire($edge->edge_uuid, $edge->revision);

        self::assertSame(ClockTypeClassificationAudit::RETIRED_RELATION_REVIEW_REQUIRED, $audit->audit()->results[0]['status']);
    }

    public function test_target_inventory_separates_canonical_legacy_other_missing_and_inactive(): void
    {
        $entities = [
            $this->entity('classification', 'Canonical', '00000000-0000-4000-8000-000000000081', ['family' => 'clock_type']),
            $this->entity('classification', 'Legacy', '00000000-0000-4000-8000-000000000082', ['family' => 'clock-type']),
            $this->entity('classification', 'Case form', '00000000-0000-4000-8000-000000000083', ['family' => 'case_form']),
            $this->entity('classification', 'Missing', '00000000-0000-4000-8000-000000000084'),
            $this->entity('classification', 'Inactive', '00000000-0000-4000-8000-000000000085', ['family' => 'clock_type'], AuthorityState::RETIRED),
        ];
        [$audit] = $this->audit($entities);
        $counts = $audit->audit()->targetInventory['counts'];
        self::assertSame(1, $counts['CANONICAL_CLOCK_TYPE']);
        self::assertSame(1, $counts['LEGACY_CLOCK_TYPE']);
        self::assertSame(1, $counts['OTHER_CLASSIFICATION_FAMILY']);
        self::assertSame(1, $counts['FAMILY_MISSING']);
        self::assertSame(1, $counts['INACTIVE']);
    }

    public function test_scope_mismatch_is_insufficient_and_determinism_is_proven(): void
    {
        $source = $this->entity('specimen', 'Specimen X', '00000000-0000-4000-8000-000000000091');
        $other = $this->entity('specimen', 'Other', '00000000-0000-4000-8000-000000000092');
        $target = $this->entity('classification', 'Đồng hồ vai bò', '00000000-0000-4000-8000-000000000093', ['family' => 'clock_type']);
        $reader = new FixtureEvidenceReader([$source->canonicalId => [$this->evidence($other, $target, 'B')]]);
        [$audit] = $this->audit([$source, $other, $target], $reader);
        $first = $audit->audit(); $second = $audit->audit();
        self::assertSame(ClockTypeClassificationAudit::INSUFFICIENT_EVIDENCE, $first->results[0]['status']);
        self::assertContains('SCOPE_MISMATCH', $first->results[0]['blockers']);
        self::assertSame($first->fingerprint, $second->fingerprint);
        self::assertSame($first->toArray(), $second->toArray());
    }

    public function test_cursor_authority_inventory_is_used_with_stable_resume_cursor(): void
    {
        $sources = [
            $this->entity('model', 'Model A', '00000000-0000-4000-8000-000000000101'),
            $this->entity('model', 'Model B', '00000000-0000-4000-8000-000000000102'),
            $this->entity('variant', 'Variant A', '00000000-0000-4000-8000-000000000103'),
        ];
        [$audit, $repo] = $this->audit($sources, new FixtureEvidenceReader([]));
        $page = $audit->audit(['limit' => 2]);
        self::assertNotNull($page->pagination['next_cursor']);
        self::assertGreaterThan(1, $repo->pages);
        $resumed = $audit->audit(['limit' => 2, 'after' => $page->pagination['next_cursor']]);
        self::assertSame('variant', $resumed->results[0]['source_type']);
        self::assertNotSame($page->results[1]['source_uuid'], $resumed->results[0]['source_uuid']);
    }

    /** @return array{0:ClockTypeClassificationAudit,1:AuditAuthorityRepository,2:GraphService} */
    private function audit(array $entities, ?ClockTypeAuditEvidenceReader $evidence = null, bool $createWrongEdge = false): array
    {
        $repo = new AuditAuthorityRepository($entities);
        $endpoints = new EndpointTypeRegistry(); $byType = [];
        foreach ($entities as $entity) $byType[$entity->entityType][] = $entity->canonicalId;
        foreach ($byType as $type => $ids) $endpoints->register($type, new FakeEndpointResolver($type, $ids));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy());
        if ($createWrongEdge) $graph->create(new NodeReference('variant', $entities[0]->canonicalId), 'classified_as', new NodeReference('classification', $entities[1]->canonicalId));
        return [new ClockTypeClassificationAudit($repo, $graph, new EntityProfileResolver(), $evidence), $repo, $graph];
    }

    private function entity(string $type, string $name, string $id, array $payload = [], AuthorityState $state = AuthorityState::ACTIVE): AuthorityEntity
    {
        return new AuthorityEntity($id, $type, $type . ':' . substr($id, -4), $name, 1, $payload, $state, 1);
    }

    private function evidence(AuthorityEntity $source, AuthorityEntity $target, string $tier): array
    {
        return ['tier' => $tier, 'source_type' => $source->entityType, 'source_uuid' => $source->canonicalId, 'scope_source_uuid' => $source->canonicalId, 'scope' => $source->entityType, 'target_uuid' => $target->canonicalId, 'evidence_status' => 'SUPPORTED', 'provenance_class' => 'EXPLICIT_USER_KNOWLEDGE', 'basis' => 'EXACT_CANONICAL_EVIDENCE', 'supporting_canonical_ids' => [$source->canonicalId, $target->canonicalId]];
    }
}

final class FixtureEvidenceReader implements ClockTypeAuditEvidenceReader
{
    /** @param array<string,list<array<string,mixed>>> $records */
    public function __construct(private array $records) {}
    public function findForSubject(string $sourceType, string $sourceUuid): array { return $this->records[$sourceUuid] ?? []; }
}

final class AuditAuthorityRepository implements AuthorityRepository, CursorAuthorityInventoryReader
{
    /** @param list<AuthorityEntity> $entities */
    public int $pages = 0;
    public function __construct(private array $entities, public int $writes = 0) {}
    public function findByCanonicalId(string $id): ?AuthorityEntity { foreach ($this->entities as $entity) if ($entity->canonicalId === $id) return $entity; return null; }
    public function findByStableKey(string $type, string $key): ?AuthorityEntity { foreach ($this->entities as $entity) if ($entity->entityType === $type && $entity->stableKey === $key) return $entity; return null; }
    public function create(AuthorityEntity $entity): AuthorityEntity { $this->writes++; throw new \LogicException('Audit must not write Authority.'); }
    public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { $this->writes++; throw new \LogicException('Audit must not write Authority.'); }
    public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { $this->writes++; throw new \LogicException('Audit must not write Authority.'); }
    public function listByType(string $type, bool $includeRetired = false): array { return array_values(array_filter($this->entities, static fn (AuthorityEntity $entity): bool => $entity->entityType === $type && ($includeRetired || $entity->active()))); }
    public function pageByType(string $type, int $limit = 100, ?string $after = null, bool $includeRetired = false): array { $this->pages++; $items = $this->listByType($type, $includeRetired); usort($items, static fn (AuthorityEntity $a, AuthorityEntity $b): int => strcmp($a->canonicalId, $b->canonicalId)); if ($after !== null) $items = array_values(array_filter($items, static fn (AuthorityEntity $entity): bool => $entity->canonicalId > $after)); $page = array_slice($items, 0, $limit); return ['items' => $page, 'next_cursor' => count($items) > $limit && $page !== [] ? $page[count($page) - 1]->canonicalId : null]; }
}
