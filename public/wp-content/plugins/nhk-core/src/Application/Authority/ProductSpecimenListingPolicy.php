<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

/** Read-only commercial conflict policy; it never mutates Product or Specimen. */
final class ProductSpecimenListingPolicy
{
    /** @param list<array<string,mixed>> $existingListings @return array{status:string,reason:string} */
    public function assess(array $existingListings, string $incomingState): array
    {
        $state = strtolower(trim($incomingState));
        if (in_array($state, ['sold', 'archived', 'expired', 'retired'], true)) return ['status' => 'allowed', 'reason' => 'HISTORICAL_LISTING'];
        foreach ($existingListings as $listing) {
            if (!is_array($listing) || ($listing['active'] ?? true) !== true) continue;
            $existing = strtolower(trim((string) ($listing['offer_state'] ?? $listing['availability'] ?? '')));
            if ($existing === '' || in_array($existing, ['sold', 'archived', 'expired', 'retired'], true)) continue;
            return ['status' => 'blocked', 'reason' => 'ACTIVE_LISTING_CONFLICT'];
        }
        return ['status' => 'allowed', 'reason' => 'NO_ACTIVE_LISTING_CONFLICT'];
    }
}
