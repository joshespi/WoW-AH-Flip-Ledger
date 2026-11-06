<?php

namespace App\Services;

use Generator;

/**
 * The capped line scanner both SavedVariables parsers share: walks the file without splitting it
 * into an array, strips a trailing CR, skips blank lines and refuses past $maxLines.
 */
final class LuaLines
{
    /**
     * @param  string  $what  the file kind for the error ("TradeSkillMaster")
     * @return Generator<int, string>
     *
     * @throws TsmParseException
     */
    public static function of(string $contents, int $maxLines, string $what): Generator
    {
        $lines = 0;
        $pos = 0;
        $len = strlen($contents);

        while ($pos < $len) {
            if (++$lines > $maxLines) {
                throw new TsmParseException("That file has too many lines to be a {$what} data file.");
            }

            $nl = strpos($contents, "\n", $pos);
            $end = $nl === false ? $len : $nl;
            $line = substr($contents, $pos, $end - $pos);
            $pos = $end + 1;

            if ($line !== '' && $line[-1] === "\r") {
                $line = substr($line, 0, -1);
            }

            if ($line !== '') {
                yield $line;
            }
        }
    }
}
