<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

final readonly class ProductSpecimenAssessmentResult
{
    public function __construct(
        public string $reasonCode,
        public bool $semanticallyComplete,
        public ?string $specimenId = null,
    ) {}
}
