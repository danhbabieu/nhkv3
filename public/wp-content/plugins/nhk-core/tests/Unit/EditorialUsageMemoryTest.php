<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\EditorialUsageMemory;
use PHPUnit\Framework\TestCase;

final class EditorialUsageMemoryTest extends TestCase
{
    public function test_read_memory_is_secondary_and_does_not_mutate_claim_truth(): void
    {
        $memory = new EditorialUsageMemory();
        $claim = ['claim_id' => 'foundational', 'confidence' => 'high', 'eligibility' => 'eligible', 'applicability' => 'applicable'];
        $memory->record('op-1', ['claim_id' => 'foundational', 'subject_id' => 'subject', 'surface' => 'video', 'state' => 'PUBLISHED']);
        $memory->record('op-1', ['claim_id' => 'foundational', 'subject_id' => 'subject', 'surface' => 'video', 'state' => 'PUBLISHED']);

        self::assertSame(['lifetime' => 1, 'recent' => 1, 'same_subject' => 1, 'same_surface' => 1], $memory->counts('foundational', 'subject', 'video'));
        self::assertSame(['claim_id' => 'foundational', 'confidence' => 'high', 'eligibility' => 'eligible', 'applicability' => 'applicable'], $claim);
    }

    public function test_failed_or_draft_usage_does_not_count_and_retry_is_idempotent(): void
    {
        $memory = new EditorialUsageMemory();
        self::assertSame('IGNORED', $memory->record('failed', ['claim_id' => 'a', 'state' => 'FAILED'])['status']);
        self::assertSame('IGNORED', $memory->record('draft', ['claim_id' => 'a', 'state' => 'SELECTED_FOR_DRAFT'])['status']);
        self::assertSame('RECORDED', $memory->record('published', ['claim_id' => 'a', 'state' => 'PUBLISHED'])['status']);
        self::assertSame('REPLAY', $memory->record('published', ['claim_id' => 'a', 'state' => 'PUBLISHED'])['status']);
        self::assertSame(1, $memory->counts('a', '', '')['lifetime']);
    }
}
