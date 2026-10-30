<?php

namespace App\Services;

use App\Models\FetchRun;
use App\Models\Upload;
use App\Models\User;
use App\Services\Blizzard\BlizzardClient;
use App\Support\Sql;
use App\Support\Utc;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Read-only views for the admin area, plus the audit trail for what admins do to accounts.
 * Admins see operational data (who signed up, whether uploads and price fetches work) but
 * never uploaded file contents, which are not stored.
 */
class AdminService
{
    public const PER_PAGE = 25;

    /** @return array<string, mixed> */
    public function overview(): array
    {
        // Each group of counts is one query (a boolean is 1 or 0 in both MariaDB and SQLite).
        $users = User::where('is_demo', false)
            ->selectRaw('count(*) as total, sum(email_verified_at is not null) as verified, sum(created_at >= ?) as new_7d', [Utc::sql(time() - 7 * 86400)])
            ->first();
        $uploads = Upload::where('received_at', '>=', Utc::sql(time() - 86400))
            ->selectRaw('sum(status = ?) as ok, sum(status = ?) as failed', [Upload::STATUS_OK, Upload::STATUS_FAILED])
            ->first();
        $items = DB::table('items')->selectRaw('count(*) as total, sum(name is null) as unnamed')->first();

        return [
            'users' => [
                'total' => (int) $users->total,
                'verified' => (int) $users->verified,
                'new_7d' => (int) $users->new_7d,
                'with_upload' => DB::table('uploads')->join('users', 'users.id', '=', 'uploads.user_id')
                    ->where('users.is_demo', false)->where('uploads.status', Upload::STATUS_OK)->distinct()->count('uploads.user_id'),
            ],
            'uploads' => ['ok_24h' => (int) $uploads->ok, 'failed_24h' => (int) $uploads->failed],
            'fetch' => $this->fetchHealth(),
            'backup' => app(BackupStatus::class)->read(),
            'failed_uploads' => $this->uploadQuery(true)->limit(8)->get()->map($this->uploadRow(...))->all(),
            'audit' => DB::table('admin_audit_logs')->orderByDesc('id')->limit(15)->get()->all(),
            'items' => ['total' => (int) $items->total, 'unnamed' => (int) $items->unnamed],
        ];
    }

    /**
     * One entry per configured region. $withCounts adds the 24 h run counts and the item_stats
     * total, which the admin page shows and health:check doesn't need.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchHealth(bool $withCounts = true): array
    {
        $day = Utc::sql(time() - 86400);
        $configured = app(BlizzardClient::class)->configured();
        $out = [];

        foreach (array_keys(config('ledger.regions')) as $region) {
            [$last, $good] = FetchRun::latestPair($region);
            $age = $good?->finished_at ? now()->getTimestamp() - $good->finished_at->getTimestamp() : null;

            $h = [
                'region' => $region,
                'configured' => $configured,
                'last' => $last,
                'good' => $good,
                'age' => $age,
                'stale' => $good === null || $age > config('ledger.prices.stale_hours') * 3600 || $last?->status === FetchRun::STATUS_FAILED,
            ];

            if ($withCounts) {
                $runs = FetchRun::where('region', $region)->where('started_at', '>=', $day)
                    ->selectRaw('count(*) as total, sum(status = ?) as failed', [FetchRun::STATUS_FAILED])->first();

                $h += [
                    'failed_24h' => (int) $runs->failed,
                    'runs_24h' => (int) $runs->total,
                    'stats' => DB::table('item_stats')->where('region', $region)->count(),
                ];
            }

            $out[] = $h;
        }

        return $out;
    }

    public function users(string $search = ''): LengthAwarePaginator
    {
        $uploads = fn (string $agg) => DB::table('uploads')->whereColumn('uploads.user_id', 'users.id')->selectRaw($agg);
        $q = trim($search);

        return DB::table('users')
            ->when($q !== '', function ($query) use ($q) {
                $like = Sql::like($q);
                $query->where(fn ($w) => $w->whereRaw('lower(users.email) like ?', [$like])->orWhereRaw('lower(users.name) like ?', [$like]));
            })
            ->select('users.id', 'users.name', 'users.email', 'users.email_verified_at', 'users.created_at', 'users.is_admin', 'users.is_demo')
            ->selectSub($uploads('count(*)')->where('status', Upload::STATUS_OK), 'uploads_ok')
            ->selectSub($uploads('count(*)')->where('status', Upload::STATUS_FAILED), 'uploads_failed')
            ->selectSub($uploads('max(received_at)')->where('status', Upload::STATUS_OK), 'last_upload')
            ->selectSub(DB::table('trades')->whereColumn('trades.user_id', 'users.id')->selectRaw('count(*)'), 'trades')
            ->orderByDesc('users.id')
            ->paginate(self::PER_PAGE)->withQueryString();
    }

    public function uploads(bool $failedOnly): LengthAwarePaginator
    {
        return $this->uploadQuery($failedOnly)->paginate(self::PER_PAGE)->withQueryString()
            ->through($this->uploadRow(...));
    }

    public function fetchRuns(): LengthAwarePaginator
    {
        return FetchRun::latest('started_at')->latest('id')->paginate(self::PER_PAGE);
    }

    public function audit(User $admin, string $action, ?User $target = null): void
    {
        DB::table('admin_audit_logs')->insert([
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'action' => $action,
            'target_user_id' => $target?->id,
            'target_email' => $target?->email,
            'ip' => request()->ip(),
            'created_at' => now(),
        ]);

        Log::notice('admin.'.$action, ['admin' => $admin->id, 'target' => $target?->id]);
    }

    private function uploadQuery(bool $failedOnly)
    {
        return DB::table('uploads')->join('users', 'users.id', '=', 'uploads.user_id')
            ->when($failedOnly, fn ($q) => $q->where('uploads.status', Upload::STATUS_FAILED))
            ->orderByDesc('uploads.received_at')->orderByDesc('uploads.id')
            ->select('uploads.id', 'uploads.user_id', 'users.email', 'uploads.source', 'uploads.status', 'uploads.bytes',
                'uploads.client_version', 'uploads.error', 'uploads.received_at', 'uploads.counts');
    }

    /** Adds a one-line summary of the stored counts. */
    private function uploadRow(object $r): object
    {
        $counts = is_string($r->counts) ? (json_decode($r->counts, true) ?: []) : [];
        $r->summary = $counts === [] ? null : Upload::summary($counts).', '.number_format($counts['skipped'] ?? 0).' skipped';

        return $r;
    }
}
