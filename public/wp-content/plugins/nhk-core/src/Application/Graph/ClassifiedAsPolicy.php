<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

/** Scope/provenance guard for the registered classified_as relation. */
final class ClassifiedAsPolicy
{
    /** @var list<string> */
    public const SOURCES = ['model', 'variant', 'specimen', 'product'];
    /** @var list<string> */
    public const PROVENANCE = ['EXPLICIT_USER_KNOWLEDGE', 'OBSERVED_FROM_MEDIA', 'CATALOG_SUPPORTED', 'EXTERNAL_RESEARCH'];

    /** @param array<string,mixed> $packet */
    public function assertCandidate(array $packet): void
    {
        $source = trim((string) ($packet['source_type'] ?? ''));
        $scope = trim((string) ($packet['scope'] ?? ''));
        $provenance = trim((string) ($packet['provenance'] ?? ''));
        $targetType = trim((string) ($packet['target_type'] ?? ''));
        $targetFamily = trim((string) ($packet['target_family'] ?? ''));
        if ($targetType !== '' && $targetType !== 'classification') throw new \RuntimeException('CLASSIFICATION_SCOPE_UNSUPPORTED');
        if ($targetFamily !== '' && $targetFamily !== 'clock_type') throw new \RuntimeException('CLASSIFICATION_SCOPE_UNSUPPORTED');
        if (!in_array($source, self::SOURCES, true) || $scope !== $source) throw new \RuntimeException('CLASSIFICATION_SCOPE_UNSUPPORTED');
        if (!in_array($provenance, self::PROVENANCE, true)) throw new \RuntimeException('CLASSIFICATION_SCOPE_UNSUPPORTED');
        if ($provenance === 'OBSERVED_FROM_MEDIA' && $source !== 'specimen') throw new \RuntimeException('CLASSIFICATION_SCOPE_UNSUPPORTED');
    }
}
