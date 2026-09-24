<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Video;

class VideoException extends \RuntimeException
{
    /** @param array<string,mixed> $diagnostics */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, public readonly array $diagnostics = [])
    {
        parent::__construct($message, $code, $previous);
    }
}
