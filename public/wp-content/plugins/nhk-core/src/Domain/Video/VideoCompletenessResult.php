<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Video;

final readonly class VideoCompletenessResult
{
    public function __construct(public bool $publishable, public array $blockers = [], public array $warnings = [])
    {
    }
}
