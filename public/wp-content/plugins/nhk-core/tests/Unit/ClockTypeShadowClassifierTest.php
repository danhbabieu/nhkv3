<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\{ClockTypeShadowClassifier, ClockTypeShadowResolution};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Capture\ClockTypeCanonicalMembershipReader;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class ClockTypeShadowClassifierTest extends TestCase
{
    public function test_explicit_canonical_clock_type_is_shadow_resolved_without_a_write(): void
    {
        $clock = $this->entity('clock_type', 'Đồng hồ vai bò');
        $repository = new ShadowReadRepository([$clock]);

        $result = $this->classifier($repository)->resolve($this->context([
            'classification_uuid' => $clock->canonicalId,
        ]));

        self::assertSame(ClockTypeShadowResolution::RESOLVED_EXPLICIT, $result->status);
        self::assertSame($clock->canonicalId, $result->selectedCandidate?->classificationUuid);
        self::assertSame(['EXPLICIT_CANONICAL_ID'], $result->basis);
        self::assertSame(0, $repository->writes);
    }

    public function test_brand_plus_clock_type_is_context_not_a_combined_identity(): void
    {
        $clock = $this->entity('clock_type', 'Đồng hồ vai bò');
        $repository = new ShadowReadRepository([$clock]);

        $result = $this->classifier($repository)->resolve($this->context([
            'brand' => ['id' => UuidCodec::newV7(), 'name' => 'Odo'],
            'user_statement' => 'Odo, đây là Đồng hồ vai bò.',
        ]));

        self::assertSame(ClockTypeShadowResolution::RESOLVED_EXPLICIT, $result->status);
        self::assertSame('Đồng hồ vai bò', $result->selectedCandidate?->name);
        self::assertContains('BRAND_NOT_CLASSIFICATION_EVIDENCE', $result->diagnostics);
        self::assertSame([], $result->toArray()['writes']);
        self::assertArrayNotHasKey('combined_entity', $result->toArray());
    }

    public function test_brandless_capture_can_return_clock_type_candidate(): void
    {
        $clock = $this->entity('clock_type', 'Đồng hồ công cộng');

        $result = $this->classifier(new ShadowReadRepository([$clock]))->resolve($this->context([
            'user_statement' => 'Đồng hồ công cộng, không rõ hãng.',
        ]));

        self::assertSame(ClockTypeShadowResolution::RESOLVED_EXPLICIT, $result->status);
        self::assertSame('Đồng hồ công cộng', $result->selectedCandidate?->name);
        self::assertNotContains('UNKNOWN_BRAND', $result->diagnostics);
    }

    public function test_exact_scoped_clock_type_context_is_read_without_using_url_or_primary_stable_key(): void
    {
        $clock = $this->entity('clock_type', 'Đồng hồ vai bò');
        $result = $this->classifier(new ShadowReadRepository([$clock]))->resolve($this->context([
            'clock_type_name' => 'Đồng hồ vai bò',
            'slug' => 'vai-bo',
            'subject_resolution' => ['primary' => ['id' => UuidCodec::newV7(), 'type' => 'variant', 'stable_key' => 'nhk:variant:vai-bo']],
        ]));

        self::assertSame(ClockTypeShadowResolution::RESOLVED_EXPLICIT, $result->status);
        self::assertSame('EXACT_CANONICAL_CONTEXT', $result->candidates[0]->resolutionBasis);
        self::assertSame('variant', $result->canonicalSubject['type']);
    }

    public function test_brand_only_does_not_invent_a_clock_type(): void
    {
        $clock = $this->entity('clock_type', 'Đồng hồ vai bò');

        $result = $this->classifier(new ShadowReadRepository([$clock]))->resolve($this->context([
            'brand' => ['name' => 'Odo'],
            'raw_input' => 'Odo',
        ]));

        self::assertSame(ClockTypeShadowResolution::NONE, $result->status);
        self::assertSame([], $result->candidates);
        self::assertContains('BRAND_NOT_CLASSIFICATION_EVIDENCE', $result->diagnostics);
    }

    public function test_case_form_is_not_clock_type(): void
    {
        $caseForm = $this->entity('case_form', 'Dáng vai bò', ['family' => 'case_form']);

        $result = $this->classifier(new ShadowReadRepository([$caseForm]))->resolve($this->context([
            'user_statement' => 'Dáng vai bò',
        ]));

        self::assertSame(ClockTypeShadowResolution::NONE, $result->status);
        self::assertSame([], $result->candidates);
        self::assertContains('AMBIGUOUS_CLASSIFICATION_FAMILY', $result->diagnostics);
    }

    public function test_weak_title_match_is_review_only_and_not_selected(): void
    {
        $clock = $this->entity('clock_type', 'Đồng hồ vai bò');

        $result = $this->classifier(new ShadowReadRepository([$clock]))->resolve($this->context([
            'title' => 'Ảnh vai bò trong bộ sưu tập',
        ]));

        self::assertSame(ClockTypeShadowResolution::REVIEW_CANDIDATE, $result->status);
        self::assertNull($result->selectedCandidate);
        self::assertSame('LEXICAL_MEDIA_REVIEW', $result->candidates[0]->resolutionBasis);
        self::assertContains('WEAK_INPUT_NOT_CANONICAL_EVIDENCE', $result->diagnostics);
    }

    public function test_multiple_clock_type_matches_remain_ambiguous(): void
    {
        $first = $this->entity('clock_type', 'Đồng hồ vai bò');
        $second = $this->entity('clock_type', 'Đồng hồ công cộng');

        $result = $this->classifier(new ShadowReadRepository([$first, $second]))->resolve($this->context([
            'user_statement' => 'Đồng hồ vai bò và Đồng hồ công cộng.',
        ]));

        self::assertSame(ClockTypeShadowResolution::AMBIGUOUS, $result->status);
        self::assertNull($result->selectedCandidate);
        self::assertCount(2, $result->candidates);
        self::assertContains('MULTIPLE_CLOCK_TYPE_CANDIDATES', $result->ambiguities);
    }

    public function test_existing_canonical_membership_is_reported_without_duplicate_proposal(): void
    {
        $clock = $this->entity('clock_type', 'Đồng hồ vai bò');
        $reader = new ExistingMembershipReader([$clock]);

        $result = (new ClockTypeShadowClassifier(new ShadowReadRepository([$clock]), memberships: $reader))->resolve($this->context());

        self::assertSame(ClockTypeShadowResolution::RESOLVED_CANONICAL, $result->status);
        self::assertSame('ALREADY_CANONICAL', $result->selectedCandidate?->origin);
        self::assertSame(1, $reader->reads);
        self::assertSame([], $result->toArray()['writes']);
    }

    public function test_legacy_clock_type_is_compatibility_read_only(): void
    {
        $legacy = $this->entity('classification', 'Đồng hồ vai bò', ['family' => 'clock-type']);

        $result = $this->classifier(new ShadowReadRepository([$legacy]))->resolve($this->context([
            'classification_uuid' => $legacy->canonicalId,
        ]));

        self::assertSame(ClockTypeShadowResolution::RESOLVED_EXPLICIT, $result->status);
        self::assertSame('COMPATIBILITY_READ', $result->selectedCandidate?->profileStatus);
        self::assertSame('clock-type', $result->selectedCandidate?->family);
        self::assertContains('DATA_COMPATIBILITY_GAP', $result->selectedCandidate?->diagnostics ?? []);
        self::assertSame(['family' => 'clock-type'], $legacy->payload);
    }

    public function test_unknown_family_is_fail_safe_and_not_guessed(): void
    {
        $unknown = $this->entity('classification', 'Đồng hồ vai bò', ['family' => 'future_facet']);

        $result = $this->classifier(new ShadowReadRepository([$unknown]))->resolve($this->context([
            'classification_uuid' => $unknown->canonicalId,
        ]));

        self::assertSame(ClockTypeShadowResolution::NONE, $result->status);
        self::assertSame([], $result->candidates);
        self::assertContains('CLASSIFICATION_FAMILY_UNRESOLVED', $result->diagnostics);
    }

    public function test_video_media_and_knowledge_context_cannot_change_the_primary_subject_or_create_relations(): void
    {
        $clock = $this->entity('clock_type', 'Đồng hồ vai bò');
        $primaryId = UuidCodec::newV7();
        $result = $this->classifier(new ShadowReadRepository([$clock]))->resolve([
            'subject_resolution' => ['primary' => ['id' => $primaryId, 'type' => 'variant', 'name' => 'Odo 36/8']],
            'user_statement' => 'Đồng hồ vai bò',
            'video' => ['about' => ['type' => 'variant', 'id' => $primaryId]],
            'assets' => [['media_id' => UuidCodec::newV7(), 'depicts' => ['type' => 'specimen', 'id' => UuidCodec::newV7()]]],
            'knowledge' => ['subject_id' => $primaryId],
        ]);

        self::assertSame($primaryId, $result->canonicalSubject['id']);
        self::assertSame('variant', $result->canonicalSubject['type']);
        self::assertArrayNotHasKey('about', $result->toArray());
        self::assertArrayNotHasKey('relations', $result->toArray());
        self::assertArrayNotHasKey('knowledge_claim', $result->toArray());
    }

    public function test_same_immutable_input_is_deterministic_and_missing_primary_is_unavailable(): void
    {
        $clock = $this->entity('clock_type', 'Đồng hồ vai bò');
        $classifier = $this->classifier(new ShadowReadRepository([$clock]));
        $context = $this->context(['user_statement' => 'Đồng hồ vai bò']);

        self::assertSame($classifier->resolve($context)->toArray(), $classifier->resolve($context)->toArray());

        $missing = $classifier->resolve(['raw_input' => 'Đồng hồ vai bò']);
        self::assertSame(ClockTypeShadowResolution::UNAVAILABLE, $missing->status);
        self::assertContains('PRIMARY_SUBJECT_REQUIRED', $missing->diagnostics);
    }

    private function classifier(AuthorityRepository $repository): ClockTypeShadowClassifier
    {
        return new ClockTypeShadowClassifier($repository);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function context(array $extra = []): array
    {
        return array_replace_recursive([
            'subject_resolution' => ['primary' => ['id' => UuidCodec::newV7(), 'type' => 'specimen', 'name' => 'Specimen X']],
        ], $extra);
    }

    private function entity(string $type, string $name, array $payload = []): AuthorityEntity
    {
        $entityType = in_array($type, ['clock_type', 'case_form'], true) ? 'classification' : $type;
        if ($type === 'clock_type') $payload = ['family' => 'clock_type'] + $payload;
        if ($type === 'case_form') $payload = ['family' => 'case_form'] + $payload;
        return new AuthorityEntity(UuidCodec::newV7(), $entityType, 'nhk:' . $entityType . ':' . substr(hash('sha256', $name . $type), 0, 12), $name, 1, $payload);
    }
}

final class ShadowReadRepository implements AuthorityRepository
{
    public int $writes = 0;

    /** @param list<AuthorityEntity> $items */
    public function __construct(private array $items) {}
    public function findByCanonicalId(string $id): ?AuthorityEntity { foreach ($this->items as $entity) if ($entity->canonicalId === $id) return $entity; return null; }
    public function findByStableKey(string $type, string $key): ?AuthorityEntity { foreach ($this->items as $entity) if ($entity->entityType === $type && $entity->stableKey === $key) return $entity; return null; }
    public function listByType(string $type, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (AuthorityEntity $entity): bool => $entity->entityType === $type && ($includeRetired || $entity->active()))); }
    public function create(AuthorityEntity $entity): AuthorityEntity { $this->writes++; throw new \LogicException('SHADOW_WRITE_FORBIDDEN'); }
    public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { $this->writes++; throw new \LogicException('SHADOW_WRITE_FORBIDDEN'); }
    public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { $this->writes++; throw new \LogicException('SHADOW_WRITE_FORBIDDEN'); }
}

final class ExistingMembershipReader implements ClockTypeCanonicalMembershipReader
{
    public int $reads = 0;

    /** @param list<AuthorityEntity> $items */
    public function __construct(private array $items) {}
    public function listClockTypesForSubject(string $sourceType, string $sourceId): array { $this->reads++; return $this->items; }
}
