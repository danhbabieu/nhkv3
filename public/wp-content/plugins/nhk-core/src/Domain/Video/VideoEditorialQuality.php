<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Video;

final readonly class VideoEditorialQuality
{
    public const COMPLETE = 'CONTENT_COMPLETE';
    public const NEEDS_REVIEW = 'CONTENT_NEEDS_REVIEW';

    /** @param list<string> $blockers */
    public function __construct(public string $status, public array $blockers = [])
    {
        if (!in_array($status, [self::COMPLETE, self::NEEDS_REVIEW], true)) throw new \InvalidArgumentException('Invalid Video editorial quality status.');
    }

    public function complete(): bool { return $this->status === self::COMPLETE && $this->blockers === []; }

    /** @return array{status:string,blockers:list<string>} */
    public function toArray(): array { return ['status' => $this->status, 'blockers' => $this->blockers]; }
}
