<?php

namespace App\Models;

use App\Support\Game;
use Database\Factories\UserFactory;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'timezone', 'region', 'target_margin_pct', 'stale_minutes', 'market_share_pct', 'alerts_enabled', 'alert_buy_target', 'alert_sell_target', 'alert_profit'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Same as the column defaults, so a user created in this request (registration, factories)
     * has them before it is reloaded from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'UTC',
        'region' => 'us',
        'target_margin_pct' => 15,
        'stale_minutes' => 30,
        'market_share_pct' => 5,
        'game_view' => Game::RETAIL,
        'enabled_games' => Game::RETAIL,
        'is_demo' => false,
        'is_admin' => false,
        'alerts_enabled' => false,
        'alert_buy_target' => true,
        'alert_sell_target' => true,
        'alert_profit' => false,
        'scoreboard_opt_in' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'target_margin_pct' => 'float',
            'stale_minutes' => 'integer',
            'market_share_pct' => 'float',
            'is_demo' => 'boolean',
            'is_admin' => 'boolean',
            'alerts_enabled' => 'boolean',
            'alert_buy_target' => 'boolean',
            'alert_sell_target' => 'boolean',
            'alert_profit' => 'boolean',
            'scoreboard_opt_in' => 'boolean',
        ];
    }

    public const MAX_TOKENS = 10;

    /** This user is the one an admin is currently viewing as, in this session. */
    public function isBeingImpersonated(): bool
    {
        return request()->hasSession() && request()->session()->has('impersonator_id') && (int) Auth::id() === (int) $this->id;
    }

    /** The demo account, or this user being viewed by an impersonating admin in the current session. */
    public function readOnly(): bool
    {
        return $this->is_demo || $this->isBeingImpersonated();
    }

    /** The shared demo account. */
    public function scopeDemo(Builder $query): void
    {
        $query->where('is_demo', true);
    }

    /** Every account but the demo. */
    public function scopeReal(Builder $query): void
    {
        $query->where('is_demo', false);
    }

    public function scopeVerified(Builder $query): void
    {
        $query->whereNotNull('email_verified_at');
    }

    /** Admins who can be mailed. */
    public function scopeAdmins(Builder $query): void
    {
        $query->where('is_admin', true)->verified();
    }

    /** Accounts that count in public numbers (/stats, /scoreboard): verified and not the demo. */
    public function scopeCounted(Builder $query): void
    {
        $query->real()->verified();
    }

    /** Counted accounts listed on the scoreboard right now: opted in, with a nickname. */
    public function scopeOnScoreboard(Builder $query): void
    {
        $query->counted()->where('scoreboard_opt_in', true)->whereNotNull('scoreboard_nickname');
    }

    /**
     * A new upload-only API token; the plain text is returned once and not stored.
     *
     * @throws \InvalidArgumentException at the token limit
     */
    public function issueIngestToken(string $name): string
    {
        if ($this->tokens()->count() >= self::MAX_TOKENS) {
            throw new \InvalidArgumentException('You can have up to '.self::MAX_TOKENS.' tokens. Revoke one first.');
        }

        return $this->createToken($name, ['ingest'])->plainTextToken;
    }

    /**
     * Refuses a write in a read-only session. Livewire components call it from one place
     * (ReportsErrors::attempt), because ReadOnlySession lets Livewire's update endpoint through
     * (its POSTs carry reads too). The component shows the message.
     *
     * @throws \InvalidArgumentException on a read-only session
     */
    public function assertWritable(): void
    {
        if ($this->readOnly()) {
            throw new \InvalidArgumentException($this->is_demo
                ? 'The demo is read-only. Create an account to try this.'
                : 'You are viewing this account as an admin, so it is read-only.');
        }
    }

    protected static function booted(): void
    {
        // Sanctum tokens are polymorphic with no foreign key, so unlike our own tables they
        // don't cascade when the user is deleted.
        static::deleting(fn (self $user) => $user->tokens()->delete());
    }

    /**
     * The games this user plays, in display order; the screens show only these. Never empty.
     *
     * @return list<string>
     */
    public function enabledGames(): array
    {
        return Game::normalize(explode(',', (string) $this->enabled_games));
    }

    public function playsSeveral(): bool
    {
        return count($this->enabledGames()) > 1;
    }

    /** @param  list<string>  $games */
    public function setEnabledGames(array $games): void
    {
        $this->forceFill(['enabled_games' => implode(',', Game::normalize($games))])->saveQuietly();
    }

    /** Data arriving for a game means the user plays it. */
    public function enableGame(string $game): void
    {
        if (! in_array($game, $this->enabledGames(), true)) {
            $this->setEnabledGames([...$this->enabledGames(), $game]);
        }
    }

    /**
     * Which game the screens show: one enabled game, or `all` when more than one is enabled. A
     * read-only session (demo, impersonation) cannot save the choice, so the session holds it first.
     */
    public function gameView(): string
    {
        $view = session('game_view', $this->game_view);
        $enabled = $this->enabledGames();

        return match (true) {
            $view === Game::ALL && $this->playsSeveral() => Game::ALL,
            in_array($view, $enabled, true) => $view,
            default => $enabled[0],
        };
    }

    /** Saves the view; a read-only session keeps it in the session only. The caller validates $view. */
    public function setGameView(string $view): void
    {
        session(['game_view' => $view]);

        if (! $this->readOnly()) {
            $this->forceFill(['game_view' => $view])->saveQuietly();
        }
    }

    /** The one game a page that cannot mix games works on: the view, or the first enabled game when showing all. */
    public function activeGame(): string
    {
        $view = $this->gameView();

        return $view === Game::ALL ? $this->enabledGames()[0] : $view;
    }

    public function viewsAll(): bool
    {
        return $this->gameView() === Game::ALL;
    }

    /** A stored UTC time (a date, a database string or epoch seconds) in this user's timezone, for display. */
    public function local(DateTimeInterface|int|string|null $time): ?Carbon
    {
        return match (true) {
            $time === null => null,
            is_int($time) => Carbon::createFromTimestamp($time, $this->timezone),
            is_string($time) => Carbon::parse($time, 'UTC')->setTimezone($this->timezone),
            default => Carbon::instance($time)->setTimezone($this->timezone),
        };
    }

    /** A time as a `datetime-local` form value ("2026-10-12T10:51") in this user's timezone; now by default. */
    public function inputTime(DateTimeInterface|int|string|null $time = null): string
    {
        return $this->local($time ?? time())->format('Y-m-d\TH:i');
    }

    /**
     * A `datetime-local` form value, read in this user's timezone, as epoch seconds.
     *
     * @throws \InvalidArgumentException
     */
    public function parseInputTime(string $value): int
    {
        // PHP's own parser would also take "now" or "+1 week".
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/D', $value)) {
            try {
                return (new DateTimeImmutable($value, new DateTimeZone($this->timezone)))->getTimestamp();
            } catch (\Throwable) {
                // falls through to the same message
            }
        }

        throw new \InvalidArgumentException('Enter a valid date and time.');
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }
}
