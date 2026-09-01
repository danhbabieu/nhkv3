<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;
use NHK\Core\Graph\Exception\InvalidPredicateDefinition;

final readonly class PredicateDefinition {
    public function __construct(public string $key, public array $allowed_source_types, public array $allowed_target_types, public string $outbound_cardinality = 'MANY', public string $inbound_cardinality = 'MANY', public bool $allow_self_relation = false, public bool $active = true) {
        $validTypes = static fn (array $types): bool => $types !== [] && array_is_list($types) && count(array_filter($types, static fn (mixed $type): bool => is_string($type) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $type) === 1)) === count($types);
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', $key) !== 1 || !$validTypes($allowed_source_types) || !$validTypes($allowed_target_types) || !in_array($outbound_cardinality, ['ONE','MANY'], true) || !in_array($inbound_cardinality, ['ONE','MANY'], true)) throw new InvalidPredicateDefinition('Predicate definition is invalid.');
    }
    public function allows(string $source, string $target): bool { return $this->active && in_array($source, $this->allowed_source_types, true) && in_array($target, $this->allowed_target_types, true); }
}
