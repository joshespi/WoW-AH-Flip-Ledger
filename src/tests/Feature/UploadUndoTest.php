<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\User;
use App\Services\IngestService;
use App\Services\UploadService;
use App\Support\TsmSpan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\TsmFile;
use Tests\TestCase;

class UploadUndoTest extends TestCase
{
    use RefreshDatabase;

    /** One retail sale, the file most tests upload. */
    private const SALE = [['i:100', 5, 200, TsmFile::T0, 'Auction']];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function upload(User $user, array $sales, string $game = 'retail'): Upload
    {
        $file = UploadedFile::fake()->createWithContent('TradeSkillMaster.lua', TsmFile::make(sales: $sales));
        // These tests misfile files on purpose, so they confirm past UploadGameCheck.
        $this->actingAs($user)->post('/uploads', ['file' => $file, 'game' => $game, 'game_confirmed' => '1'])->assertSessionHas('status', 'upload-ok');

        return Upload::ownedBy($user)->where('game', $game)->newest()->firstOrFail();
    }

    private function sales(User $user, string $game = 'retail'): int
    {
        return DB::table('tsm_sales')->ownedBy($user)->inGame($game)->count();
    }

    public function test_undo_removes_only_that_uploads_rows_and_the_history_entry(): void
    {
        $user = $this->user;
        $this->upload($user, self::SALE);
        $wrong = $this->upload($user, [['i:200', 3, 300, TsmFile::T0, 'Auction']], 'forever');

        $this->actingAs($user)->delete("/uploads/{$wrong->id}")
            ->assertRedirect('/uploads')->assertSessionHas('status', 'upload-undone');

        $this->assertSame(0, $this->sales($user, 'forever'));
        $this->assertSame(1, $this->sales($user));
        $this->assertDatabaseMissing('uploads', ['id' => $wrong->id]);
    }

    public function test_the_option_clears_inventory_and_the_span_always_follows_the_remaining_tsm_rows(): void
    {
        $user = $this->user;
        $up = $this->upload($user, self::SALE);
        DB::table('inventory')->insert(['user_id' => $user->id, 'game' => 'retail', 'item_id' => 100, 'qty' => 4, 'taken_at' => now()]);
        DB::table('tsm_spans')->where('user_id', $user->id)->update(['from' => '2026-01-01 00:00:00', 'to' => '2026-02-01 00:00:00']);

        $this->actingAs($user)->delete("/uploads/{$up->id}", ['clear_inventory' => '1'])->assertSessionHas('undone.inventory', true);

        $this->assertSame(0, DB::table('inventory')->where('user_id', $user->id)->count());
        $this->assertNull(TsmSpan::get($user, 'retail'), 'no TSM rows left');
    }

    public function test_inventory_is_kept_without_the_reset_option(): void
    {
        $user = $this->user;
        $up = $this->upload($user, self::SALE);
        DB::table('inventory')->insert(['user_id' => $user->id, 'game' => 'retail', 'item_id' => 100, 'qty' => 4, 'taken_at' => now()]);

        $this->actingAs($user)->delete("/uploads/{$up->id}");

        $this->assertSame(1, DB::table('inventory')->where('user_id', $user->id)->count());
    }

    public function test_undo_is_refused_while_an_ingest_holds_the_lock(): void
    {
        $upload = $this->upload($this->user, self::SALE);
        $lock = IngestService::lock($this->user);
        $lock->get();

        try {
            app(UploadService::class)->undo($this->user, $upload->id, false);
            $this->fail('undo ran while the ingest lock was held');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('still being processed', $e->getMessage());
        } finally {
            $lock->release();
        }

        $this->assertDatabaseHas('uploads', ['id' => $upload->id]);
    }

    public function test_only_the_newest_upload_of_a_game_can_be_undone(): void
    {
        $user = $this->user;
        $first = $this->upload($user, self::SALE);
        $this->upload($user, [['i:100', 5, 200, TsmFile::T0, 'Auction'], ['i:101', 1, 50, TsmFile::T0 + 60, 'Auction']]);

        $this->actingAs($user)->from('/uploads')->delete("/uploads/{$first->id}")->assertSessionHasErrors('undo');

        $this->assertSame(2, $this->sales($user));
        $this->assertDatabaseHas('uploads', ['id' => $first->id]);
    }

    public function test_an_upload_whose_buys_are_already_in_trades_is_refused(): void
    {
        $user = $this->user;
        $up = $this->upload($user, self::SALE);
        DB::table('tsm_buy_candidates')->insert([
            'user_id' => $user->id, 'game' => 'retail', 'tsm_ref' => str_repeat('a', 40), 'realm' => 'R', 'item_id' => 100,
            'qty' => 1, 'cost_copper' => 10, 'at' => now(), 'status' => 'accepted', 'origin' => 'tsm', 'upload_id' => $up->id,
        ]);

        $this->actingAs($user)->from('/uploads')->delete("/uploads/{$up->id}")->assertSessionHasErrors('undo');

        $this->assertSame(1, $this->sales($user));
    }

    public function test_the_button_shows_only_on_the_undoable_row_and_other_users_get_404(): void
    {
        $alice = $this->user;
        $bob = User::factory()->create();
        $up = $this->upload($alice, self::SALE);

        $this->actingAs($alice)->get('/uploads')->assertSee('Undo upload');
        $this->actingAs($bob)->delete("/uploads/{$up->id}")->assertNotFound();
        $this->assertSame(1, $this->sales($alice));
    }

    public function test_a_reupload_after_undo_brings_the_rows_back(): void
    {
        $user = $this->user;
        $sales = self::SALE;
        $up = $this->upload($user, $sales);
        $this->actingAs($user)->delete("/uploads/{$up->id}");
        $this->assertSame(0, $this->sales($user));

        $this->upload($user, $sales);

        $this->assertSame(1, $this->sales($user));
    }
}
