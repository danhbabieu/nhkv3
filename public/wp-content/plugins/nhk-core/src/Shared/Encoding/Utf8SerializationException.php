<?php
declare(strict_types=1);

namespace NHK\Core\Shared\Encoding;

final class Utf8SerializationException extends \RuntimeException
{
    public function __construct(
        public readonly string $producer,
        public readonly string $path,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('UTF8_SERIALIZATION_FAILED', 0, $previous);
    }
}
