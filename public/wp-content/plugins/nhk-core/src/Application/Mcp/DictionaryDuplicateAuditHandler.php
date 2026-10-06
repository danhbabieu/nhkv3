<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Dictionary\DictionaryRuntime;

final class DictionaryDuplicateAuditHandler
{
    public function __construct(private DictionaryRuntime $runtime) {}

    public function audit(array $input): array
    {
        return $this->runtime->duplicateAudit()->run(
            (int) ($input['limit'] ?? 100),
            isset($input['cursor']) ? (string) $input['cursor'] : null,
        );
    }
}
