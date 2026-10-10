<?php
declare(strict_types=1);

namespace NHK\Core\Domain\FacebookAudit;

use InvalidArgumentException;

final readonly class ReadPage
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(
        public array $rows,
        public ?string $nextCursor = null,
        public string $status = 'OK',
        public bool $retryable = false,
        public ?string $reason = null,
    ) {
        if (!in_array($this->status, ['OK', 'UNAVAILABLE', 'INACCESSIBLE'], true)) throw new InvalidArgumentException('INVALID_READ_PAGE_STATUS');
        foreach ($this->rows as $row) if (!is_array($row)) throw new InvalidArgumentException('INVALID_READ_PAGE_ROW');
    }
}
