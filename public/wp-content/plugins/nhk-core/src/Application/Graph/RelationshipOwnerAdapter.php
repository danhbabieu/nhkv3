<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

interface RelationshipOwnerAdapter
{
    public function kind(): string;
    public function registry(): array;
    public function list(array $filters, int $limit = 50, ?string $after = null): array;
    public function get(string $id, array $context = []): array;
    public function preview(array $input): array;
}
