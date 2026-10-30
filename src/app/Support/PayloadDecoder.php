<?php

namespace App\Support;

use App\Services\TsmParseException;
use App\Services\TsmParser;

final class PayloadDecoder
{
    /**
     * Upload bodies may be gzipped (the uploader always does; manual uploads may). Detect by
     * magic bytes rather than trusting a header, and cap the decoded size so a tiny gzip can't
     * expand into memory (a zip bomb).
     *
     * @throws TsmParseException
     */
    public static function decode(string $raw): string
    {
        if (! str_starts_with($raw, "\x1f\x8b")) {
            return $raw;
        }

        $limit = TsmParser::MAX_BYTES;
        $out = @gzdecode($raw, $limit + 1);

        if ($out === false) {
            throw new TsmParseException('The compressed upload is corrupt or expands to more than the allowed size.');
        }

        if (strlen($out) > $limit) {
            throw new TsmParseException('That file is too large to be a TradeSkillMaster data file.');
        }

        return $out;
    }
}
