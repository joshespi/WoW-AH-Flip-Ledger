<?php

namespace App\Services;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Reads pasted auction-house chat lines such as
 * `You won an auction for [Practically Pork]x5351 for 2,730g 82s`.
 * Quantity defaults to 1; gold may lack silver or copper; a raw item link
 * (`|Hitem:242639::...|h[Name]|h`) yields the id as well as the name.
 * Lines that are not "won an auction" lines are skipped (a paste is mostly noise).
 */
class TradesPasteParser
{
    private const LINE = '/won an auction for\s+(?<link>.*?\[(?<name>[^\]]+)\][^\s\]]*?)\s*(?:x\s*(?<qty>[\d,]+))?\s+for\s+(?<cost>[\d,]+\s*g?(?:\s*\d+\s*s)?(?:\s*\d+\s*c)?)\s*\.?\s*$/iu';

    /**
     * @return list<array{name: string, id: ?int, qty: int, cost: int, line: int}>
     *
     * @throws InvalidArgumentException with the pasted line number in the message
     */
    public function parse(string $text): array
    {
        $trades = [];

        foreach (preg_split('/\R/u', $text) as $i => $raw) {
            $line = $i + 1;

            if (! preg_match('/won an auction for/i', $raw)) {
                continue;
            }

            if (! preg_match(self::LINE, trim($raw), $m)) {
                throw new InvalidArgumentException("line {$line}: could not read \"".mb_substr(trim($raw), 0, 80).'".');
            }

            $qty = ($m['qty'] ?? '') === '' ? 1 : (int) str_replace(',', '', $m['qty']);

            if ($qty < 1) {
                throw new InvalidArgumentException("line {$line}: quantity must be at least 1.");
            }

            try {
                $cost = Money::parseGold($m['cost']);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException("line {$line}: bad cost \"{$m['cost']}\".");
            }

            $trades[] = [
                'name' => trim($m['name']),
                'id' => preg_match('/Hitem:(\d+)/', $m['link'], $im) ? (int) $im[1] : null,
                'qty' => $qty,
                'cost' => $cost,
                'line' => $line,
            ];
        }

        return $trades;
    }
}
