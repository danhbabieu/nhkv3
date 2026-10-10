<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\MusicReferenceContract;
use PHPUnit\Framework\TestCase;

final class MusicReferenceContractTest extends TestCase
{
    public function test_complete_score_and_verified_audio_normalize_as_available(): void
    {
        $result = (new MusicReferenceContract())->normalize([
            'score' => [
                'version' => 'westminster-v1',
                'source' => 'Cambridge archive score, edition A',
                'verification_status' => 'VERIFIED',
                'tuning' => 'A4=440Hz',
                'tempo_bpm' => 72,
                'events' => [
                    ['pitch_class' => 'G#', 'octave' => 4, 'start_ms' => 0, 'duration_ms' => 500, 'phrase' => 'quarter-1'],
                    ['pitch_class' => 'F#', 'octave' => 4, 'start_ms' => 600, 'duration_ms' => 500, 'phrase' => 'quarter-1'],
                ],
                'segments' => [
                    ['key' => 'quarter-1', 'label' => 'Cụm một', 'start_ms' => 0, 'end_ms' => 1100],
                ],
            ],
            'audio' => [
                $this->audio('PIANO', 'Piano reference', 'VERIFIED'),
                $this->audio('BELL_SIMULATION', 'Bell simulation', 'VERIFIED'),
            ],
        ]);

        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame('westminster-v1', $result['score']['version']);
        self::assertSame('G#', $result['score']['events'][0]['pitch_class']);
        self::assertSame('quarter-1', $result['score']['segments'][0]['key']);
        self::assertSame(['PIANO', 'BELL_SIMULATION'], array_column($result['audio'], 'mode'));
        self::assertSame([], $result['errors']);
    }

    public function test_score_packet_fails_closed_when_event_timing_or_pitch_is_invalid(): void
    {
        foreach ([
            ['score' => ['events' => [['pitch_class' => 'H']]]],
            ['score' => ['events' => [['pitch_class' => 'C', 'octave' => 4, 'start_ms' => -1, 'duration_ms' => 100, 'phrase' => 'p']]]],
            ['score' => ['events' => [['pitch_class' => 'C', 'octave' => 4, 'start_ms' => 0, 'duration_ms' => 0, 'phrase' => 'p']]]],
            ['score' => ['source' => 'source', 'verification_status' => 'QUALIFIED', 'tuning' => 'A4=440Hz', 'tempo_bpm' => 72, 'events' => []]],
            ['score' => [
                'version' => 'v1', 'source' => 'source', 'verification_status' => 'QUALIFIED', 'tuning' => 'A4=440Hz', 'tempo_bpm' => 72,
                'events' => [['pitch_class' => 'C', 'octave' => 4, 'start_ms' => 0, 'duration_ms' => 100, 'phrase' => 'p']],
                'segments' => [['key' => 'p', 'label' => 'P', 'start_ms' => 100, 'end_ms' => 50]],
            ]],
        ] as $packet) {
            $result = (new MusicReferenceContract())->normalize($packet);

            self::assertSame('INVALID', $result['status']);
            self::assertNull($result['score']);
            self::assertNotSame([], $result['errors']);
        }
    }

    public function test_empty_packet_returns_empty_without_inventing_reference_data(): void
    {
        $result = (new MusicReferenceContract())->normalize([]);

        self::assertSame('EMPTY', $result['status']);
        self::assertNull($result['score']);
        self::assertSame([], $result['audio']);
        self::assertSame([], $result['errors']);
    }

    public function test_score_preserves_verified_playback_metadata_and_rests(): void
    {
        $score = $this->score();
        $score['edition'] = 'verified-edition-1';
        $score['arrangement_identity'] = 'melody-arrangement-a';
        $score['events'][1] = ['rest' => true, 'start_ms' => 500, 'duration_ms' => 100, 'phrase' => 'gap'];
        $score['segments'][0]['end_ms'] = 600;
        $score['playback'] = [
            'verification_status' => 'VERIFIED',
            'rights' => 'NHK-owned synthesis method',
            'method' => 'documented additive Web Audio synthesis',
            'instruments' => ['PIANO', 'BELL', 'GONG'],
        ];

        $result = (new MusicReferenceContract())->normalize(['score' => $score]);

        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame('verified-edition-1', $result['score']['edition']);
        self::assertSame('melody-arrangement-a', $result['score']['arrangement_identity']);
        self::assertTrue($result['score']['events'][1]['rest']);
        self::assertSame(['PIANO', 'BELL', 'GONG'], $result['score']['playback']['instruments']);
    }

    public function test_audio_requires_public_rights_and_verification(): void
    {
        $result = (new MusicReferenceContract())->normalize([
            'audio' => [
                $this->audio('PIANO', 'Private piano', 'UNVERIFIED'),
                $this->audio('BELL_SIMULATION', 'No rights bell', 'VERIFIED', ''),
            ],
        ]);

        self::assertSame('INVALID', $result['status']);
        self::assertSame([], $result['audio']);
        self::assertNotSame([], $result['errors']);
    }

    public function test_historical_recording_remains_distinct_from_bell_simulation(): void
    {
        $recording = $this->audio('HISTORICAL_RECORDING', 'Historical recording', 'VERIFIED');
        $simulation = $this->audio('BELL_SIMULATION', 'Bell simulation', 'VERIFIED');

        $result = (new MusicReferenceContract())->normalize(['audio' => [$recording, $simulation]]);

        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame(['HISTORICAL_RECORDING', 'BELL_SIMULATION'], array_column($result['audio'], 'mode'));
        self::assertNotSame($result['audio'][0]['mode'], $result['audio'][1]['mode']);
    }

    public function test_score_is_public_only_when_exactly_verified(): void
    {
        foreach (['QUALIFIED', 'DRAFT', 'UNVERIFIED', ''] as $verification) {
            $score = $this->score();
            $score['verification_status'] = $verification;

            $result = (new MusicReferenceContract())->normalize(['score' => $score]);

            self::assertSame('INVALID', $result['status'], $verification);
            self::assertNull($result['score'], $verification);
            self::assertContains('SCORE_VERIFICATION_REQUIRED', $result['errors']);
        }
    }

    public function test_score_rejects_non_finite_tempo_and_unreasonable_octave(): void
    {
        foreach ([NAN, INF, -INF] as $tempo) {
            $score = $this->score();
            $score['tempo_bpm'] = $tempo;

            $result = (new MusicReferenceContract())->normalize(['score' => $score]);

            self::assertSame('INVALID', $result['status']);
            self::assertNull($result['score']);
            self::assertContains('SCORE_TEMPO_INVALID', $result['errors']);
        }

        $score = $this->score();
        $score['events'][0]['octave'] = 12;
        $result = (new MusicReferenceContract())->normalize(['score' => $score]);

        self::assertSame('INVALID', $result['status']);
        self::assertNull($result['score']);
        self::assertContains('SCORE_EVENT_OCTAVE_INVALID', $result['errors']);
    }

    public function test_score_rejects_overlapping_out_of_order_events_and_out_of_range_segments(): void
    {
        $overlap = $this->score();
        $overlap['events'][1]['start_ms'] = 400;
        $overlapResult = (new MusicReferenceContract())->normalize(['score' => $overlap]);
        self::assertSame('INVALID', $overlapResult['status']);
        self::assertNull($overlapResult['score']);
        self::assertContains('SCORE_EVENT_OVERLAP', $overlapResult['errors']);

        $outOfOrder = $this->score();
        $outOfOrder['events'][0]['start_ms'] = 700;
        $outOfOrder['events'][1]['start_ms'] = 100;
        $outOfOrderResult = (new MusicReferenceContract())->normalize(['score' => $outOfOrder]);
        self::assertSame('INVALID', $outOfOrderResult['status']);
        self::assertNull($outOfOrderResult['score']);
        self::assertContains('SCORE_EVENT_ORDER_INVALID', $outOfOrderResult['errors']);

        $outOfRange = $this->score();
        $outOfRange['segments'][0]['end_ms'] = 5000;
        $outOfRangeResult = (new MusicReferenceContract())->normalize(['score' => $outOfRange]);
        self::assertSame('INVALID', $outOfRangeResult['status']);
        self::assertNull($outOfRangeResult['score']);
        self::assertContains('SCORE_SEGMENT_RANGE_INVALID', $outOfRangeResult['errors']);
    }

    public function test_normalization_discards_arbitrary_fields_and_raw_audio_url(): void
    {
        $audio = $this->audio('PIANO', 'Piano reference', 'VERIFIED');
        $audio['url'] = 'https://example.invalid/private-audio.mp3';
        $audio['canonical_id'] = 'private-id';
        $audio['metadata'] = ['private' => true];

        $result = (new MusicReferenceContract())->normalize(['audio' => [$audio]]);

        self::assertSame('AVAILABLE', $result['status']);
        self::assertArrayNotHasKey('url', $result['audio'][0]);
        self::assertArrayNotHasKey('canonical_id', $result['audio'][0]);
        self::assertArrayNotHasKey('metadata', $result['audio'][0]);
    }

    public function test_mixed_valid_and_invalid_components_are_partial_not_unconditionally_available(): void
    {
        $audio = $this->audio('PIANO', 'Piano reference', 'VERIFIED');
        $audio['media_asset_id'] = 'not-a-uuid';

        $result = (new MusicReferenceContract())->normalize([
            'score' => $this->score(),
            'audio' => [$audio],
        ]);

        self::assertSame('PARTIAL', $result['status']);
        self::assertNotNull($result['score']);
        self::assertSame([], $result['audio']);
        self::assertContains('AUDIO_MEDIA_ASSET_INVALID', $result['errors']);
    }

    public function test_governed_media_asset_identity_is_internal_only_and_not_a_public_audio_url(): void
    {
        $assetId = '018f5b74-5f30-7d2e-9a93-c0e7d6dc3341';
        $audio = $this->audio('PIANO', 'Piano reference', 'VERIFIED');
        $audio['media_asset_id'] = $assetId;
        $audio['url'] = 'https://example.invalid/untrusted.mp3';

        $result = (new MusicReferenceContract())->normalize(['audio' => [$audio]]);

        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame($assetId, $result['audio'][0]['media_asset_id']);
        self::assertArrayNotHasKey('url', $result['audio'][0]);
    }

    private function score(): array
    {
        return [
            'version' => 'westminster-v1',
            'source' => 'Verified score source',
            'verification_status' => 'VERIFIED',
            'tuning' => 'A4=440Hz',
            'tempo_bpm' => 72,
            'events' => [
                ['pitch_class' => 'G#', 'octave' => 4, 'start_ms' => 0, 'duration_ms' => 500, 'phrase' => 'quarter-1'],
                ['pitch_class' => 'F#', 'octave' => 4, 'start_ms' => 600, 'duration_ms' => 500, 'phrase' => 'quarter-1'],
            ],
            'segments' => [
                ['key' => 'quarter-1', 'label' => 'Cụm một', 'start_ms' => 0, 'end_ms' => 1100],
            ],
        ];
    }

    private function audio(string $mode, string $instrument, string $verification, string $rights = 'NHK reference license'): array
    {
        return [
            'mode' => $mode,
            'score_version' => 'westminster-v1',
            'instrument' => $instrument,
            'render_method' => 'documented-reference-render',
            'tuning' => 'A4=440Hz',
            'pitch_reference' => 'concert-pitch',
            'tempo_bpm' => 72,
            'duration_ms' => 2200,
            'source' => 'NHK reference source',
            'rights' => $rights,
            'verification_status' => $verification,
        ];
    }
}
