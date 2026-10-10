<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\FacebookAudit;

use InvalidArgumentException;

final class MetaGraphHttpClient
{
    /** @var callable(string,string,array<string,string>):array<string,mixed>|null */
    private $transport;

    public function __construct(?callable $transport = null, private string $graphVersion = 'v20.0')
    {
        $this->transport = $transport;
        if (!preg_match('/^v[0-9]+(?:\.[0-9]+)?$/', $this->graphVersion)) throw new InvalidArgumentException('INVALID_META_GRAPH_VERSION');
    }

    /** @return array{ok:bool,data?:array<string,mixed>,error_code?:string,status?:int} */
    public function get(string $path, array $query, string $accessToken): array
    {
        $accessToken = trim($accessToken);
        if ($accessToken === '') throw new InvalidArgumentException('META_CREDENTIALS_MISSING');
        $path = '/' . ltrim(trim($path), '/');
        if (str_contains($path, '..') || !preg_match('#^/[A-Za-z0-9._/-]+$#', $path)) throw new InvalidArgumentException('META_PATH_NOT_ALLOWED');
        $query = array_map('strval', $query);
        $query['access_token'] = $accessToken;
        $url = 'https://graph.facebook.com/' . $this->graphVersion . $path;
        try {
            $response = $this->transport !== null
                ? ($this->transport)('GET', $url, $query)
                : $this->nativeGet($url, $query);
            if (!is_array($response)) return ['ok' => false, 'error_code' => 'META_INVALID_RESPONSE'];
            if (isset($response['error']) || isset($response['error_code'])) return ['ok' => false, 'error_code' => 'META_READ_DENIED', 'status' => (int) ($response['status'] ?? 403)];
            return ['ok' => true, 'data' => $response];
        } catch (\Throwable) {
            return ['ok' => false, 'error_code' => 'META_READ_FAILED'];
        }
    }

    /** @return array<string,mixed> */
    private function nativeGet(string $url, array $query): array
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 15, 'ignore_errors' => true]]);
        $body = @file_get_contents($url . '?' . http_build_query($query), false, $context);
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : ['error_code' => 'META_INVALID_RESPONSE'];
    }
}
