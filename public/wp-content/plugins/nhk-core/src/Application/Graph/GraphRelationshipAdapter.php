<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

/** Adapter boundary for the existing Graph read implementation. */
final class GraphRelationshipAdapter implements RelationshipOwnerAdapter
{
    public function __construct(private \Closure $registryReader, private \Closure $listReader, private \Closure $getReader, private \Closure $previewReader) {}
    public function kind(): string { return 'graph'; }
    public function registry(): array { return ($this->registryReader)(); }
    public function list(array $filters, int $limit = 50, ?string $after = null): array { return ($this->listReader)($filters, $limit, $after); }
    public function get(string $id, array $context = []): array { return ($this->getReader)($id); }
    public function preview(array $input): array { return ($this->previewReader)($input); }
}
