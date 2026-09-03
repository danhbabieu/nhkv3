<?php
declare(strict_types=1);

namespace NHK\Core\Application\PublicIdentity;

use NHK\Core\Application\Entity\AuthorityUrlPolicy;
use NHK\Core\Domain\Authority\EntityTypeRegistry;

final class PublicRoutePolicyRegistry
{
    public function __construct(private EntityTypeRegistry $types, private AuthorityUrlPolicy $authority) {}
    public function for(string $type): ?AuthorityUrlPolicy { return $this->types->has($type) ? $this->authority : null; }
    public function has(string $type): bool { return $this->for($type) !== null; }
}
