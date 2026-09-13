<?php
declare(strict_types=1);

namespace NHK\Core\Application\Runtime;

enum SemanticWritePolicy: string
{
    case READ_ONLY = 'READ_ONLY';
    case PROJECT_BUILD = 'PROJECT_BUILD';
    case LOCKED_OPERATIONAL = 'LOCKED_OPERATIONAL';

    public static function fromConfiguration(mixed $value): self
    {
        return match (strtolower(trim((string) $value))) {
            'project_build' => self::PROJECT_BUILD,
            'locked_operational' => self::LOCKED_OPERATIONAL,
            'read_only' => self::READ_ONLY,
            default => self::READ_ONLY,
        };
    }
}
