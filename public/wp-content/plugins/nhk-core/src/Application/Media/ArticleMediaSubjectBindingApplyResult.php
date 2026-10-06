<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

/** Canonical apply result for governed Article Media subject binding. */
final readonly class ArticleMediaSubjectBindingApplyResult
{
    public function __construct(
        public string $canonicalId,
        public array $subjectBinding,
        public array $readback,
        public array $mutation,
    ) {}
}
