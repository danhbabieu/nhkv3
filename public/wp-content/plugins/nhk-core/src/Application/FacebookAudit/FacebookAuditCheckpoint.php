<?php
declare(strict_types=1);

namespace NHK\Core\Application\FacebookAudit;

use InvalidArgumentException;
use NHK\Core\Domain\FacebookAudit\FacebookAuditScope;

final readonly class FacebookAuditCheckpoint
{
    /** @param list<string> $pageRowKeys @param list<string> $groupRowKeys */
    public function __construct(
        public string $targetUrl,
        public ?string $verifiedPageId,
        public ?string $pageCursor,
        public ?string $groupCursor,
        public array $pageRowKeys,
        public array $groupRowKeys,
        public string $updatedAt,
    ) {}

    public static function initial(FacebookAuditScope $scope): self
    {
        return new self($scope->targetUrl, $scope->verifiedPageId, null, null, [], [], gmdate('c'));
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, FacebookAuditScope $scope): self
    {
        if (($data['target_url'] ?? null) !== $scope->targetUrl || ($data['verified_page_id'] ?? null) !== $scope->verifiedPageId) throw new InvalidArgumentException('CHECKPOINT_SCOPE_MISMATCH');
        return new self(
            $scope->targetUrl,
            $scope->verifiedPageId,
            self::nullableString($data['page_cursor'] ?? null),
            self::nullableString($data['group_cursor'] ?? null),
            self::stringList($data['page_row_keys'] ?? []),
            self::stringList($data['group_row_keys'] ?? []),
            (string) ($data['updated_at'] ?? gmdate('c')),
        );
    }

    public function withPage(?string $cursor, array $rowKeys): self
    {
        return new self($this->targetUrl, $this->verifiedPageId, $cursor, $this->groupCursor, array_values(array_unique(array_map('strval', $rowKeys))), $this->groupRowKeys, gmdate('c'));
    }

    public function withGroup(?string $cursor, array $rowKeys): self
    {
        return new self($this->targetUrl, $this->verifiedPageId, $this->pageCursor, $cursor, $this->pageRowKeys, array_values(array_unique(array_map('strval', $rowKeys))), gmdate('c'));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['target_url' => $this->targetUrl, 'verified_page_id' => $this->verifiedPageId, 'page_cursor' => $this->pageCursor, 'group_cursor' => $this->groupCursor, 'page_row_keys' => $this->pageRowKeys, 'group_row_keys' => $this->groupRowKeys, 'updated_at' => $this->updatedAt];
    }

    private static function nullableString(mixed $value): ?string { return $value === null || trim((string) $value) === '' ? null : trim((string) $value); }

    /** @return list<string> */
    private static function stringList(mixed $value): array { return array_values(array_map('strval', array_filter((array) $value, static fn (mixed $item): bool => is_scalar($item)))); }
}
