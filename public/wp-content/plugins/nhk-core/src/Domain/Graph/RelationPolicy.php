<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;

use NHK\Core\Graph\Exception\UnapprovedRelationPair;

final class RelationPolicy
{
    public static function assertCanCreate(string $predicate, string $sourceType, string $targetType): void
    {
        if ($predicate === 'about' && (($sourceType === 'product' && $targetType === 'specimen') || ($sourceType === 'specimen' && $targetType === 'product'))) {
            throw new UnapprovedRelationPair('Product–Specimen relation is not approved.');
        }
    }
}
