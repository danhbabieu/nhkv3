<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Shared\Uuid\UuidCodec;

/** Canonical admission boundary for a verified score edition owned by Music. */
final class ScoreEditionAdmission
{
    /** @return array<string,mixed> */
    public function admit(array $input): array
    {
        $editionId = trim((string) ($input['score_edition_id'] ?? ''));
        $musicId = trim((string) ($input['music_uuid'] ?? ''));
        $editionKey = trim((string) ($input['edition_key'] ?? ''));
        $checksum = strtolower(trim((string) ($input['score_checksum'] ?? '')));
        if (!UuidCodec::isValid($editionId) || !UuidCodec::isValid($musicId) || $editionKey === '') {
            throw new \InvalidArgumentException('SCORE_IDENTITY_INVALID');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) throw new \InvalidArgumentException('SCORE_CHECKSUM_INVALID');
        $checksumEvents = $input['checksum_events'] ?? null;
        if (!is_array($checksumEvents) || $checksumEvents === []) throw new \InvalidArgumentException('SCORE_CHECKSUM_EVENTS_REQUIRED');
        $encodedChecksumEvents = json_encode(array_values($checksumEvents), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!hash_equals($checksum, hash('sha256', $encodedChecksumEvents))) throw new \InvalidArgumentException('SCORE_CHECKSUM_MISMATCH');
        foreach (['rights_attribution', 'rights_license_url', 'rights_modification_notice', 'rights_share_alike_terms', 'provenance'] as $field) {
            if (($field === 'provenance' ? !is_array($input[$field] ?? null) : trim((string) ($input[$field] ?? '')) === '')) {
                throw new \InvalidArgumentException('SCORE_' . strtoupper($field) . '_REQUIRED');
            }
        }
        if (!filter_var((string) $input['rights_license_url'], FILTER_VALIDATE_URL)) throw new \InvalidArgumentException('SCORE_LICENSE_URL_INVALID');
        if (($input['verification_status'] ?? '') !== 'VERIFIED' || ($input['rights_status'] ?? '') !== 'CLEARED') {
            throw new \InvalidArgumentException('SCORE_VERIFICATION_OR_RIGHTS_REQUIRED');
        }
        $reference = (new MusicReferenceContract())->normalize(['score' => $input]);
        if (($reference['score'] ?? null) === null || ($reference['errors'] ?? []) !== []) throw new \InvalidArgumentException('SCORE_PACKET_INVALID');
        $score = $reference['score'];
        if (count($checksumEvents) !== count($score['events'])) throw new \InvalidArgumentException('SCORE_EVENT_COUNT_MISMATCH');
        foreach ($checksumEvents as $index => $rawEvent) {
            $event = $score['events'][$index] ?? [];
            if (!is_array($rawEvent) || ($rawEvent['phrase'] ?? null) !== ($event['phrase'] ?? null)
                || ($rawEvent['pitch_class'] ?? null) !== ($event['pitch_class'] ?? null)
                || (int) ($rawEvent['octave'] ?? -1) !== (int) ($event['octave'] ?? -2)
                || (int) ($rawEvent['start_ms'] ?? -1) !== (int) ($event['start_ms'] ?? -2)
                || (int) ($rawEvent['duration_ms'] ?? -1) !== (int) ($event['duration_ms'] ?? -2)) {
                throw new \InvalidArgumentException('SCORE_EVENT_CHECKSUM_CONTENT_MISMATCH');
            }
        }
        $score['score_edition_id'] = $editionId;
        $score['music_uuid'] = $musicId;
        $score['edition_key'] = $editionKey;
        $score['score_checksum'] = $checksum;
        $score['rights_status'] = 'CLEARED';
        $score['rights_attribution'] = trim((string) $input['rights_attribution']);
        $score['rights_license_url'] = trim((string) $input['rights_license_url']);
        $score['rights_modification_notice'] = trim((string) $input['rights_modification_notice']);
        $score['rights_share_alike_terms'] = trim((string) $input['rights_share_alike_terms']);
        $score['provenance'] = $input['provenance'];
        $score['canonical_status'] = 'VERIFIED_SCORE_EDITION';
        return $score;
    }
}
