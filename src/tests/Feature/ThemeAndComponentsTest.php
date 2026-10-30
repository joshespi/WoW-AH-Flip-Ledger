<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Support\Fixtures;
use Tests\TestCase;

class ThemeAndComponentsTest extends TestCase
{
    use Fixtures;
    use RefreshDatabase;

    /** Rendered markup as the plain text a reader sees. */
    private function text(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }

    private function money(int|float|null $copper, bool $pnl = false): string
    {
        return $this->text(Blade::render('<x-money :copper="$c" :pnl="$p" />', ['c' => $copper, 'p' => $pnl]));
    }

    public function test_every_page_sets_the_theme_before_paint_and_has_the_toggle(): void
    {
        $user = User::factory()->create();

        foreach (['/login' => null, '/dashboard' => $user, '/positions' => $user, '/settings' => $user] as $url => $as) {
            $res = ($as ? $this->actingAs($as) : $this)->get($url)->assertOk();

            $res->assertSee("localStorage.getItem('theme')", false);
            $res->assertSee('window.theme.apply()', false);
            $res->assertSee('x-data="themeToggle"', false);
            $res->assertSee("set('light')", false);
            $res->assertSee("set('system')", false);
            $res->assertSee("set('dark')", false);
        }
    }

    public function test_the_landing_page_is_ours_not_laravels(): void
    {
        $this->get('/')->assertOk()
            ->assertDontSee('laravel.com')
            ->assertDontSee('cloud.laravel.com')
            ->assertSee(route('login'), false)
            ->assertSee(route('register'), false)
            ->assertSee('Not affiliated with Blizzard Entertainment or TradeSkillMaster')
            ->assertSee('x-data="themeToggle"', false);

        $this->actingAs(User::factory()->create())->get('/')->assertOk()
            ->assertSee('Open dashboard')->assertSee(route('dashboard'), false);
    }

    public function test_every_page_has_its_own_title_and_our_favicon(): void
    {
        $user = User::factory()->create();

        foreach (['/login' => ['Log in', null], '/register' => ['Sign up', null], '/dashboard' => ['Dashboard', $user],
            '/positions' => ['Positions', $user], '/trades/import' => ['Import trades', $user], '/settings' => ['Settings', $user]] as $url => [$title, $as]) {
            ($as ? $this->actingAs($as) : $this)->get($url)
                ->assertSee("<title>{$title} · WoW Flip Ledger</title>", false)
                ->assertSee('href="'.asset('favicon.ico').'"', false)
                ->assertSee('href="'.asset('apple-touch-icon.png').'"', false);
            auth()->logout();
        }

        $this->get('/')->assertSee('<title>WoW Flip Ledger</title>', false);
        foreach (['favicon.ico', 'logo.png', 'apple-touch-icon.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }
        $this->assertFileDoesNotExist(public_path('favicon.svg'));
    }

    public function test_the_theme_script_is_inline_and_loads_nothing_third_party(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('fonts.bunny.net', $html);
        $this->assertStringNotContainsString('cdn.', $html);
        $this->assertMatchesRegularExpression('/<script[^>]*>\s*\(function \(\) \{\s*var media/', $html);
    }

    public function test_money_renders_the_same_digits_as_the_plain_formatter_for_every_golden_case(): void
    {
        foreach ($this->golden('money')['fmt'] as [$copper, $expected]) {
            $this->assertSame($expected, str_replace('−', '-', $this->money($copper)), "copper {$copper}");
        }
    }

    public function test_money_shows_a_dash_when_there_is_no_value(): void
    {
        $this->assertSame('—', $this->money(null));
    }

    public function test_money_pnl_adds_an_explicit_sign_and_never_to_zero(): void
    {
        $this->assertSame('+6,496g 53s 80c', $this->money(64_965_380, pnl: true));
        $this->assertSame('−1,488g 16s 20c', $this->money(-14_881_620, pnl: true));
        $this->assertSame('0s 00c', $this->money(0, pnl: true));
        $this->assertSame('6,496g 53s 80c', $this->money(64_965_380), 'no plus without pnl');
    }

    public function test_money_colors_each_unit_and_carries_the_plain_text_as_title(): void
    {
        $html = Blade::render('<x-money :copper="$c" />', ['c' => 12_345_678]);

        $this->assertStringContainsString('title="1,234g 56s 78c"', $html);
        $this->assertStringContainsString('<span class="text-amber-600 dark:text-indigo-300">1,234g</span>', $html);
        $this->assertStringContainsString('<span class="text-gray-500 dark:text-gray-300">56s</span>', $html);
        $this->assertStringContainsString('<span class="text-orange-700 dark:text-orange-400">78c</span>', $html);
    }

    public function test_pct_is_signed_and_colored(): void
    {
        $this->assertSame('+4.2%', $this->text(Blade::render('<x-pct :value="0.0415" />')));
        $this->assertSame('−2.7%', $this->text(Blade::render('<x-pct :value="-0.0274" />')));
        $this->assertSame('—', $this->text(Blade::render('<x-pct :value="null" />')));
        $this->assertStringContainsString('text-red-600', Blade::render('<x-pct :value="-0.5" />'));
    }

    public function test_chips_say_what_they_mean_in_words(): void
    {
        foreach (['profit' => 'In profit', 'underwater' => 'Under water', 'below' => 'Below typical', 'above' => 'Above typical'] as $type => $label) {
            $this->assertSame($label, $this->text(Blade::render('<x-chip :type="$t" />', ['t' => $type])));
        }
    }

    public function test_the_collecting_placeholder_shows_progress_against_the_minimum(): void
    {
        $this->assertSame('collecting data (3/14)', $this->text(Blade::render('<x-collecting :days="3" />')));
    }
}
