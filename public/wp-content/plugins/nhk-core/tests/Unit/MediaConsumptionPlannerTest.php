<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{MediaConsumptionPlanner, MediaConsumptionQualityGate, MediaUniversalEnrichmentAdapter};
use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, EnrichmentPack, UniversalInputEnvelope};
use PHPUnit\Framework\TestCase;

final class MediaConsumptionPlannerTest extends TestCase
{
    public function test_media_surfaces_consume_only_supported_selected_facts(): void
    {
        $plan = (new MediaConsumptionPlanner())->plan($this->pack([
            $this->claim('fact', 'Mặt số màu xanh.', 'READER_FACT'),
            $this->claim('context', 'Dòng máy rộng hơn có lịch sử riêng.', 'SUPPORTING_CONTEXT', ['semantic_context_only' => true, 'editorial_treatment' => 'BACKGROUND_CONTEXT']),
            $this->claim('excluded', 'Nguồn nội bộ claim_id=excluded.', 'PROVENANCE_ONLY', ['publicly_composable' => false]),
        ]), ['owner_id' => 'media-1', 'subject_name' => 'Odo 36', 'observations' => [['value' => 'mặt số màu xanh', 'origin' => 'OBSERVED_FROM_MEDIA']]]);

        $data = $plan->toArray();
        self::assertStringContainsString('Mặt số màu xanh.', $data['surfaces']['caption']);
        self::assertStringNotContainsString('claim_id', $data['surfaces']['caption']);
        self::assertNotContains('excluded', $data['dependencies']['caption']);
        self::assertContains('fact', $data['dependencies']['caption']);
    }

    public function test_alt_text_never_inherits_invisible_background_knowledge(): void
    {
        $plan = (new MediaConsumptionPlanner())->plan($this->pack([
            $this->claim('technical', 'Bộ máy có 36 chân kính.', 'READER_FACT'),
            $this->claim('visible', 'Mặt số màu xanh.', 'READER_FACT', ['visual_support' => ['status' => 'supported']]),
        ]), ['owner_id' => 'media-2', 'subject_name' => 'Odo 36', 'observations' => [['value' => 'mặt số màu xanh', 'origin' => 'OBSERVED_FROM_MEDIA']]]);

        self::assertStringContainsString('mặt số màu xanh', strtolower($plan->toArray()['surfaces']['alt_text']));
        self::assertStringNotContainsString('36 chân kính', $plan->toArray()['surfaces']['alt_text']);
        self::assertNotContains('technical', $plan->toArray()['dependencies']['alt_text']);
    }

    public function test_sparse_media_is_partial_and_quality_blocks_internal_leakage(): void
    {
        $plan = (new MediaConsumptionPlanner())->plan($this->pack([]), ['owner_id' => 'media-3', 'subject_name' => 'Odo 36', 'observations' => []]);
        $quality = (new MediaConsumptionQualityGate())->evaluate($plan);
        self::assertSame('PARTIAL', $plan->toArray()['quality']['status']);
        self::assertContains('MEDIA_SURFACE_SPARSE', $quality['warnings']);

        $leaky = (new MediaConsumptionPlanner())->plan($this->pack([$this->claim('leak', 'canonical_id=abc claim_id=leak', 'READER_FACT')]), ['owner_id' => 'media-4', 'subject_name' => 'Odo 36']);
        $leakyQuality = (new MediaConsumptionQualityGate())->evaluate($leaky);
        self::assertContains('INTERNAL_METADATA_LEAK', $leakyQuality['blockers']);
    }

    public function test_adapter_returns_a_media_consumption_plan_after_shared_enrichment(): void
    {
        $adapter = MediaUniversalEnrichmentAdapter::fromEngine(new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static fn (array $subject, array $neighborhood): array => [],
        ));
        $plan = $adapter->consume(UniversalInputEnvelope::fromArray(['owner_or_source_type' => 'media_image', 'title' => 'Sparse image', 'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'model']]]), ['owner_id' => 'media-1', 'subject_name' => 'Odo 36']);
        self::assertSame('media_image', $plan->toArray()['owner_type']);
        self::assertArrayHasKey('caption', $plan->toArray()['surfaces']);
        self::assertNotContains('MEDIA_ADAPTER_NOT_YET_CONNECTED', $plan->toArray()['gaps']);
    }

    private function pack(array $claims): EnrichmentPack
    {
        return EnrichmentPack::fromBranches(['content' => ['status' => 'AVAILABLE', 'selected_claims' => $claims], 'relations' => ['status' => 'NOT_REQUESTED'], 'knowledge' => ['status' => 'NOT_REQUESTED']]);
    }

    private function claim(string $id, string $text, string $role, array $extra = []): array
    {
        return array_replace(['claim_id' => $id, 'claim_revision' => 2, 'text' => $text, 'eligibility' => 'eligible', 'applicability' => 'applicable', 'publicly_composable' => true, 'semantic_role' => $role, 'editorial_treatment' => 'DIRECT_FACT', 'evidence' => ['status' => 'eligible']], $extra);
    }
}
