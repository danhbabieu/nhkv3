<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;
use NHK\Core\Graph\Exception\UnknownPredicate;
final class PredicateRegistry {
    /** @var array<string,PredicateDefinition> */ private array $definitions = [];
    public function __construct() {
        $all=['wp_post','brand','model','variant','movement','music','component','classification','specimen','product','knowledge','source','media','video','evidence'];
        $this->register(new PredicateDefinition('about',$all,$all));
        $this->register(new PredicateDefinition('depicts',['media'],$all));
    }
    public function register(PredicateDefinition $definition): void { $this->definitions[$definition->key]=$definition; }
    public function get(string $key): PredicateDefinition { if (!isset($this->definitions[$key])) throw new UnknownPredicate('Unknown predicate: '.$key); return $this->definitions[$key]; }
    /** @return list<PredicateDefinition> */ public function all(): array { return array_values($this->definitions); }
}
