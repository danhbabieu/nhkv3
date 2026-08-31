<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Shared;

interface TransactionManager
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function transactional(callable $callback): mixed;
    public function run(callable $callback): mixed;
}
