<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

use NHK\Core\Domain\Authority\AuthorityEntity;

final class ProductSpecimenAssessment
{
    /**
     * Read-only assessment. It deliberately does not persist a relationship.
     *
     * @param list<AuthorityEntity> $specimens
     */
    public function assess(AuthorityEntity $product, bool $representsSpecificObject, array $specimens = []): ProductSpecimenAssessmentResult
    {
        if ($product->entityType !== 'product' || count($specimens) > 1) {
            return new ProductSpecimenAssessmentResult('PRODUCT_SPECIMEN_CONFLICT', false);
        }

        foreach ($specimens as $specimen) {
            if (!$specimen instanceof AuthorityEntity || $specimen->entityType !== 'specimen') {
                return new ProductSpecimenAssessmentResult('PRODUCT_SPECIMEN_CONFLICT', false);
            }
        }

        if ($specimens === []) {
            return $representsSpecificObject
                ? new ProductSpecimenAssessmentResult('PRODUCT_REQUIRES_SPECIMEN', false)
                : new ProductSpecimenAssessmentResult('PRODUCT_WITHOUT_SPECIMEN_ALLOWED', true);
        }

        return new ProductSpecimenAssessmentResult('PRODUCT_WITH_SPECIMEN', true, $specimens[0]->canonicalId);
    }
}
