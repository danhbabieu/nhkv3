<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Fail-closed staging scope for direct canonical MediaBindingService calls. */
final class MediaBindingStagingGuard
{
    /** @param callable():string $environment @param callable(array<string,mixed>,array<string,mixed>):bool|null $scopeVerifier @param callable(string):bool|null $can */
    public function __construct(private $environment, private $scopeVerifier = null, private $can = null) {}

    /** @param array<string,mixed> $request */
    public function __invoke(array $request): void
    {
        $environment = strtolower(trim((string) ($this->environment)()));
        if (in_array($environment, ['production', 'prod'], true)) {
            if (($request['governed_apply'] ?? false) !== true) throw new \RuntimeException('STAGING_PRODUCTION_FORBIDDEN');
            $this->assertProductionGovernedMutation($request);
            return;
        }
        if ($environment !== 'staging') return;
        $scope = $request['staging_acceptance'] ?? null;
        if (!is_array($scope)) throw new \RuntimeException('STAGING_SCOPE_REQUIRED');
        if (!is_callable($this->scopeVerifier)) throw new \RuntimeException('STAGING_SCOPE_VERIFIER_REQUIRED');
        if (!(bool) ($this->scopeVerifier)($scope, $request)) throw new \RuntimeException('STAGING_SCOPE_NOT_APPROVED');
        if (is_callable($this->can) && !(bool) ($this->can)('nhk_internal_content_operations')) throw new \RuntimeException('STAGING_CAPABILITY_REQUIRED:nhk_internal_content_operations');
        if (($scope['approved'] ?? false) !== true) throw new \RuntimeException('STAGING_SCOPE_NOT_APPROVED');
        $captureId = trim((string) ($scope['capture_id'] ?? ''));
        if (!UuidCodec::isValid($captureId) || !hash_equals(strtolower($captureId), strtolower(trim((string) ($request['capture_id'] ?? ''))))) throw new \RuntimeException('STAGING_CAPTURE_SCOPE_MISMATCH');
        $operation = strtolower(trim((string) ($scope['operation'] ?? '')));
        if (($scope['operation_family'] ?? '') !== 'media_usage_reconciliation' || ($scope['entity_type'] ?? '') !== 'media' || !in_array($operation, ['representative_bind', 'add', 'replace', 'remove'], true)) throw new \RuntimeException('STAGING_OPERATION_SCOPE_MISMATCH');
        if (!in_array((string) ($scope['writer'] ?? ''), ['canonical_media_binding', 'canonical_governed'], true)) throw new \RuntimeException('STAGING_DIRECT_WRITER_BLOCKED');

        $mediaId = trim((string) (($request['media']['id'] ?? '')));
        $target = is_array($request['target'] ?? null) ? $request['target'] : [];
        $targetId = trim((string) ($target['id'] ?? ''));
        $targetType = strtolower(trim((string) ($target['type'] ?? '')));
        $exactTarget = $targetType === 'wp_post'
            ? preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $targetId) === 1
            : UuidCodec::isValid($targetId);
        if (!UuidCodec::isValid($mediaId) || !$exactTarget || $targetType === '') throw new \RuntimeException('STAGING_EXACT_TARGET_REQUIRED');
        $mediaIds = is_array($scope['media_ids'] ?? null) ? array_map('strval', $scope['media_ids']) : [];
        $scopedTarget = is_array($scope['target'] ?? null) ? $scope['target'] : [];
        if (!in_array($mediaId, $mediaIds, true)) throw new \RuntimeException('STAGING_MEDIA_SCOPE_MISMATCH');
        if ((string) ($scopedTarget['id'] ?? '') !== $targetId || (string) ($scopedTarget['type'] ?? '') !== (string) $target['type']) throw new \RuntimeException('STAGING_TARGET_SCOPE_MISMATCH');
        foreach ([$target, is_array($request['media'] ?? null) ? $request['media'] : []] as $locator) {
            if (array_intersect(['name', 'filename', 'url', 'match', 'similarity', 'fuzzy', 'locator'], array_keys($locator)) !== []) throw new \RuntimeException('STAGING_EXACT_TARGET_REQUIRED');
        }
        if ($operation !== 'representative_bind') {
            if (($scope['writer'] ?? '') !== 'canonical_governed') throw new \RuntimeException('STAGING_DIRECT_WRITER_BLOCKED');
            if (!hash_equals((string) ($scope['payload_fingerprint'] ?? ''), $this->mediaUsagePayloadFingerprint($request, $scope))) throw new \RuntimeException('STAGING_MEDIA_USAGE_PAYLOAD_MISMATCH');
        }
    }

    /** Production uses the approved Proposal boundary, never a staging packet. */
    private function assertProductionGovernedMutation(array $request): void
    {
        $operation = strtolower(trim((string) ($request['operation'] ?? '')));
        if (!in_array($operation, ['add', 'replace', 'remove'], true)) throw new \RuntimeException('PRODUCTION_OPERATION_UNREGISTERED');
        if (!UuidCodec::isValid(trim((string) (($request['media']['id'] ?? ''))))) throw new \RuntimeException('PRODUCTION_EXACT_MEDIA_REQUIRED');
        $target = is_array($request['target'] ?? null) ? $request['target'] : [];
        $type = strtolower(trim((string) ($target['type'] ?? '')));
        $id = trim((string) ($target['id'] ?? ''));
        if ($type === 'wp_post' ? preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $id) !== 1 : !UuidCodec::isValid($id)) throw new \RuntimeException('PRODUCTION_EXACT_TARGET_REQUIRED');
        if ($operation !== 'add') {
            if (!UuidCodec::isValid(trim((string) ($request['usage_id'] ?? '')))) throw new \RuntimeException('PRODUCTION_USAGE_ID_REQUIRED');
            if ((int) ($request['expected_usage_revision'] ?? 0) < 1) throw new \RuntimeException('PRODUCTION_USAGE_REVISION_REQUIRED');
        }
        if (trim((string) ($request['proposal_id'] ?? '')) === '' || !preg_match('/^[a-f0-9]{64}$/i', (string) ($request['proposal_fingerprint'] ?? ''))) throw new \RuntimeException('PRODUCTION_PROPOSAL_BINDING_REQUIRED');
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $scope */
    private function mediaUsagePayloadFingerprint(array $request, array $scope): string
    {
        $target = is_array($request['target'] ?? null) ? $request['target'] : [];
        if (strtolower(trim((string) ($target['type'] ?? ''))) === 'wp_post') {
            $target = ['type' => 'wp_post', 'id' => ((int) ($target['blog_id'] ?? 1)) . ':' . (int) ($target['post_id'] ?? $target['id'] ?? 0)];
        }
        $media = is_array($request['media'] ?? null) ? $request['media'] : [];
        $payload = array_replace($request, [
            'operation' => strtolower(trim((string) ($scope['operation'] ?? ($request['operation'] ?? '')))),
            'media' => ['id' => trim((string) ($media['id'] ?? ''))],
            'target' => $target,
            'capture_id' => (string) ($scope['capture_id'] ?? ''),
            'capture_fingerprint' => (string) ($scope['capture_fingerprint'] ?? ''),
        ]);
        unset($payload['staging_acceptance']);
        return hash('sha256', CommandCanonicalizer::canonicalize(StagingAcceptanceScope::withoutAuthorization($payload)));
    }
}
