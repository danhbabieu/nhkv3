<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Database;

use NHK\Core\Contracts\Shared\TransactionManager;

final class WpdbTransactionManager implements TransactionManager
{
    public function __construct(private ?object $database = null) {}
    private function db(): object { global $wpdb; return $this->database ?? $wpdb; }
    public function begin(): void { if ($this->db()->query('START TRANSACTION') === false) throw new \RuntimeException('TRANSACTION_BEGIN_FAILED'); }
    public function commit(): void { if ($this->db()->query('COMMIT') === false) throw new \RuntimeException('TRANSACTION_COMMIT_FAILED'); }
    public function rollback(): void { $this->db()->query('ROLLBACK'); }
    public function transactional(callable $callback): mixed
    {
        $this->begin();
        try { $result = $callback(); $this->commit(); return $result; }
        catch (\Throwable $error) { $this->rollback(); throw $error; }
    }
    public function run(callable $callback): mixed { return $this->transactional($callback); }
}
