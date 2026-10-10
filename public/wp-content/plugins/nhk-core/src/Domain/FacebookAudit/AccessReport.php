<?php
declare(strict_types=1);

namespace NHK\Core\Domain\FacebookAudit;

use InvalidArgumentException;

final readonly class AccessReport
{
    public const CAPABILITIES = [
        'page_metadata', 'page_posts', 'engagement_metrics', 'photos_videos',
        'reels', 'insights', 'known_groups', 'delete_capability',
    ];

    public const STATUSES = ['GRANTED', 'DENIED', 'UNSUPPORTED', 'INACCESSIBLE', 'NOT_CHECKED'];

    /** @param array<string,string> $statuses */
    public function __construct(public array $statuses)
    {
        foreach ($statuses as $capability => $status) {
            if (!in_array($capability, self::CAPABILITIES, true) || !in_array($status, self::STATUSES, true)) throw new InvalidArgumentException('INVALID_ACCESS_STATUS');
        }
    }

    public function status(string $capability): string { return $this->statuses[$capability] ?? 'NOT_CHECKED'; }

    /** @return array<string,string> */
    public function complete(): array
    {
        $result = [];
        foreach (self::CAPABILITIES as $capability) $result[$capability] = $this->status($capability);
        return $result;
    }
}
