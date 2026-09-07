<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

final class AdminContentAdapter
{
    /** @return list<array{id:string,label:string,description:string}> */
    public function tabs(): array
    {
        return [
            ['id' => 'article', 'label' => 'Bài viết', 'description' => 'Editorial content do WordPress sở hữu.'],
            ['id' => 'video', 'label' => 'Video', 'description' => 'Video canonical và semantic attachment theo contract.'],
        ];
    }
}
