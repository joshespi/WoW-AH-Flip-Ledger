<?php

namespace App\Services;

use App\Support\Bounds;

/**
 * What TsmParser extracts. Everything here is untrusted user input: names are raw (sanitize
 * with ItemName::sanitize before storing) and csv rows are plain string maps.
 */
final readonly class TsmData
{
    /** The only sale/buy source that is an auction house flip (vendor, trade and so on are ignored). */
    public const SOURCE_AUCTION = 'Auction';

    public const ORIGIN_TSM = 'tsm';

    public const ORIGIN_ADDON = 'addon';

    /**
     * @param  array<string, string>  $names  base item id (`i:123`) => item name
     * @param  array<string, list<array<string, string>>>  $csv  section (`csvSales`) => rows; each row also has `realm`
     * @param  array<string, int>  $inventory  base item id => total quantity across characters and locations
     * @param  string  $origin  `tsm` (TradeSkillMaster.lua) or `addon` (the companion addon's own log)
     * @param  int|null  $takenAt  when the inventory snapshot was taken (epoch), when the file says
     * @param  string|null  $game  the game whose client wrote the file, when it says (the addon's `interface`; TSM files never do)
     */
    public function __construct(
        public array $names,
        public array $csv,
        public array $inventory,
        public string $origin = self::ORIGIN_TSM,
        public ?int $takenAt = null,
        public ?string $game = null,
    ) {}

    /**
     * Realms the file's log rows name (TSM's realm scopes, the addon's normalized realm).
     *
     * @return list<string>
     */
    public function realms(): array
    {
        $realms = [];

        foreach ($this->csv as $rows) {
            foreach ($rows as $r) {
                if (($r['realm'] ?? '') !== '') {
                    $realms[Bounds::realm($r['realm'])] = true;
                }
            }
        }

        return array_keys($realms);
    }

    /**
     * @return list<array<string, string>>
     */
    public function rows(string $section): array
    {
        return $this->csv[$section] ?? [];
    }
}
