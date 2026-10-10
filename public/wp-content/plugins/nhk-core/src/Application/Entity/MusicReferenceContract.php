<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Read-only contract for optional score/audio reference packets.
 *
 * This class validates presentation input only. It is not an Authority,
 * Media, audio-storage, or semantic mutation boundary.
 */
final class MusicReferenceContract
{
    private const PUBLIC_SCORE_VERIFICATION = 'VERIFIED';

    private const MIN_SCORE_OCTAVE = 0;

    private const MAX_SCORE_OCTAVE = 9;

    /** @return array{status:string,score:?array<string,mixed>,audio:list<array<string,mixed>>,errors:list<string>} */
    public function normalize(array $packet): array
    {
        $errors = [];
        $score = null;

        if (array_key_exists('score', $packet)) {
            $score = $this->score(is_array($packet['score']) ? $packet['score'] : null, $errors);
        }

        $audio = [];
        if (array_key_exists('audio', $packet)) {
            if (!is_array($packet['audio'])) {
                $errors[] = 'AUDIO_LIST_INVALID';
            } else {
                foreach ($packet['audio'] as $item) {
                    $normalized = $this->audio(is_array($item) ? $item : null, $errors);
                    if ($normalized !== null) $audio[] = $normalized;
                }
            }
        }

        if ($score === null && $audio === [] && $errors === []) {
            return ['status' => 'EMPTY', 'score' => null, 'audio' => [], 'errors' => []];
        }

        $hasComponent = $score !== null || $audio !== [];
        return [
            'status' => !$hasComponent ? 'INVALID' : ($errors === [] ? 'AVAILABLE' : 'PARTIAL'),
            'score' => $score,
            'audio' => $audio,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @param list<string> $errors @return array<string,mixed>|null */
    private function score(?array $input, array &$errors): ?array
    {
        if ($input === null) {
            $errors[] = 'SCORE_PACKET_INVALID';
            return null;
        }

        foreach (['version', 'source', 'verification_status', 'tuning'] as $field) {
            if ($this->text($input[$field] ?? null) === '') $errors[] = 'SCORE_' . strtoupper($field) . '_REQUIRED';
        }
        if (!$this->positiveFiniteNumber($input['tempo_bpm'] ?? null)) $errors[] = 'SCORE_TEMPO_INVALID';
        if ($this->text($input['verification_status'] ?? null) !== self::PUBLIC_SCORE_VERIFICATION) $errors[] = 'SCORE_VERIFICATION_REQUIRED';

        $events = $input['events'] ?? null;
        if (!is_array($events) || $events === []) {
            $errors[] = 'SCORE_EVENTS_REQUIRED';
            return null;
        }

        $normalizedEvents = [];
        $previousStart = null;
        $previousEnd = null;
        foreach ($events as $event) {
            if (!is_array($event)) {
                $errors[] = 'SCORE_EVENT_INVALID';
                continue;
            }
            $isRest = ($event['rest'] ?? false) === true;
            $pitch = $this->text($event['pitch_class'] ?? null);
            $octave = $event['octave'] ?? null;
            $start = $event['start_ms'] ?? null;
            $duration = $event['duration_ms'] ?? null;
            $phrase = $this->text($event['phrase'] ?? null);
            if (!$isRest && preg_match('/^[A-G](?:#|b)?$/', $pitch) !== 1) $errors[] = 'SCORE_EVENT_PITCH_INVALID';
            if (!$isRest && (!is_int($octave) || $octave < self::MIN_SCORE_OCTAVE || $octave > self::MAX_SCORE_OCTAVE)) $errors[] = 'SCORE_EVENT_OCTAVE_INVALID';
            if (!is_int($start) || $start < 0) $errors[] = 'SCORE_EVENT_START_INVALID';
            if (!is_int($duration) || $duration <= 0) $errors[] = 'SCORE_EVENT_DURATION_INVALID';
            if ($phrase === '') $errors[] = 'SCORE_EVENT_PHRASE_REQUIRED';
            if ((!$isRest && (preg_match('/^[A-G](?:#|b)?$/', $pitch) !== 1 || !is_int($octave) || $octave < self::MIN_SCORE_OCTAVE || $octave > self::MAX_SCORE_OCTAVE)) || !is_int($start) || $start < 0 || !is_int($duration) || $duration <= 0 || $phrase === '') continue;
            if ($previousStart !== null && $start < $previousStart) $errors[] = 'SCORE_EVENT_ORDER_INVALID';
            if ($previousEnd !== null && $start < $previousEnd) $errors[] = 'SCORE_EVENT_OVERLAP';
            $normalizedEvents[] = ['pitch_class' => $pitch, 'octave' => $octave, 'start_ms' => $start, 'duration_ms' => $duration, 'phrase' => $phrase, 'rest' => $isRest];
            $previousStart = $start;
            $previousEnd = $start + $duration;
        }

        $segments = [];
        if (array_key_exists('segments', $input)) {
            if (!is_array($input['segments']) || $input['segments'] === []) {
                $errors[] = 'SCORE_SEGMENTS_INVALID';
            } else {
                foreach ($input['segments'] as $segment) {
                    if (!is_array($segment)) {
                        $errors[] = 'SCORE_SEGMENT_INVALID';
                        continue;
                    }
                    $key = $this->text($segment['key'] ?? null);
                    $label = $this->text($segment['label'] ?? null);
                    $start = $segment['start_ms'] ?? null;
                    $end = $segment['end_ms'] ?? null;
                    if ($key === '' || $label === '') $errors[] = 'SCORE_SEGMENT_LABEL_REQUIRED';
                    if (!is_int($start) || $start < 0 || !is_int($end) || $end <= $start) $errors[] = 'SCORE_SEGMENT_TIMING_INVALID';
                    if ($previousEnd !== null && (!is_int($start) || $start < 0 || !is_int($end) || $end > $previousEnd)) $errors[] = 'SCORE_SEGMENT_RANGE_INVALID';
                    if ($key === '' || $label === '' || !is_int($start) || $start < 0 || !is_int($end) || $end <= $start || ($previousEnd !== null && $end > $previousEnd)) continue;
                    $segments[] = ['key' => $key, 'label' => $label, 'start_ms' => $start, 'end_ms' => $end];
                }
            }
        }

        if ($normalizedEvents === [] || $this->hasScoreErrors($errors)) return null;

        $playback = null;
        if (array_key_exists('playback', $input)) {
            $candidate = is_array($input['playback']) ? $input['playback'] : [];
            $rights = $this->text($candidate['rights'] ?? null);
            $method = $this->text($candidate['method'] ?? null);
            $status = $this->text($candidate['verification_status'] ?? null);
            $instruments = is_array($candidate['instruments'] ?? null) ? array_values(array_intersect($candidate['instruments'], ['PIANO', 'BELL', 'GONG'])) : [];
            if ($rights === '' || $method === '' || $status !== self::PUBLIC_SCORE_VERIFICATION || $instruments === []) {
                $errors[] = 'SCORE_PLAYBACK_VERIFICATION_REQUIRED';
            } else {
                $playback = ['verification_status' => $status, 'rights' => $rights, 'method' => $method, 'instruments' => $instruments];
            }
        }

        return [
            'version' => $this->text($input['version']),
            'source' => $this->text($input['source']),
            'verification_status' => $this->text($input['verification_status']),
            'tuning' => $this->text($input['tuning']),
            'tempo_bpm' => (float) $input['tempo_bpm'],
            'events' => $normalizedEvents,
            'segments' => $segments,
            ...($this->text($input['edition'] ?? null) !== '' ? ['edition' => $this->text($input['edition'])] : []),
            ...($this->text($input['arrangement_identity'] ?? null) !== '' ? ['arrangement_identity' => $this->text($input['arrangement_identity'])] : []),
            ...($playback !== null ? ['playback' => $playback] : []),
        ];
    }

    /** @param list<string> $errors @return array<string,mixed>|null */
    private function audio(?array $input, array &$errors): ?array
    {
        if ($input === null) {
            $errors[] = 'AUDIO_ITEM_INVALID';
            return null;
        }
        $mode = $this->text($input['mode'] ?? null);
        if (!in_array($mode, ['PIANO', 'BELL_SIMULATION', 'HISTORICAL_RECORDING'], true)) $errors[] = 'AUDIO_MODE_INVALID';
        foreach (['score_version', 'instrument', 'render_method', 'tuning', 'pitch_reference', 'source', 'rights', 'verification_status'] as $field) {
            if ($this->text($input[$field] ?? null) === '') $errors[] = 'AUDIO_' . strtoupper($field) . '_REQUIRED';
        }
        if (!$this->positiveFiniteNumber($input['tempo_bpm'] ?? null)) $errors[] = 'AUDIO_TEMPO_INVALID';
        if (!is_int($input['duration_ms'] ?? null) || $input['duration_ms'] <= 0) $errors[] = 'AUDIO_DURATION_INVALID';
        if ($this->text($input['verification_status'] ?? null) !== 'VERIFIED') $errors[] = 'AUDIO_VERIFICATION_REQUIRED';
        $mediaAssetId = $this->text($input['media_asset_id'] ?? null);
        if ($mediaAssetId !== '' && !UuidCodec::isValid($mediaAssetId)) $errors[] = 'AUDIO_MEDIA_ASSET_INVALID';

        $valid = $mode !== '' && in_array($mode, ['PIANO', 'BELL_SIMULATION', 'HISTORICAL_RECORDING'], true)
            && $this->text($input['score_version'] ?? null) !== ''
            && $this->text($input['instrument'] ?? null) !== ''
            && $this->text($input['render_method'] ?? null) !== ''
            && $this->text($input['tuning'] ?? null) !== ''
            && $this->text($input['pitch_reference'] ?? null) !== ''
            && $this->positiveFiniteNumber($input['tempo_bpm'] ?? null)
            && is_int($input['duration_ms'] ?? null) && $input['duration_ms'] > 0
            && $this->text($input['source'] ?? null) !== ''
            && $this->text($input['rights'] ?? null) !== ''
            && ($mediaAssetId === '' || UuidCodec::isValid($mediaAssetId))
            && $this->text($input['verification_status'] ?? null) === 'VERIFIED';
        if (!$valid) return null;

        return [
            'mode' => $mode,
            'score_version' => $this->text($input['score_version']),
            'instrument' => $this->text($input['instrument']),
            'render_method' => $this->text($input['render_method']),
            'tuning' => $this->text($input['tuning']),
            'pitch_reference' => $this->text($input['pitch_reference']),
            'tempo_bpm' => (float) $input['tempo_bpm'],
            'duration_ms' => $input['duration_ms'],
            'source' => $this->text($input['source']),
            'rights' => $this->text($input['rights']),
            'verification_status' => 'VERIFIED',
            ...($mediaAssetId !== '' ? ['media_asset_id' => $mediaAssetId] : []),
        ];
    }

    /** @param list<string> $errors */
    private function hasScoreErrors(array $errors): bool
    {
        foreach ($errors as $error) if (str_starts_with($error, 'SCORE_')) return true;
        return false;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function positiveFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && (float) $value > 0;
    }
}
