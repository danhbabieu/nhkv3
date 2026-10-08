<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

/**
 * Read-only contract for optional score/audio reference packets.
 *
 * This class validates presentation input only. It is not an Authority,
 * Media, audio-storage, or semantic mutation boundary.
 */
final class MusicReferenceContract
{
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

        return [
            'status' => $score !== null || $audio !== [] ? 'AVAILABLE' : 'INVALID',
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
        if (!is_int($input['tempo_bpm'] ?? null) && !is_float($input['tempo_bpm'] ?? null)) {
            $errors[] = 'SCORE_TEMPO_INVALID';
        } elseif ((float) $input['tempo_bpm'] <= 0) {
            $errors[] = 'SCORE_TEMPO_INVALID';
        }

        $events = $input['events'] ?? null;
        if (!is_array($events) || $events === []) {
            $errors[] = 'SCORE_EVENTS_REQUIRED';
            return null;
        }

        $normalizedEvents = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                $errors[] = 'SCORE_EVENT_INVALID';
                continue;
            }
            $pitch = $this->text($event['pitch_class'] ?? null);
            $octave = $event['octave'] ?? null;
            $start = $event['start_ms'] ?? null;
            $duration = $event['duration_ms'] ?? null;
            $phrase = $this->text($event['phrase'] ?? null);
            if (preg_match('/^[A-G](?:#|b)?$/', $pitch) !== 1) $errors[] = 'SCORE_EVENT_PITCH_INVALID';
            if (!is_int($octave)) $errors[] = 'SCORE_EVENT_OCTAVE_INVALID';
            if (!is_int($start) || $start < 0) $errors[] = 'SCORE_EVENT_START_INVALID';
            if (!is_int($duration) || $duration <= 0) $errors[] = 'SCORE_EVENT_DURATION_INVALID';
            if ($phrase === '') $errors[] = 'SCORE_EVENT_PHRASE_REQUIRED';
            if (preg_match('/^[A-G](?:#|b)?$/', $pitch) !== 1 || !is_int($octave) || !is_int($start) || $start < 0 || !is_int($duration) || $duration <= 0 || $phrase === '') continue;
            $normalizedEvents[] = ['pitch_class' => $pitch, 'octave' => $octave, 'start_ms' => $start, 'duration_ms' => $duration, 'phrase' => $phrase];
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
                    if ($key === '' || $label === '' || !is_int($start) || $start < 0 || !is_int($end) || $end <= $start) continue;
                    $segments[] = ['key' => $key, 'label' => $label, 'start_ms' => $start, 'end_ms' => $end];
                }
            }
        }

        if ($normalizedEvents === [] || $this->hasScoreErrors($errors)) return null;

        return [
            'version' => $this->text($input['version']),
            'source' => $this->text($input['source']),
            'verification_status' => $this->text($input['verification_status']),
            'tuning' => $this->text($input['tuning']),
            'tempo_bpm' => (float) $input['tempo_bpm'],
            'events' => $normalizedEvents,
            'segments' => $segments,
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
        if (!is_int($input['tempo_bpm'] ?? null) && !is_float($input['tempo_bpm'] ?? null)) $errors[] = 'AUDIO_TEMPO_INVALID';
        elseif ((float) $input['tempo_bpm'] <= 0) $errors[] = 'AUDIO_TEMPO_INVALID';
        if (!is_int($input['duration_ms'] ?? null) || $input['duration_ms'] <= 0) $errors[] = 'AUDIO_DURATION_INVALID';
        if ($this->text($input['verification_status'] ?? null) !== 'VERIFIED') $errors[] = 'AUDIO_VERIFICATION_REQUIRED';

        $valid = $mode !== '' && in_array($mode, ['PIANO', 'BELL_SIMULATION', 'HISTORICAL_RECORDING'], true)
            && $this->text($input['score_version'] ?? null) !== ''
            && $this->text($input['instrument'] ?? null) !== ''
            && $this->text($input['render_method'] ?? null) !== ''
            && $this->text($input['tuning'] ?? null) !== ''
            && $this->text($input['pitch_reference'] ?? null) !== ''
            && is_int($input['tempo_bpm'] ?? null) && $input['tempo_bpm'] > 0
            && is_int($input['duration_ms'] ?? null) && $input['duration_ms'] > 0
            && $this->text($input['source'] ?? null) !== ''
            && $this->text($input['rights'] ?? null) !== ''
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
}
