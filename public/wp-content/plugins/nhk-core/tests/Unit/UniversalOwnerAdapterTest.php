<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaUniversalEnrichmentAdapter;
use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, EditorialClaimRetrievalService, EditorialKnowledgeSelector, SharedEnrichmentBoundary, UniversalInputEnvelope};
use PHPUnit\Framework\TestCase;

final class UniversalOwnerAdapterTest extends TestCase
{
    public function test_media_adapter_is_honest_until_a_governed_media_lifecycle_exists(): void
    {
        $pack = (new MediaUniversalEnrichmentAdapter())->enrich(UniversalInputEnvelope::fromArray([
            'owner_or_source_type' => 'media_image',
            'title' => 'Image description',
            'observations' => [['value' => 'visible dial', 'origin' => 'MACHINE_DERIVED']],
        ]));

        self::assertSame('UNAVAILABLE', $pack->toArray()['content']['status']);
        self::assertContains('MEDIA_ADAPTER_NOT_YET_CONNECTED', $pack->toArray()['content']['diagnostics']);
        self::assertSame('NOT_REQUESTED', $pack->toArray()['knowledge']['status']);
    }

    public function test_generic_source_can_use_shared_core_without_article_or_video_owner(): void
    {
        $boundary = new SharedEnrichmentBoundary(
            new EditorialClaimRetrievalService(new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => [])),
            new EditorialKnowledgeSelector(),
        );
        $result = $boundary->enrich([
            'profile' => 'generic_source',
            'owner_or_source_type' => 'external_url',
            'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'model']],
            'raw_input' => 'A source note',
        ]);

        self::assertSame('generic_source', $result['profile']);
        self::assertArrayHasKey('content', $result);
        self::assertContains('SHARED_CONTENT_CONTEXT_SPARSE', $result['content']['diagnostics']);
    }
}
