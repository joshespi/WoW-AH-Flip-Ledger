<?php

namespace Tests\Unit;

use App\Support\ItemName;
use App\Support\LuaString;
use PHPUnit\Framework\TestCase;

class LuaStringAndItemNameTest extends TestCase
{
    public function test_unescape_handles_named_decimal_and_literal_escapes(): void
    {
        $this->assertSame("a\nb\tc\rd", LuaString::unescape('a\\nb\\tc\\rd'));
        $this->assertSame('say "hi" \\ done', LuaString::unescape('say \\"hi\\" \\\\ done'));
        $this->assertSame("x\x02y", LuaString::unescape('x\\2y'));
        $this->assertSame('ABC', LuaString::unescape('\\065\\066\\067'));
    }

    public function test_unescape_leaves_a_trailing_lone_backslash_and_plain_text_alone(): void
    {
        $this->assertSame('plain text', LuaString::unescape('plain text'));
        $this->assertSame('abc\\', LuaString::unescape('abc\\'));
    }

    public function test_base_id_strips_bonus_ids_for_items_and_pets(): void
    {
        $this->assertSame('i:238511', ItemName::baseId('i:238511::1:6710'));
        $this->assertSame('i:238511', ItemName::baseId('i:238511'));
        $this->assertSame('p:2843', ItemName::baseId('p:2843:1:3'));
        $this->assertSame('weird', ItemName::baseId('weird'));
    }

    public function test_numeric_id_only_for_plain_item_ids(): void
    {
        $this->assertSame(238511, ItemName::numericId('i:238511'));
        $this->assertNull(ItemName::numericId('p:2843'));
        $this->assertNull(ItemName::numericId('i:12x'));
    }

    public function test_sanitize_cleans_untrusted_names(): void
    {
        $this->assertSame("Sin'dorei Swarmer", ItemName::sanitize("  Sin'dorei \x01\x02 Swarmer\n"));
        $this->assertSame('Café Crème', ItemName::sanitize('Café Crème'));
        $scrubbed = ItemName::sanitize("Bad \xC3\x28 bytes");
        $this->assertNotNull($scrubbed);
        $this->assertTrue(mb_check_encoding($scrubbed, 'UTF-8'));
        $this->assertNull(ItemName::sanitize("\x01\x02  \n"));
        $this->assertSame(120, mb_strlen(ItemName::sanitize(str_repeat('é', 500))));
    }
}
