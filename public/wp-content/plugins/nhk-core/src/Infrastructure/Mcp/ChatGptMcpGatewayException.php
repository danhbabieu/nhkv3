<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Mcp;

final class ChatGptMcpGatewayException extends \RuntimeException
{
    public function __construct(private string $reasonCode, string $message, private ?string $host = null)
    {
        parent::__construct($message);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    public function host(): ?string
    {
        return $this->host;
    }
}
