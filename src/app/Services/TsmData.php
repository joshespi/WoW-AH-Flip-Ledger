<?php

namespace App\Services;

/**
 * What TsmParser extracts. Everything here is untrusted user input: names are raw (sanitize
 * with ItemName::sanitize before storing) and csv rows are plain string maps.
 */
final readonly class TsmData
{
    /** The only sale/buy source that is an auction house flip (vendor, trade and so on are ignored). */
    public const SOURCE_AUCTION = 'Auction';

    /**
     * @param  array<string, string>  $names  base item id (`i:123`) => item name
     * @param  array<string, list<array<string, string>>>  $csv  section (`csvSales`) => rows; each row also has `realm`
     * @param  array<string, int>  $inventory  base item id => total quantity across characters and locations
     */
    public function __construct(
        public array $names,
        public array $csv,
        public array $inventory,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function rows(string $section): array
    {
        return $this->csv[$section] ?? [];
    }
}
