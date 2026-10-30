<?php

namespace App\Providers;

use App\Console\Dev\PricesCalibrate;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // N+1s throw outside production, so they get caught in dev and tests.
        Model::preventLazyLoading(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // The raw-table twin of the OwnedByUser model scope: `DB::table('tsm_sales')->ownedBy($user)`.
        // `$column` is qualified when the query joins ('trades.user_id').
        QueryBuilder::macro('ownedBy', function (User|int $user, string $column = 'user_id') {
            /** @var QueryBuilder $this */
            return $this->where($column, $user instanceof User ? $user->id : $user);
        });

        $this->rateLimiters();

        // Dev-only tooling that reads TSM's own data (never available in production).
        if ($this->app->runningInConsole() && $this->app->environment(['local', 'testing'])) {
            $this->commands([PricesCalibrate::class]);
        }
    }

    private function rateLimiters(): void
    {
        RateLimiter::for('register', fn (Request $r) => Limit::perHour(10)->by($r->ip()));

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));

        // Each reset request sends an email, so an IP gets a handful an hour.
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perHour(10)->by($r->ip()));

        // Laravel prefixes each key with the limiter name, so every limiter is its own bucket.
        $token = fn (Request $r) => $r->user()?->currentAccessToken()?->id ?? $r->ip();
        $user = fn (Request $r) => $r->user()?->id ?? $r->ip();

        // After auth: one uploader token, one budget. The pre-auth per-IP limit is the
        // LimitIngestByIp middleware.
        RateLimiter::for('ingest', fn (Request $r) => Limit::perHour(config('ledger.ingest.per_token_per_hour'))->by($token($r)));

        RateLimiter::for('addon-feed', fn (Request $r) => Limit::perHour(config('ledger.addon.per_token_per_hour'))->by($token($r)));

        RateLimiter::for('manual-upload', fn (Request $r) => Limit::perHour(config('ledger.ingest.manual_per_user_per_hour'))->by($user($r)));

        RateLimiter::for('trades-import', fn (Request $r) => Limit::perHour(config('ledger.ingest.trades_import_per_user_per_hour'))->by($user($r)));
    }
}
