<?php

namespace App\Support;

/**
 * The WoW economies a user can have data for. Each is separate: Forever (`_forever_`) and Classic
 * (`_classic_`) rows never mix into the retail ledger. `ALL` is a view over every enabled game,
 * never stored. Which games a user sees is their `enabled_games` setting.
 */
final class Game
{
    public const RETAIL = 'retail';

    public const FOREVER = 'forever';

    public const CLASSIC = 'classic';

    public const ALL = 'all';

    /** In display order. */
    public const GAMES = [self::RETAIL, self::FOREVER, self::CLASSIC];

    /** The WoW install folder each game's files live in. */
    public const FOLDERS = [self::RETAIL => '_retail_', self::FOREVER => '_forever_', self::CLASSIC => '_classic_'];

    public static function valid(mixed $game): bool
    {
        return is_string($game) && in_array($game, self::GAMES, true);
    }

    /**
     * The valid games among $games, in display order; never empty (retail when none).
     *
     * @return list<string>
     */
    public static function normalize(array $games): array
    {
        $keep = array_values(array_filter(self::GAMES, fn (string $g) => in_array($g, $games, true)));

        return $keep === [] ? [self::RETAIL] : $keep;
    }

    public static function label(string $game): string
    {
        return match ($game) {
            self::FOREVER => __('Forever'),
            self::CLASSIC => __('Classic'),
            default => __('Retail'),
        };
    }

    /**
     * @param  list<string>  $games
     * @return array<string, string> game => label
     */
    public static function labels(array $games): array
    {
        return array_combine($games, array_map(self::label(...), $games));
    }

    /** The `<x-chip>` tone that marks the game: Retail gold, Forever teal, Classic violet. */
    public static function tone(string $game): string
    {
        return match ($game) {
            self::FOREVER => 'teal',
            self::CLASSIC => 'violet',
            default => 'indigo',
        };
    }

    /**
     * The game's line on a chart, matching tone(); the dash tells the lines apart without colour.
     *
     * @return array{color: string, dash: list<int>|null}
     */
    public static function chart(string $game): array
    {
        return match ($game) {
            self::FOREVER => ['color' => '#2dd4bf', 'dash' => [6, 4]],
            self::CLASSIC => ['color' => '#a78bfa', 'dash' => [2, 3]],
            default => ['color' => '#e5b64a', 'dash' => null],
        };
    }

    /**
     * Whether the game is backed by the shared item catalog and Blizzard's price feed (retail
     * only): the others get no market stats and never name items in the catalog.
     */
    public static function hasMarket(string $game): bool
    {
        return $game === self::RETAIL;
    }

    /** Prefix that keeps a non-retail row's dedupe key apart from an identical retail row's. */
    public static function keyPrefix(string $game): string
    {
        return $game === self::RETAIL ? '' : $game.':';
    }
}
