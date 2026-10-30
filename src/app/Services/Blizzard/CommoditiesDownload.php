<?php

namespace App\Services\Blizzard;

final readonly class CommoditiesDownload
{
    /**
     * @param  int|null  $lastModified  Blizzard's own snapshot time (epoch), from Last-Modified
     * @param  array<string, string>  $headers  response headers, lower-cased names
     */
    public function __construct(
        public bool $notModified,
        public ?int $lastModified,
        public int $bytes,
        public array $headers = [],
    ) {}
}
