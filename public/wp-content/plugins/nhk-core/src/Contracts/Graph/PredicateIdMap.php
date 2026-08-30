<?php
declare(strict_types=1);
namespace NHK\Core\Contracts\Graph;
interface PredicateIdMap { public function idFor(string $predicate): int; }
