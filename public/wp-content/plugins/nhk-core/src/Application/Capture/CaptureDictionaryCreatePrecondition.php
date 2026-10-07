<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Dictionary\DictionaryPreCreateResolution;

final class CaptureDictionaryCreatePrecondition
{
    public function assert(CaptureEnrichmentPlanningEnvelope $envelope, DictionaryPreCreateResolution $resolution, string $operation): void
    {
        $track = $envelope->ownerTracks['lexical'] ?? null;
        if (!is_array($track) || !is_array($track['pre_create_resolution'] ?? null)) throw new \RuntimeException('CAPTURE_DICTIONARY_PRE_CREATE_PACKET_REQUIRED');
        $packet = $track['pre_create_resolution'];
        $operation = strtoupper(trim($operation));
        $requiredAction = match ($operation) {
            'CREATE_ENTRY_WITH_SENSE', 'CREATE_DRAFT' => DictionaryPreCreateResolution::CREATE_NEW,
            'ADD_FORM_TO_ENTRY' => DictionaryPreCreateResolution::ADD_FORM_TO_ENTRY,
            'ADD_SENSE_TO_ENTRY' => DictionaryPreCreateResolution::ADD_SENSE_TO_ENTRY,
            'ENRICH_EXISTING' => DictionaryPreCreateResolution::ENRICH_EXISTING,
            'REUSE_EXISTING' => DictionaryPreCreateResolution::REUSE_EXISTING,
            default => throw new \RuntimeException('CAPTURE_DICTIONARY_PRE_CREATE_OPERATION_INVALID'),
        };
        if ($resolution->action !== $requiredAction || !$this->same((string) ($packet['action'] ?? ''), $resolution->action)) throw new \RuntimeException('CAPTURE_DICTIONARY_PRE_CREATE_ACTION_MISMATCH');
        if (!$this->same((string) ($packet['fingerprint'] ?? ''), $resolution->fingerprint())) throw new \RuntimeException('CAPTURE_DICTIONARY_PRE_CREATE_PACKET_STALE');
        $packetOperation = match ($operation) {
            'CREATE_ENTRY_WITH_SENSE', 'CREATE_DRAFT' => 'CREATE',
            'ADD_FORM_TO_ENTRY' => 'ADD_FORM',
            'ADD_SENSE_TO_ENTRY' => 'ADD_SENSE',
            'ENRICH_EXISTING' => 'ENRICH',
            'REUSE_EXISTING' => 'REUSE',
            default => '',
        };
        if ($packetOperation === '' || !$this->same((string) ($packet['operation'] ?? ''), $packetOperation) || !$this->same((string) ($packet['resolution_fingerprint'] ?? $packet['fingerprint'] ?? ''), $resolution->fingerprint())) throw new \RuntimeException('CAPTURE_DICTIONARY_PRE_CREATE_PACKET_STALE');
        if (!$this->same((string) ($track['request_fingerprint'] ?? ''), $envelope->requestFingerprint)) throw new \RuntimeException('CAPTURE_DICTIONARY_PRE_CREATE_PACKET_STALE');
        if (!array_key_exists('expected_revision', $track)) throw new \RuntimeException('CAPTURE_DICTIONARY_PRE_CREATE_PACKET_STALE');
        if (!$this->same((array) ($track['dependency_revisions'] ?? []), $resolution->dependencyRevisions)) throw new \RuntimeException('CAPTURE_DICTIONARY_PRE_CREATE_PACKET_STALE');
        $lexicalClosure = $envelope->dependencyClosure['lexical'] ?? null;
        if (!is_array($lexicalClosure)) foreach ($envelope->dependencyClosure as $closure) if (is_array($closure) && ($closure['owner'] ?? '') === 'lexical') { $lexicalClosure = $closure; break; }
        if (!is_array($lexicalClosure)) throw new \RuntimeException('CAPTURE_DICTIONARY_PRE_CREATE_PACKET_STALE');
    }

    private function same(mixed $left, mixed $right): bool
    {
        return json_encode($this->sort($left), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === json_encode($this->sort($right), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sort(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->sort($item);
        return $value;
    }
}
