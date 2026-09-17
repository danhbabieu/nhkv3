<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Exact binding rules for a verified staging Capture acceptance scope. */
final class StagingAcceptanceScope
{
    /** @var array<string,string> */
    private const OPERATION_FAMILIES = [
        'media:representative_bind' => 'media_usage_reconciliation',
        'media:ingest' => 'media_usage_reconciliation',
        'relation:relation_create' => 'governed_relation_reconciliation',
        'relation:relation_retire' => 'governed_relation_reconciliation',
        'relation:relation_reactivate' => 'governed_relation_reconciliation',
        'knowledge:create' => 'knowledge_delta',
        'knowledge:ingest' => 'knowledge_delta',
        'knowledge:update' => 'knowledge_delta',
        'source:create' => 'source_evidence_reconciliation',
        'source:ingest' => 'source_evidence_reconciliation',
        'evidence:create' => 'source_evidence_reconciliation',
        'evidence:ingest' => 'source_evidence_reconciliation',
    ];

    /** @param array<string,mixed> $scope */
    public static function assertProposal(Proposal $proposal, array $scope): void
    {
        if (($scope['approved'] ?? false) !== true) throw new \RuntimeException('STAGING_SCOPE_NOT_APPROVED');
        $captureId = trim((string) ($scope['capture_id'] ?? ''));
        if (!UuidCodec::isValid($captureId)) throw new \RuntimeException('STAGING_CAPTURE_SCOPE_INVALID');
        $audit = is_array($proposal->payload['project_build_audit'] ?? null) ? $proposal->payload['project_build_audit'] : [];
        $proposalCaptureId = trim((string) ($audit['capture_id'] ?? $proposal->payload['capture_id'] ?? ''));
        if ($proposalCaptureId === '' || !hash_equals(strtolower($captureId), strtolower($proposalCaptureId))) throw new \RuntimeException('STAGING_CAPTURE_SCOPE_MISMATCH');

        $operationKey = $proposal->entityType . ':' . $proposal->operation;
        $expectedFamily = self::OPERATION_FAMILIES[$operationKey] ?? null;
        if ($expectedFamily === null || (string) ($scope['operation_family'] ?? '') !== $expectedFamily) throw new \RuntimeException('STAGING_OPERATION_SCOPE_MISMATCH');
        if ((string) ($scope['entity_type'] ?? '') !== $proposal->entityType || (string) ($scope['operation'] ?? '') !== $proposal->operation) throw new \RuntimeException('STAGING_OPERATION_SCOPE_MISMATCH');
        if ((string) ($scope['writer'] ?? '') !== 'canonical_governed') throw new \RuntimeException('STAGING_DIRECT_WRITER_BLOCKED');

        $mediaIds = $scope['media_ids'] ?? [];
        if (!is_array($mediaIds) || $mediaIds === [] || !array_is_list($mediaIds)) throw new \RuntimeException('STAGING_MEDIA_SCOPE_INVALID');
        $mediaIds = array_values(array_map('strval', $mediaIds));
        foreach ($mediaIds as $mediaId) if (!UuidCodec::isValid($mediaId)) throw new \RuntimeException('STAGING_MEDIA_SCOPE_INVALID');
        if (count(array_unique($mediaIds)) !== count($mediaIds) || !in_array($proposal->subjectId, $mediaIds, true)) throw new \RuntimeException('STAGING_MEDIA_SCOPE_MISMATCH');

        $target = $scope['target'] ?? null;
        if (!is_array($target) || !UuidCodec::isValid((string) ($target['id'] ?? '')) || (string) ($target['type'] ?? '') === '') throw new \RuntimeException('STAGING_TARGET_SCOPE_INVALID');
        if ((string) $target['id'] !== (string) ($proposal->targetUuid ?? '') || (string) $target['type'] !== (string) ($proposal->payload['binding']['target']['type'] ?? $target['type'])) throw new \RuntimeException('STAGING_TARGET_SCOPE_MISMATCH');
        if (isset($proposal->payload['binding']['target']['id']) && (string) $proposal->payload['binding']['target']['id'] !== (string) $target['id']) throw new \RuntimeException('STAGING_TARGET_SCOPE_MISMATCH');
        if (isset($target['stable_key']) && trim((string) $target['stable_key']) === '') throw new \RuntimeException('STAGING_TARGET_SCOPE_INVALID');

        self::assertNoFuzzyLocator($scope);
    }

    /** @param array<string,mixed> $scope */
    private static function assertNoFuzzyLocator(array $scope): void
    {
        $forbidden = ['name', 'filename', 'url', 'match', 'similarity', 'fuzzy', 'locator'];
        $target = is_array($scope['target'] ?? null) ? $scope['target'] : [];
        if (array_intersect($forbidden, array_keys($target)) !== []) throw new \RuntimeException('STAGING_EXACT_TARGET_REQUIRED');
        foreach (['media', 'media_ref'] as $key) {
            if (is_array($scope[$key] ?? null) && array_intersect($forbidden, array_keys($scope[$key])) !== []) throw new \RuntimeException('STAGING_EXACT_MEDIA_REQUIRED');
        }
    }
}
