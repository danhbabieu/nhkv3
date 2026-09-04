<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Seo;

final readonly class SeoReadinessResult
{
    public const READY = 'READY';
    public const INCOMPLETE = 'INCOMPLETE';
    public const BLOCKED = 'BLOCKED';
    public const UNAVAILABLE = 'UNAVAILABLE';
    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    /** @param list<string> $reasons @param list<string> $structuredDataReasons */
    public function __construct(private string $status, private array $reasons = [], private array $structuredDataReasons = []) {}

    public function status(): string { return $this->status; }
    public function reasons(): array { return $this->reasons; }
    public function structuredDataNotApplicable(): bool { return $this->structuredDataReasons !== []; }
    public function structuredDataReasons(): array { return $this->structuredDataReasons; }
}
