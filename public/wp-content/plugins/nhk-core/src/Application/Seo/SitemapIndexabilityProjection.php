<?php
declare(strict_types=1);

namespace NHK\Core\Application\Seo;

final class SitemapIndexabilityProjection
{
    /** @param array<string,mixed> $snapshot @return array{included:bool,url:?string,reasons:list<string>} */
    public function include(array $snapshot): array
    {
        $reasons = [];
        if (($snapshot['historic'] ?? false) === true) $reasons[] = 'HISTORIC_ROUTE';
        if (($snapshot['noindex'] ?? false) === true) $reasons[] = 'NOINDEX';
        if (($snapshot['technical'] ?? false) === true) $reasons[] = 'TECHNICAL_ENDPOINT';
        if (($snapshot['public_eligible'] ?? false) !== true) $reasons[] = 'PRIVATE_OR_UNAVAILABLE';
        if (strtoupper((string) ($snapshot['readiness'] ?? '')) !== 'READY') $reasons[] = strtoupper((string) ($snapshot['readiness'] ?? 'INCOMPLETE'));
        if (($snapshot['indexable'] ?? false) !== true) $reasons[] = 'NOT_INDEXABLE';
        $url = trim((string) ($snapshot['canonical_url'] ?? ''));
        if ($url === '') $reasons[] = 'MISSING_CANONICAL_URL';
        if (($snapshot['rendered_url'] ?? $url) !== $url) $reasons[] = 'CANONICAL_URL_MISMATCH';
        $reasons = array_values(array_unique($reasons));
        return ['included' => $reasons === [], 'url' => $reasons === [] ? $url : null, 'reasons' => $reasons];
    }

    public function lastmod(?string $previous, string $ownerRevision, string $projectionFingerprint): ?string
    {
        $fingerprint = $ownerRevision . '|' . $projectionFingerprint;
        if ($previous !== null && ($this->fingerprint[$previous] ?? null) === $fingerprint) return null;
        $value = gmdate(DATE_ATOM);
        $this->fingerprint[$value] = $fingerprint;
        return $value;
    }

    /** @var array<string,string> */
    private array $fingerprint = [];
}
