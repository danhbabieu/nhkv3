<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\ContentPreparationOrchestrator;
use NHK\Core\Application\Capture\ContentPreparationResult;
use NHK\Core\Application\Semantic\SubjectResolutionService;
use PHPUnit\Framework\TestCase;

final class VideoIntelligentConstraintsAcceptanceTest extends TestCase
{
    public function test_short_input_observation_and_unsupported_detail_keep_trace_claim_local(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $resolver = new SubjectResolutionService(static fn (string $value): array => $value === $id ? [['id' => $id, 'type' => 'variant', 'name' => 'Generic Variant', 'revision' => 2]] : []);
        $result = (new ContentPreparationOrchestrator($resolver))->prepare(
            ['canonical_uuid' => $id],
            ['statements' => [
                ['id' => 'observation', 'text' => 'mặt số xanh', 'observation' => true, 'scope' => 'depicted specimen', 'attribution' => 'trong video'],
                ['id' => 'secondary', 'text' => 'chi tiết chưa rõ', 'visual_required' => true, 'visual_supported' => false, 'core' => false],
            ]],
        );

        self::assertSame('PREPARED', $result->status);
        self::assertNotEmpty($result->decisionTrace);
        self::assertSame('USER_OBSERVATION', $result->decisionTrace[0]['classification']);
        self::assertContains('REPAIRABLE', array_column($result->constraintFindings, 'severity'));
        self::assertSame('READY', $result->qualityDecision);
    }

    public function test_persisted_preparation_retry_rehydrates_trace_without_raw_body(): void
    {
        $id = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $resolver = new SubjectResolutionService(static fn (string $value): array => $value === $id ? [['id' => $id, 'type' => 'model', 'name' => 'Generic Model', 'revision' => 3]] : []);
        $original = (new ContentPreparationOrchestrator($resolver))->prepare(['canonical_uuid' => $id], ['statements' => [['id' => 'hint', 'text' => 'context', 'user_hint' => true]], 'raw_input' => 'secret body']);
        $stored = $original->toArray();
        $stored['raw_input'] = 'secret body';
        $retry = ContentPreparationResult::fromArray($stored);

        self::assertNotNull($retry);
        self::assertSame($original->preparationFingerprint, $retry?->preparationFingerprint);
        self::assertSame($original->decisionTrace, $retry?->decisionTrace);
        self::assertArrayNotHasKey('raw_input', $retry?->toArray());
    }
}
