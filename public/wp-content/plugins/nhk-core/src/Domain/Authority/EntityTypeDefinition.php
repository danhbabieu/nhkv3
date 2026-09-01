<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Authority;
final readonly class EntityTypeDefinition
{
    /**
     * @param list<string> $allowedFields
     * @param list<string> $requiredFields
     * @param array<string, string> $fieldTypes
     * @param array<string, string> $fieldFormats
     */
    public function __construct(
        public string $type,
        public int $schemaVersion,
        public bool $graphEnabled,
        public array $allowedFields = [],
        public array $requiredFields = [],
        public array $fieldTypes = [],
        public array $fieldFormats = [],
    ) {}
}
