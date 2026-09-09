<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

use NHK\Core\Domain\Media\MediaUsage;

/**
 * Optional mutation capability for an existing usage identity.
 *
 * Keeping this separate from MediaUsageRepository preserves read-only and
 * in-memory adapters while making replacement a non-destructive update rather
 * than a delete-and-create operation.
 */
interface MediaUsageUpdater
{
    public function update(MediaUsage $usage): MediaUsage;
}
