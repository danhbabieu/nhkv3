<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Governance;

final class CommandCanonicalizer
{
    public static function canonicalize(array $command): string
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) return $value;
            if (array_is_list($value)) return array_map($normalize, $value);
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) $value[$key] = $normalize($item);
            return $value;
        };
        return json_encode($normalize($command), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function fingerprint(string $operation, string $entityType, ?string $targetUuid, ?int $expectedRevision, array $command, array $dependencyUuids): string
    {
        $dependencies = array_values(array_unique($dependencyUuids));
        sort($dependencies, SORT_STRING);
        return hash('sha256', json_encode([$operation, $entityType, $targetUuid, $expectedRevision, self::canonicalize($command), $dependencies], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true);
    }
}
