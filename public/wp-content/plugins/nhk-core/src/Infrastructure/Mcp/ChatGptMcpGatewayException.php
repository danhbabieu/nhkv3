<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Mcp;

final class ChatGptMcpGatewayException extends \RuntimeException
{
    /** @param array<string,int|string|null> $diagnostics */
    public function __construct(private string $reasonCode, string $message, private ?string $host = null, private array $diagnostics = [])
    {
        parent::__construct($message);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    public function safeReasonCode(): string
    {
        $explicit = $this->diagnostics['typed_code'] ?? null;
        if (is_string($explicit) && preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $explicit) === 1) return $explicit;

        return match ($this->reasonCode) {
            'CHATGPT_FILE_DNS_FAILED' => 'PROVIDED_FILE_DNS_RESOLUTION_FAILED',
            'CHATGPT_FILE_PRIVATE_IP_REJECTED' => 'PROVIDED_FILE_DESTINATION_NOT_PUBLIC',
            'CHATGPT_FILE_SIZE_LIMIT' => 'PROVIDED_FILE_STREAM_LIMIT',
            'CHATGPT_FILE_MIME_REJECTED', 'CHATGPT_FILE_MIME_MISMATCH' => 'PROVIDED_FILE_MIME_INVALID',
            'CHATGPT_FILE_DECODE_FAILED' => 'PROVIDED_FILE_IMAGE_DECODE_FAILED',
            'CHATGPT_FILE_DECODED_PIXEL_LIMIT' => 'PROVIDED_FILE_PIXEL_LIMIT',
            'CHATGPT_FILE_TEMP_FAILED' => 'PROVIDED_FILE_TEMPFILE_FAILED',
            'CHATGPT_FILE_REDIRECT_REJECTED' => 'PROVIDED_FILE_REDIRECT_REJECTED',
            'CHATGPT_FILE_GATEWAY_RUNTIME_UNAVAILABLE' => 'PROVIDED_FILE_CONNECT_FAILED',
            'CHATGPT_FILE_MIME_UNAVAILABLE' => 'PROVIDED_FILE_MIME_INVALID',
            'PROVIDED_FILE_REFERENCE_UNRESOLVABLE' => 'PROVIDED_FILE_MATERIALIZATION_FAILED',
            default => $this->reasonCode,
        };
    }

    public function withDiagnostic(string $key, int|string|null $value): self
    {
        $diagnostics = $this->diagnostics;
        if ($value !== null && $value !== '') $diagnostics[$key] = $value;
        return new self($this->reasonCode, $this->getMessage(), $this->host, $diagnostics);
    }

    public function host(): ?string
    {
        return $this->host;
    }

    public function safeMessage(): string
    {
        $message = preg_replace('/https?:\/\/[^\s)]+/i', '[redacted-url]', $this->getMessage()) ?? '[redacted-message]';
        return preg_replace('/\bsediment:\/\/[^\s)]+/i', '[redacted-reference]', $message) ?? '[redacted-message]';
    }

    /** @return array<string,int|string|null> */
    public function diagnostics(): array
    {
        return array_filter($this->diagnostics, static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
