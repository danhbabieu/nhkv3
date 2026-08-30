<?php
declare(strict_types=1);
namespace NHK\Core\Contracts\Authority;
use NHK\Core\Domain\Authority\AuthorityEntity;
interface AuthorityRepository { public function findByCanonicalId(string $id):?AuthorityEntity; public function findByStableKey(string $type,string $key):?AuthorityEntity; public function create(AuthorityEntity $entity):AuthorityEntity; public function update(AuthorityEntity $entity,int $expectedRevision):AuthorityEntity; public function listByType(string $type):array; }
