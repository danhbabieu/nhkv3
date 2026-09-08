<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

interface AtomicMediaBatchUploadRepository
{
    /** Return an existing binding, or null after atomically reserving the key. */
    public function claim(string $idempotencyKey, string $fingerprint): ?array;
}
