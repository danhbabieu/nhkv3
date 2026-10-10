<?php
declare(strict_types=1);

namespace NHK\Core\Domain\FacebookAudit;

use InvalidArgumentException;

final readonly class FacebookAuditScope
{
    public const TARGET_URL = 'https://www.facebook.com/donghonhakho.vn';

    private function __construct(public string $targetUrl, public ?string $verifiedPageId) {}

    public static function forTarget(string $url, ?string $verifiedPageId): self
    {
        $canonical = self::canonicalize($url);
        if ($canonical !== self::TARGET_URL) throw new InvalidArgumentException('SCOPE_MISMATCH');
        if ($verifiedPageId !== null && !preg_match('/^[0-9]+$/', trim($verifiedPageId))) throw new InvalidArgumentException('IDENTITY_NOT_VERIFIED');
        return new self($canonical, $verifiedPageId !== null ? trim($verifiedPageId) : null);
    }

    public function withVerifiedPageId(string $pageId): self
    {
        $pageId = trim($pageId);
        if ($pageId === '' || !preg_match('/^[0-9]+$/', $pageId)) throw new InvalidArgumentException('IDENTITY_NOT_VERIFIED');
        return new self($this->targetUrl, $pageId);
    }

    public function isIdentityVerified(): bool { return $this->verifiedPageId !== null; }

    public function assertCollectionReady(): void
    {
        if (!$this->isIdentityVerified()) throw new InvalidArgumentException('IDENTITY_NOT_VERIFIED');
    }

    private static function canonicalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== 'www.facebook.com') throw new InvalidArgumentException('SCOPE_MISMATCH');
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) throw new InvalidArgumentException('SCOPE_MISMATCH');
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        return 'https://www.facebook.com' . $path;
    }
}
