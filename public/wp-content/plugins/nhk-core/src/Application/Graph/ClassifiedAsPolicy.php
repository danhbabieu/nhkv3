<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

/** Scope/provenance guard for the registered classified_as relation. */
final class ClassifiedAsPolicy
{
    /** @param array<string,mixed> $packet */
    public function assertCandidate(array $packet): void
    {
        $source = trim((string) ($packet['source_type'] ?? ''));
        $scope = trim((string) ($packet['scope'] ?? ''));
        $provenance = trim((string) ($packet['provenance'] ?? ''));
        if ($source === 'brand') throw new \RuntimeException('CLASSIFICATION_SCOPE_UNSUPPORTED');
        if ($source === 'movement') throw new \RuntimeException('CONTRACT_EXTENSION_REQUIRED');
        if (!in_array($source, ['model', 'variant', 'specimen', 'product'], true) || $scope !== $source) throw new \RuntimeException('CLASSIFICATION_SCOPE_UNSUPPORTED');
        if (!in_array($provenance, ['EXPLICIT_USER_KNOWLEDGE', 'OBSERVED_FROM_MEDIA', 'CATALOG_SUPPORTED', 'EXTERNAL_RESEARCH'], true)) throw new \RuntimeException('CLASSIFICATION_SCOPE_UNSUPPORTED');
        if ($provenance === 'OBSERVED_FROM_MEDIA' && $source !== 'specimen') throw new \RuntimeException('CLASSIFICATION_SCOPE_UNSUPPORTED');
    }
}
