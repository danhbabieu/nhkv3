<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\{CaptureDictionaryCreatePrecondition, CaptureEnrichmentPlanningEnvelope};
use NHK\Core\Domain\Dictionary\DictionaryPreCreateResolution;
use PHPUnit\Framework\TestCase;

final class CaptureDictionaryCreatePreconditionTest extends TestCase
{
    public function test_valid_create_packet_is_admitted(): void
    {
        $resolution = $this->resolution(DictionaryPreCreateResolution::CREATE_NEW);
        $envelope = $this->envelope($resolution->toArray());

        (new CaptureDictionaryCreatePrecondition())->assert($envelope, $resolution, 'CREATE_ENTRY_WITH_SENSE');

        self::assertTrue(true);
    }

    public function test_missing_packet_is_rejected_before_owner_mutation(): void
    {
        $resolution = $this->resolution(DictionaryPreCreateResolution::CREATE_NEW);
        $envelope = $this->envelope(null);
        $mutated = false;

        try {
            (new CaptureDictionaryCreatePrecondition())->assert($envelope, $resolution, 'CREATE_ENTRY_WITH_SENSE');
            $mutated = true;
        } catch (\RuntimeException $e) {
            self::assertSame('CAPTURE_DICTIONARY_PRE_CREATE_PACKET_REQUIRED', $e->getMessage());
        }

        self::assertFalse($mutated);
    }

    public function test_ambiguous_resolution_cannot_be_forced_into_create(): void
    {
        $resolution = $this->resolution(DictionaryPreCreateResolution::REVIEW_REQUIRED);
        $envelope = $this->envelope($resolution->toArray());

        $this->expectExceptionMessage('CAPTURE_DICTIONARY_PRE_CREATE_ACTION_MISMATCH');
        (new CaptureDictionaryCreatePrecondition())->assert($envelope, $resolution, 'CREATE_DRAFT');
    }

    public function test_changed_resolution_fingerprint_or_dependency_revision_is_rejected(): void
    {
        $packetResolution = $this->resolution(DictionaryPreCreateResolution::CREATE_NEW);
        $currentResolution = DictionaryPreCreateResolution::fromDecision(DictionaryPreCreateResolution::CREATE_NEW, 'kính rào', ['domain' => 'different'], [], [], ['reason' => 'NO_APPLICABLE_CANDIDATE']);
        $envelope = $this->envelope($packetResolution->toArray());

        $this->expectExceptionMessage('CAPTURE_DICTIONARY_PRE_CREATE_PACKET_STALE');
        (new CaptureDictionaryCreatePrecondition())->assert($envelope, $currentResolution, 'CREATE_ENTRY_WITH_SENSE');
    }

    private function resolution(string $action): DictionaryPreCreateResolution
    {
        return DictionaryPreCreateResolution::fromDecision($action, 'kính rào', ['domain' => 'clock'], [], [], ['reason' => $action]);
    }

    private function envelope(?array $packet): CaptureEnrichmentPlanningEnvelope
    {
        $requestFingerprint = hash('sha256', 'capture-request');
        $track = ['status' => 'READ_BACK_VERIFIED', 'expected_revision' => 0, 'dependency_revisions' => [], 'depends_on' => [], 'request_fingerprint' => $requestFingerprint];
        if ($packet !== null) $track['pre_create_resolution'] = $packet;
        return CaptureEnrichmentPlanningEnvelope::fromArray([
            'capture_id' => 'capture-dictionary-1',
            'request_fingerprint' => $requestFingerprint,
            'owner_tracks' => ['lexical' => $track],
            'dependency_closure' => ['lexical' => ['depends_on' => []]],
            'lexical_request_fingerprint' => $requestFingerprint,
        ]);
    }
}
