<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Capture;

use NHK\Core\Domain\Capture\CaptureAddendumRecord;

interface CaptureAddendumRepository
{
    public function findByIdempotencyKey(string $key): ?CaptureAddendumRecord;
    public function create(CaptureAddendumRecord $record): CaptureAddendumRecord;
    public function save(CaptureAddendumRecord $record): CaptureAddendumRecord;
}
