<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Completion\CompletionCoordinator;
use PHPUnit\Framework\TestCase;

final class UniversalOwnerLifecycleAcceptanceTest extends TestCase
{
    private CompletionCoordinator $coordinator;

    protected function setUp(): void
    {
        $this->coordinator = new CompletionCoordinator();
    }

    public function test_article_video_media_and_future_owner_share_canonical_existence_rule(): void
    {
        foreach (['article', 'video', 'media', 'future_owner'] as $type) {
            $packet = $this->coordinator->finalize($type, $type . '-owner', [
                'canonical_readback' => ['canonical_id' => $type . '-owner'],
                'enrichment_readiness' => ['status' => 'UNAVAILABLE'],
                'publication_readiness' => ['status' => 'BLOCKED'],
            ]);

            self::assertSame('COMPLETE', $packet['canonical_existence']['status']);
            self::assertTrue($packet['complete']);
        }
    }

    public function test_capture_owner_completion_survives_publication_blocker(): void
    {
        $packet = $this->coordinator->aggregateCapture('capture-1', [
            ['owner_type' => 'video', 'owner_id' => 'video-1', 'canonical_readback' => ['canonical_id' => 'video-1'], 'publication_readiness' => ['status' => 'BLOCKED', 'blockers' => ['UNSAFE_PUBLIC_ASSERTION']]],
        ], [
            'canonical_readback' => ['canonical_id' => 'capture-1'],
            'required_owners' => [['owner_type' => 'video', 'owner_id' => 'video-1']],
        ]);

        self::assertTrue($packet['children'][0]['complete']);
        self::assertSame('BLOCKED', $packet['children'][0]['publication_readiness']['status']);
        self::assertFalse($packet['complete']);
        self::assertSame('COMPLETE', $packet['canonical_state']);
        self::assertSame('COMPLETE', $packet['canonical_existence']['status']);
        self::assertSame('NOT_APPLICABLE', $packet['publication_readiness']['status']);
    }

    public function test_two_owner_ids_referencing_one_subject_are_not_deduplicated(): void
    {
        $owners = [
            $this->coordinator->finalize('future_owner', 'owner-a', ['canonical_readback' => ['canonical_id' => 'owner-a'], 'subject_id' => 'subject-1']),
            $this->coordinator->finalize('future_owner', 'owner-b', ['canonical_readback' => ['canonical_id' => 'owner-b'], 'subject_id' => 'subject-1']),
        ];

        self::assertSame(['owner-a', 'owner-b'], array_column($owners, 'owner_id'));
        self::assertSame(['owner-a', 'owner-b'], array_column(array_column($owners, 'canonical_readback'), 'canonical_id'));
    }
}
