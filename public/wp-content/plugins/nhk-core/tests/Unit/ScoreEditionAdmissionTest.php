<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\ScoreEditionAdmission;
use PHPUnit\Framework\TestCase;

final class ScoreEditionAdmissionTest extends TestCase
{
    public function test_admits_rights_cleared_verified_edition_and_preserves_public_safe_metadata(): void
    {
        $checksumEvents = [['id' => 'q1-01', 'phrase' => 'Q1', 'bar' => 1, 'index' => 1, 'source_token' => 'd', 'pitch_class' => 'D', 'octave' => 4, 'midi' => 62, 'duration_quarters' => 1, 'start_ms' => 0, 'duration_ms' => 600, 'rest' => false]];
        $result = (new ScoreEditionAdmission())->admit([
            'score_edition_id' => '018f5b74-5f30-7d2e-9a93-c0e7d6dc3341',
            'music_uuid' => '1ffade21-4cb8-44b5-ac1f-16de4ee533f6',
            'edition_key' => 'generic-edition-v1',
            'version' => 'generic-edition-v1',
            'source' => 'Verified notation witness',
            'verification_status' => 'VERIFIED',
            'rights_status' => 'CLEARED',
            'rights_attribution' => 'Example archive',
            'rights_license_url' => 'https://example.org/license',
            'rights_modification_notice' => 'Adapted for score-driven synthesis.',
            'rights_share_alike_terms' => 'ShareAlike terms apply where applicable.',
            'provenance' => ['source_id' => 'source-1'],
            'score_checksum' => hash('sha256', json_encode($checksumEvents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'checksum_events' => $checksumEvents,
            'tuning' => 'A4=440Hz',
            'tempo_bpm' => 100,
            'events' => [['pitch_class' => 'D', 'octave' => 4, 'start_ms' => 0, 'duration_ms' => 600, 'phrase' => 'Q1']],
            'segments' => [['key' => 'Q1', 'label' => 'Quý một', 'start_ms' => 0, 'end_ms' => 600]],
        ]);

        self::assertSame('VERIFIED_SCORE_EDITION', $result['canonical_status']);
        self::assertSame('CLEARED', $result['rights_status']);
        self::assertSame(100.0, $result['tempo_bpm']);
        self::assertSame('D', $result['events'][0]['pitch_class']);
    }

    public function test_rejects_unverified_or_uncleared_edition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ScoreEditionAdmission())->admit([
            'score_edition_id' => '018f5b74-5f30-7d2e-9a93-c0e7d6dc3341',
            'music_uuid' => '1ffade21-4cb8-44b5-ac1f-16de4ee533f6',
            'edition_key' => 'draft', 'score_checksum' => str_repeat('a', 64), 'checksum_events' => [['pitch_class' => 'D']],
            'verification_status' => 'CANDIDATE', 'rights_status' => 'UNRESOLVED',
        ]);
    }
}
