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
                'verification_status' => 'QUALIFIED',
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
