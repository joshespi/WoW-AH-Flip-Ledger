<?php

namespace App\Console\Commands;

use App\Console\PricesCommand;
use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\BlizzardException;
use App\Services\Blizzard\CommoditiesParser;
use App\Support\Utc;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Throwable;

/**
 * Checks the Blizzard assumptions the price pipeline relies on against the live API, and writes
 * nothing to the database. Run it once after setting BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET:
 *
 *   docker compose exec app php artisan prices:probe
 */
class PricesProbe extends PricesCommand
{
    protected $signature = 'prices:probe {--region= : Region code (default: BLIZZARD_REGION)} {--item=238371 : Item id for the item lookup}';

    protected $description = 'Verify the live Blizzard API: token, commodities shape, headers, If-Modified-Since, item lookup (no database writes)';

    public function handle(BlizzardClient $client, CommoditiesParser $parser): int
    {
        if (! $this->credentialsSet($client)) {
            return self::FAILURE;
        }

        $region = $this->region();
        $tmp = tempnam(sys_get_temp_dir(), 'wfl-probe-');
        $problems = [];

        try {
            $this->line('1. Access token');
            $client->accessToken(fresh: true);
            $this->info('   OK: got a token (the secret is not shown).');

            $this->line("2. Commodities download ({$region})");
            $t = microtime(true);
            $download = $client->commodities($region, $tmp);
            $secs = round(microtime(true) - $t, 1);
            $this->info(sprintf('   OK: %s MB in %s s.', number_format($download->bytes / 1048576, 1), $secs));

            $this->line('   Headers worth knowing:');
            foreach ($download->headers as $name => $value) {
                if (preg_match('/rate|limit|cache|etag|modified|length|age|^date$|retry|vary|encoding/', $name)) {
                    $this->line("     {$name}: {$value}");
                }
            }

            if ($download->lastModified === null) {
                $problems[] = 'No Last-Modified header: snapshots will be stamped with fetch time and conditional requests are not possible.';
            } else {
                $this->info('   Last-Modified: '.Utc::sql($download->lastModified).' UTC ('.round((time() - $download->lastModified) / 60).' min ago)');
            }

            $this->line('3. Response shape');
            $first = null;
            $count = 0;
            foreach (Items::fromFile($tmp, ['pointer' => '/auctions', 'decoder' => new ExtJsonDecoder(true)]) as $a) {
                $first ??= $a;
                $count++;
            }
            $this->line('   auctions: '.number_format($count));
            $this->line('   first auction: '.json_encode($first));

            $items = $parser->aggregate($tmp);
            $this->info('   OK: parsed into '.number_format(count($items)).' distinct items.');

            uasort($items, fn ($a, $b) => $b['quantity'] <=> $a['quantity']);
            foreach (array_slice($items, 0, 3, true) as $id => $a) {
                $this->line(sprintf('   item %d: floor %d copper, quantity %s, %d auctions', $id, $a['floor'], number_format($a['quantity']), $a['auctions']));
            }

            $this->line('4. If-Modified-Since');
            if ($download->lastModified !== null) {
                $again = $client->commodities($region, $tmp, $download->lastModified);

                if ($again->notModified) {
                    $this->info('   OK: Blizzard answered 304 Not Modified. Fetching more often than hourly is cheap.');
                } else {
                    $problems[] = 'Blizzard ignored If-Modified-Since (sent the full file again). Keep fetching hourly; the Last-Modified dedupe still prevents duplicate snapshots.';
                    $this->warn('   Blizzard returned the full file again: If-Modified-Since is NOT honored.');
                }
            }

            $this->line('5. Item lookup');
            $id = (int) $this->option('item');
            $item = $client->item($id, $region);
            $item === null
                ? $this->warn("   Blizzard has no item {$id}.")
                : $this->info('   OK: item '.$id.' is "'.($item['name'] ?? '?').'" ('.($item['quality'] ?? '?').').');
        } catch (BlizzardException $e) {
            $this->error('   FAILED: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('   FAILED: '.get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($tmp);
        }

        $this->newLine();
        foreach ($problems as $p) {
            $this->warn('Note: '.$p);
        }
        $this->info($problems === [] ? 'All checks passed.' : 'Checks finished with notes above.');

        return self::SUCCESS;
    }
}
