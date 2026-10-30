<?php

namespace Tests\Feature;

use App\Mail\HealthAlertMail;
use App\Models\FetchRun;
use App\Models\User;
use App\Services\BackupStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Support\SeedsMarketData;
use Tests\Support\TempFiles;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMarketData;
    use TempFiles;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        config(['services.blizzard.client_id' => 'id', 'services.blizzard.client_secret' => 'secret', 'ledger.backup.status_file' => null]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email' => 'boss@example.com']);
    }

    private function sent(): int
    {
        return Mail::sent(HealthAlertMail::class)->count();
    }

    private function check(): void
    {
        $this->artisan('health:check')->assertSuccessful();
    }

    public function test_a_failing_fetch_is_mailed_once_reminded_later_and_announced_when_fixed(): void
    {
        $this->admin();
        $this->seedFetchRun('ok', time() - 6 * 3600, time() - 6 * 3600);
        $this->seedFetchRun('failed', time() - 60);
        FetchRun::where('status', 'failed')->update(['error' => 'Blizzard said no']);

        $this->check();

        Mail::assertSent(HealthAlertMail::class, fn ($m) => $m->hasTo('boss@example.com') && str_contains($m->problems['Price fetch (US)'], 'Blizzard said no'));
        $this->assertStringContainsString('Problem: Price fetch (US)', Mail::sent(HealthAlertMail::class)->first()->envelope()->subject);

        $this->check();
        $this->assertSame(1, $this->sent(), 'the same problem is not mailed again right away');

        // 13 hours later, still broken.
        Cache::put('health:notified:'.md5('Price fetch (US)'), time() - 13 * 3600);
        $this->check();
        $this->assertSame(2, $this->sent());

        $this->seedFetchRun('ok', time() - 10, time() - 100);
        $this->check();
        $this->assertSame(3, $this->sent());
        Mail::assertSent(HealthAlertMail::class, fn ($m) => $m->recovered === ['Price fetch (US)'] && $m->problems === []);

        $this->check();
        $this->assertSame(3, $this->sent(), 'nothing more once it is healthy');
    }

    public function test_a_stale_fetch_without_a_failure_is_reported_too(): void
    {
        $this->admin();
        $this->seedFetchRun('ok', time() - 6 * 3600, time() - 6 * 3600);

        $this->check();

        Mail::assertSent(HealthAlertMail::class, fn ($m) => str_contains($m->problems['Price fetch (US)'], 'no good fetch for'));
    }

    public function test_nothing_is_reported_for_what_is_not_set_up(): void
    {
        $this->admin();

        $this->check();                      // configured, but no run yet

        config(['services.blizzard.client_id' => null, 'services.blizzard.client_secret' => null]);
        $this->seedFetchRun('failed', time() - 60);
        $this->check();                      // runs exist, but no credentials

        $this->assertSame(0, $this->sent());
    }

    public function test_a_healthy_system_is_quiet(): void
    {
        $this->admin();
        $this->seedFetchRun('ok', time() - 300, time() - 600);

        $this->check();

        $this->assertSame(0, $this->sent());
    }

    public function test_stale_backups_are_reported_and_fresh_ones_are_not(): void
    {
        $this->admin();
        $file = $this->tempFile((string) (time() - 40 * 3600));
        config(['ledger.backup.status_file' => $file]);

        $this->check();
        Mail::assertSent(HealthAlertMail::class, fn ($m) => str_contains($m->problems['Database backup'], 'hours ago'));

        file_put_contents($file, (string) (time() - 3600));
        $this->check();
        Mail::assertSent(HealthAlertMail::class, fn ($m) => $m->recovered === ['Database backup']);
        $this->assertSame(2, $this->sent());
    }

    public function test_a_missing_backup_marker_is_a_problem(): void
    {
        $this->admin();
        config(['ledger.backup.status_file' => sys_get_temp_dir().'/wfl-no-such-marker']);

        $this->check();

        Mail::assertSent(HealthAlertMail::class, fn ($m) => str_contains($m->problems['Database backup'], 'no successful backup'));
    }

    public function test_problems_with_nobody_to_tell_fail_loudly(): void
    {
        config(['ledger.backup.status_file' => sys_get_temp_dir().'/wfl-no-such-marker']);

        $this->artisan('health:check')->expectsOutputToContain('no admin to tell')->assertFailed();
        $this->assertSame(0, $this->sent());

        // Once someone is an admin, it is reported (state was not recorded as sent).
        $this->admin();
        $this->check();
        $this->assertSame(1, $this->sent());
    }

    public function test_unverified_and_non_admin_users_are_not_mailed(): void
    {
        User::factory()->create(['email' => 'user@example.com']);
        User::factory()->unverified()->admin()->create(['email' => 'new@example.com']);
        $this->admin();
        config(['ledger.backup.status_file' => sys_get_temp_dir().'/wfl-no-such-marker']);

        $this->check();

        Mail::assertSent(HealthAlertMail::class, fn ($m) => $m->hasTo('boss@example.com') && ! $m->hasTo('user@example.com') && ! $m->hasTo('new@example.com'));
    }

    public function test_a_mail_failure_keeps_the_state_so_it_retries(): void
    {
        $this->admin();
        config(['ledger.backup.status_file' => sys_get_temp_dir().'/wfl-no-such-marker']);

        $fake = Mail::getFacadeRoot();
        Mail::swap(new class
        {
            public function to(): never
            {
                throw new \RuntimeException('smtp down');
            }
        });

        $this->artisan('health:check')->assertFailed();
        Mail::swap($fake);

        $this->check();
        $this->assertSame(1, $this->sent());
    }

    public function test_backup_status_reads_the_marker(): void
    {
        $status = new BackupStatus;

        config(['ledger.backup.status_file' => null]);
        $this->assertFalse($status->read()['configured']);

        config(['ledger.backup.status_file' => $this->tempFile((string) (time() - 2 * 3600))]);
        $r = $status->read();
        $this->assertSame([true, 2, false], [$r['configured'], $r['age_hours'], $r['stale']]);

        config(['ledger.backup.status_file' => $this->tempFile('garbage')]);
        $this->assertTrue($status->read()['stale']);
    }

    public function test_admin_overview_shows_the_backup_state_only_when_configured(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin')->assertDontSee('Database backup');

        config(['ledger.backup.status_file' => $this->tempFile((string) (time() - 3600))]);
        $this->get('/admin')->assertSee('Database backup')->assertSee('Last good dump');

        config(['ledger.backup.status_file' => $this->tempFile((string) (time() - 50 * 3600))]);
        $this->get('/admin')->assertSee('Stale or missing');
    }

    public function test_it_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('health:check')->assertSuccessful();
    }
}
