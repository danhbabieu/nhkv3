<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class WpdbMigrationLedgerRepository
{
    private string $table;
    public function __construct(private object $database) { $this->table = $database->prefix . 'nhk_migration_ledger'; }

    public function find(string $sourceType, string $sourceKey): ?array
    {
        return $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE source_type=%s AND source_key=%s LIMIT 1", $sourceType, $sourceKey), ARRAY_A) ?: null;
    }

    public function record(string $sourceType, string $sourceKey, string $status, string $reasonCode, ?string $sourceChecksum = null, ?string $targetType = null, ?string $targetKey = null, ?string $targetId = null, int $batchNo = 0, array $details = []): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $sql = "INSERT INTO {$this->table} (source_type,source_key,source_checksum,status,reason_code,target_type,target_key,target_id,batch_no,details_json,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE source_checksum=VALUES(source_checksum),status=VALUES(status),reason_code=VALUES(reason_code),target_type=VALUES(target_type),target_key=VALUES(target_key),target_id=VALUES(target_id),batch_no=VALUES(batch_no),details_json=VALUES(details_json),updated_at=VALUES(updated_at)";
        $ok = $this->database->query($this->database->prepare($sql, $sourceType, $sourceKey, $sourceChecksum, $status, $reasonCode, $targetType, $targetKey, $targetId, $batchNo, wp_json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now));
        if ($ok === false) throw new \RuntimeException('MIGRATION_LEDGER_WRITE_FAILED');
    }

    public function counts(): array
    {
        $rows = $this->database->get_results("SELECT status,reason_code,COUNT(*) AS total FROM {$this->table} GROUP BY status,reason_code ORDER BY status,reason_code", ARRAY_A) ?: [];
        return array_map(static fn (array $row): array => ['status' => (string) $row['status'], 'reason_code' => (string) $row['reason_code'], 'total' => (int) $row['total']], $rows);
    }
}
