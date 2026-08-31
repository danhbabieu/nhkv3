<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Authority;
use InvalidArgumentException;
use RuntimeException;

final class EntityTypeRegistry
{
    /** @var array<string, EntityTypeDefinition> */
    private array $definitions = [];

    public function register(EntityTypeDefinition $definition): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $definition->type)) throw new InvalidArgumentException('Entity type is invalid.');
        if ($definition->schemaVersion < 1) throw new InvalidArgumentException('Entity schema version must be positive.');
        if (count($definition->requiredFields) !== count(array_unique($definition->requiredFields))) throw new InvalidArgumentException('Required entity fields must be unique.');
        foreach ($definition->requiredFields as $field) {
            if (!in_array($field, $definition->allowedFields, true)) throw new InvalidArgumentException('Required field is not allowed: ' . $field);
        }
        foreach (array_keys($definition->fieldTypes) as $field) {
            if (!in_array($field, $definition->allowedFields, true)) throw new InvalidArgumentException('Typed field is not allowed: ' . $field);
        }
        if (isset($this->definitions[$definition->type]) && $this->definitions[$definition->type] !== $definition) {
            throw new InvalidArgumentException('Entity type is already registered with a different definition: ' . $definition->type);
        }
        $this->definitions[$definition->type] = $definition;
    }

    public function get(string $type): EntityTypeDefinition
    {
        if (!isset($this->definitions[$type])) throw new RuntimeException('Unknown entity type: ' . $type);
        return $this->definitions[$type];
    }

    public function has(string $type): bool { return isset($this->definitions[$type]); }

    /** @return list<EntityTypeDefinition> */
    public function all(): array { return array_values($this->definitions); }
}
