<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final readonly class ArticlePreflightResult
{
    /** @param list<string> $reasons @param array<string,mixed> $details */
    public function __construct(public bool $accepted, public array $reasons = [], public array $details = []) {}

    /** @param array<string,mixed> $details */
    public static function accepted(array $details = []): self { return new self(true, [], $details); }
    /** @param list<string> $reasons @param array<string,mixed> $details */
    public static function rejected(array $reasons, array $details = []): self { return new self(false, array_values(array_unique($reasons)), $details); }
}
