<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

final class McpToolCatalog
{
    /** @return list<array{name:string,kind:string,governed:bool}> */
    public static function tools(): array
    {
        return [
            ['name' => 'nhk.search', 'kind' => 'read', 'governed' => false],
            ['name' => 'nhk.entity.get', 'kind' => 'read', 'governed' => false],
            ['name' => 'nhk.media.get', 'kind' => 'read', 'governed' => false],
            ['name' => 'nhk.video.get', 'kind' => 'read', 'governed' => false],
            ['name' => 'nhk.knowledge.get', 'kind' => 'read', 'governed' => false],
            ['name' => 'nhk.proposal.create', 'kind' => 'mutation', 'governed' => true],
            ['name' => 'nhk.proposal.submit', 'kind' => 'mutation', 'governed' => true],
            ['name' => 'nhk.proposal.approve', 'kind' => 'mutation', 'governed' => true],
            ['name' => 'nhk.proposal.reject', 'kind' => 'mutation', 'governed' => true],
            ['name' => 'nhk.proposal.eligibility', 'kind' => 'read', 'governed' => false],
            ['name' => 'nhk.proposal.apply', 'kind' => 'mutation', 'governed' => true],
        ];
    }

    public static function isGoverned(string $tool): bool
    {
        foreach (self::tools() as $definition) if ($definition['name'] === $tool) return $definition['governed'];
        return false;
    }
}
