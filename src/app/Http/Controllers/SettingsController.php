<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\IssuesIngestToken;
use App\Support\Game;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    use IssuesIngestToken;

    /** field => [rule, min, max, step]: validated here, and the form's inputs carry the same limits. */
    private const NUMBERS = [
        'target_margin_pct' => ['numeric', 0, 500, 0.01],
        'market_share_pct' => ['numeric', 0.1, 100, 0.1],
        'stale_minutes' => ['integer', 5, 1440, 1],
    ];

    public function edit(Request $request): View
    {
        return $this->page($request);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'string', 'timezone:all'],
            'region' => ['required', 'string', Rule::in(array_keys(config('ledger.regions')))],
            // Checkboxes post 0/1 (a hidden 0 comes first); a partial post leaves them alone.
            'alerts_enabled' => ['sometimes', 'boolean'],
            'alert_buy_target' => ['sometimes', 'boolean'],
            'alert_sell_target' => ['sometimes', 'boolean'],
            'alert_profit' => ['sometimes', 'boolean'],
        ] + array_map(
            // `sometimes`: the form always sends them, but a partial post shouldn't reset them.
            fn (array $n): array => ['sometimes', $n[0], "between:{$n[1]},{$n[2]}"],
            self::NUMBERS,
        ));

        // The game checkboxes only count when their form marker came along (a partial post leaves them alone).
        if ($request->has('games_form')) {
            $games = $request->validate(['games' => ['required', 'array', 'min:1'], 'games.*' => [Rule::in(Game::GAMES)]], [
                'games.required' => __('Keep at least one game on.'),
            ])['games'];
            $request->user()->setEnabledGames($games);
        }

        $request->user()->update($data);

        return back()->with('status', 'settings-updated');
    }

    public function storeToken(Request $request): View|RedirectResponse
    {
        return $this->issueToken($request, fn (string $token) => $this->page($request, $token));
    }

    public function destroyToken(Request $request, string $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->firstOrFail()->delete();

        return back()->with('status', 'token-revoked');
    }

    private function page(Request $request, ?string $newToken = null): View
    {
        $user = $request->user();

        return view('settings.edit', [
            'user' => $user,
            'timezones' => DateTimeZone::listIdentifiers(),
            'regions' => config('ledger.regions'),
            'limits' => self::NUMBERS,
            'tokens' => $user->tokens()->latest()->get(),
            'newToken' => $newToken,
        ]);
    }
}
