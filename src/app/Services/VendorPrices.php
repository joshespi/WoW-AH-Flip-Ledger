<?php

namespace App\Services;

use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\BlizzardException;
use App\Support\Utc;
use Illuminate\Support\Facades\Cache;

/**
 * Blizzard's listed vendor prices (copper each): what a vendor pays for an item (`sell`) and what
 * it charges (`buy`). Retail item data, used for every game. Cached per region and item; null when
 * unknown, unset, or Blizzard can't be asked (not cached then).
 */
class VendorPrices
{
    public function sell(int $itemId, string $region): ?int
    {
        return $this->listed($itemId, $region)['sell'];
    }

    public function buy(int $itemId, string $region): ?int
    {
        return $this->listed($itemId, $region)['buy'];
    }

    /** @return array{sell: ?int, buy: ?int} */
    private function listed(int $itemId, string $region): array
    {
        $none = ['sell' => null, 'buy' => null];
        $key = "vendor-prices:$region:$itemId";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $client = app(BlizzardClient::class);

            if (! $client->configured()) {
                return $none;
            }

            $item = $client->item($itemId, $region);
        } catch (BlizzardException) {
            Cache::put($key, $none, 300);

            return $none;
        }

        $price = fn (?int $p): ?int => $p === null || $p < 1 ? null : $p;
        $prices = ['sell' => $price($item['sell_price'] ?? null), 'buy' => $price($item['buy_price'] ?? null)];
        Cache::put($key, $prices, $prices === $none ? Utc::DAY : 30 * Utc::DAY);

        return $prices;
    }
}
