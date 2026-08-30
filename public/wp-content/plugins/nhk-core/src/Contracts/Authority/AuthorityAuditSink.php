<?php
declare(strict_types=1);
namespace NHK\Core\Contracts\Authority;
use NHK\Core\Domain\Authority\AuthorityEntity;
interface AuthorityAuditSink { public function record(string $event, AuthorityEntity $entity):void; }
