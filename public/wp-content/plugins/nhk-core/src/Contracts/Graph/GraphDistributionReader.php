<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Graph;

interface GraphDistributionReader
{
    /** @return list<array{source_type:string,predicate:string,target_type:string,edge_count?:int}> */
    public function rows(): array;
}
