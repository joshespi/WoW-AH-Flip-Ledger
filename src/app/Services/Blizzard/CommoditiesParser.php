<?php

namespace App\Services\Blizzard;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Throwable;

/**
 * Stream-parses a commodities response ({"auctions": [{"item": {"id"}, "quantity", "unit_price"}, ...]})
 * from disk into one aggregate per item. The file is tens of MB, so it is never loaded whole:
 * memory stays proportional to the number of distinct items, not auctions.
 */
class CommoditiesParser
{
    /**
     * @return array<int, array{floor: int, quantity: int, auctions: int}> keyed by item id
     *
     * @throws BlizzardException when the file isn't valid commodity data (truncated, wrong shape, empty)
     */
    public function aggregate(string $path): array
    {
        $items = [];

        try {
            $auctions = Items::fromFile($path, [
                'pointer' => '/auctions',
                'decoder' => new ExtJsonDecoder(true),
            ]);

            foreach ($auctions as $a) {
                $id = $a['item']['id'] ?? null;
                $price = $a['unit_price'] ?? null;
                $qty = $a['quantity'] ?? null;

                // Skip rows we can't use rather than guess; a bad row shouldn't poison a snapshot.
                if (! is_int($id) || $id < 1 || ! is_int($price) || $price < 1 || ! is_int($qty) || $qty < 1) {
                    continue;
                }

                if (! isset($items[$id])) {
                    $items[$id] = ['floor' => $price, 'quantity' => $qty, 'auctions' => 1];

                    continue;
                }

                $items[$id]['floor'] = min($items[$id]['floor'], $price);
                $items[$id]['quantity'] += $qty;
                $items[$id]['auctions']++;
            }
        } catch (Throwable) {
            // A truncated or malformed body must never become a partial snapshot.
            throw new BlizzardException('Blizzard returned a response that is not valid commodity data.');
        }

        if ($items === []) {
            throw new BlizzardException('Blizzard returned no usable commodity listings.');
        }

        return $items;
    }
}
