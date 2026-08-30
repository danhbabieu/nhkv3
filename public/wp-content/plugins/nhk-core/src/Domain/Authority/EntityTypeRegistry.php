<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Authority;
use RuntimeException;
final class EntityTypeRegistry { private array $definitions=[]; public function register(EntityTypeDefinition $d):void{$this->definitions[$d->type]=$d;} public function get(string $type):EntityTypeDefinition{if(!isset($this->definitions[$type]))throw new RuntimeException('Unknown entity type: '.$type);return $this->definitions[$type];} public function has(string $type):bool{return isset($this->definitions[$type]);} public function all():array{return array_values($this->definitions);} }
