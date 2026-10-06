<?php
declare(strict_types=1);

namespace NHK\Core\Shared\Encoding;

final class Utf8ValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $producer,
        public readonly string $path,
    ) {
        parent::__construct('UTF8_INVALID_INPUT');
    }
}
