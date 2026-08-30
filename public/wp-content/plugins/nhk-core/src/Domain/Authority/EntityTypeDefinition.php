<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Authority;
final readonly class EntityTypeDefinition { public function __construct(public string $type, public int $schemaVersion, public bool $graphEnabled, public array $allowedFields=[]) {} }
