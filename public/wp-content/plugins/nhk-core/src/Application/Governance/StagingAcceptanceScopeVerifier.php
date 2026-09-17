<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal};
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * The single owner of server-issued, Capture-derived staging acceptance.
 *
 * A client may describe intent and exact references, but it cannot mint an
 * approved packet: the packet is admitted by the server, fingerprinted and
 * signed with the staging secret before any governed writer can use it.
 */
final class StagingAcceptanceScopeVerifier
{
    /** @param callable():string $environment @param callable(array<string,mixed>,CaptureRecord,array<string,mixed>,array<int,array<string,mixed>>):bool|null $admission */
    public function __construct(
        private $environment,
        private ?string $signingSecret = null,
        private $admission = null,
        private int $ttlSeconds = 900,
    ) {}

    /** @param list<array<string,mixed>> $assets @return array<string,mixed> */
    public function issueForCapture(CaptureRecord $capture, array $input, array $assets): array
    {
        $environment = $this->environmentName();
        if (in_array($environment, ['production', 'prod'], true)) throw new \RuntimeException('STAGING_PRODUCTION_FORBIDDEN');
        if ($environment !== 'staging') throw new \RuntimeException('STAGING_SCOPE_ENVIRONMENT_REQUIRED');
        if ($this->secret() === '') throw new \RuntimeException('STAGING_SCOPE_SIGNING_KEY_REQUIRED');
        if (!is_callable($this->admission)) throw new \RuntimeException('STAGING_SCOPE_ADMISSION_REQUIRED');
        if (strtoupper(trim((string) ($input['intent'] ?? ''))) !== 'MEDIA_ENRICHMENT') throw new \RuntimeException('STAGING_SCOPE_INTENT_INVALID');

        $bindings = $this->bindingEntries($input, $assets);
        if ($bindings === []) throw new \RuntimeException('STAGING_SCOPE_BINDINGS_REQUIRED');
        $mediaIds = array_values(array_unique(array_map(static fn (array $binding): string => (string) $binding['media_id'], $bindings)));
        $base = [
            'approved' => true,
            'environment' => 'staging',
            'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint,
            'operation_family' => 'media_usage_reconciliation',
            'entity_type' => 'media',
            'operation' => 'representative_bind',
            'writer' => 'canonical_media_binding',
            'entrypoint' => 'nhk.capture.ingest',
            'intent' => 'MEDIA_ENRICHMENT',
            'media_ids' => $mediaIds,
            'target' => $bindings[0]['target'],
            'bindings' => $bindings,
            'issued_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + max(1, $this->ttlSeconds)),
        ];
        if (!(bool) ($this->admission)($base, $capture, $input, $assets)) throw new \RuntimeException('STAGING_SCOPE_NOT_ADMITTED');
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize($base));
        return $base + ['fingerprint' => $fingerprint, 'signature' => hash_hmac('sha256', $fingerprint, $this->secret())];
    }

    /** @param list<array<string,mixed>> $assets @return array<string,mixed>|null */
    public function forCapture(CaptureRecord $capture, array $input, array $assets): ?array
    {
        if ($this->environmentName() !== 'staging') return null;
        $existing = is_array($capture->context['staging_acceptance'] ?? null) ? $capture->context['staging_acceptance'] : null;
        if ($existing !== null) {
            foreach ((array) ($input['media_bindings'] ?? []) as $index => $binding) {
                $request = $this->bindingRequest($capture, $binding, $assets, (int) $index, $existing);
                if (!$this->verifyBindingRequest($existing, $request)) throw new \RuntimeException('STAGING_SCOPE_REPLAY_MISMATCH');
            }
            return $existing;
        }
        return $this->issueForCapture($capture, $input, $assets);
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $request */
    public function verifyBindingRequest(array $scope, array $request): bool
    {
        if (!$this->verifyPacket($scope) || ($scope['writer'] ?? '') !== 'canonical_media_binding') return false;
        if (!hash_equals((string) $scope['capture_id'], trim((string) ($request['capture_id'] ?? '')))) return false;
        if (!in_array((string) ($request['operation'] ?? 'representative_bind'), ['representative_bind'], true)) return false;
        $mediaId = trim((string) ($request['media']['id'] ?? ''));
        $target = is_array($request['target'] ?? null) ? $request['target'] : [];
        foreach ((array) ($scope['bindings'] ?? []) as $binding) {
            if (!is_array($binding)) continue;
            if ($mediaId === (string) ($binding['media_id'] ?? '')
                && $this->sameTarget($target, (array) ($binding['target'] ?? []))
                && strtoupper((string) ($request['role'] ?? '')) === strtoupper((string) ($binding['role'] ?? ''))
                && strtoupper((string) ($request['selection_source'] ?? '')) === (string) ($binding['selection_source'] ?? '')
                && strtoupper((string) ($request['selection_policy'] ?? '')) === (string) ($binding['selection_policy'] ?? '')) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $scope */
    public function verifyProposal(array $scope, Proposal $proposal): bool
    {
        if (!$this->verifyPacket($scope) || ($scope['writer'] ?? '') !== 'canonical_governed') return false;
        try { StagingAcceptanceScope::assertProposal($proposal, $scope); return true; } catch (\Throwable) { return false; }
    }

    /** @param array<string,mixed> $scope */
    private function verifyPacket(array $scope): bool
    {
        if ($this->environmentName() !== 'staging' || $this->secret() === '' || ($scope['approved'] ?? false) !== true) return false;
        if (($scope['environment'] ?? '') !== 'staging' || !UuidCodec::isValid((string) ($scope['capture_id'] ?? '')) || !preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['capture_fingerprint'] ?? ''))) return false;
        $expires = strtotime((string) ($scope['expires_at'] ?? ''));
        if ($expires === false || $expires < time()) return false;
        $fingerprint = (string) ($scope['fingerprint'] ?? '');
        $signature = (string) ($scope['signature'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $fingerprint) || !preg_match('/^[a-f0-9]{64}$/i', $signature)) return false;
        $unsigned = $scope;
        unset($unsigned['fingerprint'], $unsigned['signature']);
        $calculated = hash('sha256', CommandCanonicalizer::canonicalize($unsigned));
        return hash_equals($fingerprint, $calculated) && hash_equals($signature, hash_hmac('sha256', $fingerprint, $this->secret()));
    }

    /** @param array<string,mixed> $binding @param list<array<string,mixed>> $assets @return array<string,mixed> */
    private function bindingRequest(CaptureRecord $capture, array $binding, array $assets, int $index, array $scope): array
    {
        $mediaId = $this->mediaId($binding, $assets);
        $target = is_array($binding['target'] ?? null) ? $binding['target'] : [];
        $scoped = is_array($scope['bindings'][$index] ?? null) ? $scope['bindings'][$index] : [];
        return ['capture_id' => $capture->captureId, 'operation' => 'representative_bind', 'media' => ['id' => $mediaId], 'target' => $target, 'role' => (string) ($binding['role'] ?? 'representative'), 'selection_source' => (string) ($binding['selection_source'] ?? 'USER_EXPLICIT'), 'selection_policy' => (string) ($binding['selection_policy'] ?? 'PINNED'), 'scope_binding' => $scoped];
    }

    /** @param list<array<string,mixed>> $assets @return list<array<string,mixed>> */
    private function bindingEntries(array $input, array $assets): array
    {
        $entries = [];
        foreach ((array) ($input['media_bindings'] ?? []) as $binding) {
            if (!is_array($binding)) throw new \RuntimeException('STAGING_SCOPE_BINDING_INVALID');
            $source = strtoupper(trim((string) ($binding['selection_source'] ?? 'USER_EXPLICIT')));
            $policy = strtoupper(trim((string) ($binding['selection_policy'] ?? ($source === 'SYSTEM_AUTO' ? 'AUTO' : 'PINNED'))));
            if ($source === 'SYSTEM_AUTO' || $policy === 'AUTO') throw new \RuntimeException('MEDIA_BINDING_GOVERNANCE_REQUIRED');
            if ($source !== 'USER_EXPLICIT' || $policy !== 'PINNED') throw new \RuntimeException('STAGING_SCOPE_SELECTION_INVALID');
            $mediaId = $this->mediaId($binding, $assets);
            $target = is_array($binding['target'] ?? null) ? $binding['target'] : [];
            if (!UuidCodec::isValid($mediaId) || !UuidCodec::isValid((string) ($target['id'] ?? '')) || trim((string) ($target['type'] ?? '')) === '') throw new \RuntimeException('STAGING_SCOPE_EXACT_REFERENCE_REQUIRED');
            $entries[] = ['media_id' => $mediaId, 'target' => ['type' => strtolower(trim((string) $target['type'])), 'id' => (string) $target['id']], 'role' => 'representative', 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED'];
        }
        return $entries;
    }

    /** @param array<string,mixed> $binding @param list<array<string,mixed>> $assets */
    private function mediaId(array $binding, array $assets): string
    {
        $reference = is_array($binding['media_ref'] ?? null) ? $binding['media_ref'] : [];
        if (trim((string) ($reference['media_id'] ?? '')) !== '') return trim((string) $reference['media_id']);
        $asset = $assets[(int) ($reference['item_index'] ?? -1)] ?? null;
        return is_array($asset) ? trim((string) ($asset['media_id'] ?? '')) : '';
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameTarget(array $left, array $right): bool
    {
        return strtolower(trim((string) ($left['type'] ?? ''))) === strtolower(trim((string) ($right['type'] ?? '')))
            && trim((string) ($left['id'] ?? '')) === trim((string) ($right['id'] ?? ''))
            && array_intersect(['name', 'filename', 'url', 'match', 'similarity', 'fuzzy', 'locator'], array_keys($left)) === [];
    }

    private function environmentName(): string { return strtolower(trim((string) ($this->environment)())); }

    private function secret(): string { return trim((string) ($this->signingSecret ?? '')); }
}
