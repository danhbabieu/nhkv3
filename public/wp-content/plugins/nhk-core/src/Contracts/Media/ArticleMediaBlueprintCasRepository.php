<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

use NHK\Core\Domain\Media\MediaSeoBlueprint;

/** CAS write boundary for governed Article Media subject bindings. */
interface ArticleMediaBlueprintCasRepository extends ArticleMediaBlueprintRepository
{
    public function saveExpected(MediaSeoBlueprint $blueprint, int $expectedRevision): MediaSeoBlueprint;
}
