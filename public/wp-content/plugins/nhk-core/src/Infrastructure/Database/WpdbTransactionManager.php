<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Database;

use NHK\Core\Contracts\Shared\TransactionManager;

final class WpdbTransactionManager implements TransactionManager
{
    /** @var array<int, true> */
    private static array $active = [];

    public function __construct(private ?object $database = null) {}
    private function db(): object { global $wpdb; return $this->database ?? $wpdb; }
    public function begin(): void { if ($this->db()->query('START TRANSACTION') === false) throw new \RuntimeException('TRANSACTION_BEGIN_FAILED'); self::$active[spl_object_id($this->db())] = true; }
    public function commit(): void { if ($this->db()->query('COMMIT') === false) throw new \RuntimeException('TRANSACTION_COMMIT_FAILED'); unset(self::$active[spl_object_id($this->db())]); }
    public function rollback(): void { $this->db()->query('ROLLBACK'); unset(self::$active[spl_object_id($this->db())]); }
    public static function isActive(object $database): bool { return isset(self::$active[spl_object_id($database)]); }
    public function transactional(callable $callback): mixed
    {
        $this->begin();
        try { $result = $callback(); $this->commit(); return $result; }
        catch (\Throwable $error) { $this->rollback(); throw $error; }
    }
    public function run(callable $callback): mixed { return $this->transactional($callback); }
}
