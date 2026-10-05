<?php
declare(strict_types=1);
namespace NHK\Core\Contracts\Graph;
use NHK\Core\Domain\Graph\GraphRelationContext;
interface GraphRelationContextRepository
{
    public function create(GraphRelationContext $context): GraphRelationContext;
    public function findByContextUuid(string $uuid): ?GraphRelationContext;
    public function findByEdgeUuid(string $uuid): ?GraphRelationContext;
    public function findByIdempotencyKey(string $key): ?GraphRelationContext;
    public function update(GraphRelationContext $context, int $expectedRevision): GraphRelationContext;
    public function retire(GraphRelationContext $context, int $expectedRevision): GraphRelationContext;
    public function reactivate(GraphRelationContext $context, int $expectedRevision): GraphRelationContext;
}
