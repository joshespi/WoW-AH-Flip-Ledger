<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IngestService;
use App\Support\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\AddonFile;
use Tests\Support\IngestsFiles;
use Tests\Support\TsmFile;
use Tests\TestCase;

class UploadGameCheckTest extends TestCase
{
    use IngestsFiles;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->user->setEnabledGames([Game::RETAIL, Game::FOREVER]);
    }

    private function tsmFile(string $realm, int $at = self::T + self::DAY): string
    {
        return TsmFile::make(sales: [['i:2318', 62, 24, $at, 'Auction']], buys: [['i:6350', 1, 300, $at, 'Auction']], realm: $realm);
    }

    /** Stored through the uploader, which never guesses from realms. */
    private function store(string $realm, string $game): void
    {
        $upload = app(IngestService::class)->ingest($this->user, $this->tsmFile($realm, self::T), 'uploader', self::T + self::DAY, game: $game);

        $this->assertSame('ok', $upload->status);
    }

    private function upload(string $content, string $game, bool $confirmed = false): TestResponse
    {
        return $this->actingAs($this->user)->from('/uploads')->post('/uploads', [
            'file' => UploadedFile::fake()->createWithContent('TradeSkillMaster.lua', $content),
            'game' => $game,
        ] + ($confirmed ? ['game_confirmed' => '1'] : []));
    }

    private function retailRows(): int
    {
        return $this->rowsOf('tsm_sales', game: Game::RETAIL) + $this->rowsOf('tsm_buy_candidates', game: Game::RETAIL);
    }

    public function test_a_tsm_file_whose_realms_are_another_games_is_refused_until_confirmed(): void
    {
        $this->store('Whisperwind', Game::RETAIL);
        $this->store('Normal', Game::FOREVER);
        $before = $this->retailRows();

        $this->upload($this->tsmFile('Normal'), Game::RETAIL)
            ->assertRedirect('/uploads')
            ->assertSessionHasErrors(['game' => 'This file\'s realms (Normal) are in your Forever data, not Retail. Upload it as Forever, or tick "Upload anyway" if it really is from Retail.'])
            ->assertSessionHas('game_check', true);

        $this->assertSame($before, $this->retailRows(), 'nothing is stored');
        $this->assertDatabaseHas('uploads', ['user_id' => $this->user->id, 'source' => 'manual', 'status' => 'failed', 'game' => Game::RETAIL]);

        $this->upload($this->tsmFile('Normal'), Game::RETAIL, confirmed: true)->assertSessionHas('status', 'upload-ok');
        $this->assertGreaterThan($before, $this->retailRows());
    }

    public function test_a_tsm_file_with_no_realm_in_a_game_that_has_data_is_questioned(): void
    {
        // The misfiled Forever file is the first: its realms are known nowhere yet.
        $this->store('Whisperwind', Game::RETAIL);

        $this->upload($this->tsmFile('Normal'), Game::RETAIL)
            ->assertSessionHasErrors(['game' => 'None of this file\'s realms (Normal) are in your Retail data. Check which game folder it came from, or tick "Upload anyway" if it really is from Retail.'])
            ->assertSessionHas('game_check', true);

        $this->upload($this->tsmFile('Normal'), Game::FOREVER)->assertSessionHas('status', 'upload-ok');
    }

    public function test_a_known_realm_or_a_first_upload_passes(): void
    {
        $this->upload($this->tsmFile('Whisperwind'), Game::RETAIL)->assertSessionHas('status', 'upload-ok');

        $this->upload($this->tsmFile('Whisperwind', self::T + 2 * self::DAY), Game::RETAIL)->assertSessionHas('status', 'upload-ok');
    }

    public function test_one_known_realm_is_enough(): void
    {
        $this->store('Whisperwind', Game::RETAIL);
        $this->store('Normal', Game::FOREVER);

        // A realm in both games counts as being in the chosen one.
        $this->store('Normal', Game::RETAIL);

        $this->upload($this->tsmFile('Normal', self::T + 2 * self::DAY), Game::RETAIL)->assertSessionHas('status', 'upload-ok');
    }

    public function test_addon_and_tsm_spellings_of_a_realm_match(): void
    {
        // The addon stores GetNormalizedRealmName ("Area52"), TSM the display name ("Area 52").
        $addon = AddonFile::make(['sale|'.self::T.'|Area52|2318|1|1000|50']);
        $this->assertSame('ok', app(IngestService::class)->ingest($this->user, $addon, 'uploader', self::T + self::DAY, game: Game::RETAIL)->status);

        $this->upload($this->tsmFile('Area 52'), Game::RETAIL)->assertSessionHas('status', 'upload-ok');
    }

    public function test_a_game_that_is_switched_off_points_to_settings(): void
    {
        $this->store('Whisperwind', Game::RETAIL);
        $this->store('Normal', Game::FOREVER);
        $this->user->setEnabledGames([Game::RETAIL]);

        $this->upload($this->tsmFile('Normal'), Game::RETAIL)
            ->assertSessionHasErrors(['game' => 'This file\'s realms (Normal) are in your Forever data, not Retail. Upload it as Forever, or tick "Upload anyway" if it really is from Retail. Turn Forever on in Settings first.']);

        $this->upload($this->tsmFile('Hyjal'), Game::RETAIL)
            ->assertSessionHasErrors(['game' => 'None of this file\'s realms (Hyjal) are in your Retail data. Check which game folder it came from, or tick "Upload anyway" if it really is from Retail. If it is from another game, turn that game on in Settings first.']);
    }

    public function test_the_uploader_is_not_second_guessed_on_realms(): void
    {
        $this->store('Whisperwind', Game::RETAIL);
        $this->store('Normal', Game::FOREVER);

        $upload = app(IngestService::class)->ingest($this->user, $this->tsmFile('Normal'), 'uploader', self::T + 2 * self::DAY, game: Game::RETAIL);

        $this->assertSame('ok', $upload->status);
    }

    public function test_an_addon_file_from_another_client_is_always_refused(): void
    {
        $forever = AddonFile::make(['sale|'.(self::T + 100).'|CAMWOWUS1Realm7PvE|2318|62|1488|74'], interface: 16001);
        $message = 'This file was saved by the Forever client but was uploaded as Retail. Upload it as Forever.';

        $this->upload($forever, Game::RETAIL)
            ->assertSessionHasErrors(['game' => $message])
            ->assertSessionHas('game_check', false);

        // Confirming does not override the file's own stamp.
        $this->upload($forever, Game::RETAIL, confirmed: true)->assertSessionHasErrors('game');
        $this->assertSame(0, $this->rowsOf('tsm_sales'));

        // The uploader gets a 422, which it drops until the file changes.
        $token = $this->user->issueIngestToken('test');
        $this->postIngest($forever, $token, ['X-Wow-Game' => 'retail'])->assertStatus(422)->assertJsonPath('message', $message);
        $this->postIngest($forever, $token, ['X-Wow-Game' => 'forever'])->assertOk();

        $this->assertSame(1, $this->rowsOf('tsm_sales', game: Game::FOREVER));
    }

    public function test_an_addon_file_from_its_own_client_skips_the_realm_guess(): void
    {
        $this->store('Whisperwind', Game::RETAIL);

        $this->upload(AddonFile::make(['sale|'.(self::T + 100).'|Area52|238371|1|1000|50'], interface: 120105), Game::RETAIL)
            ->assertSessionHas('status', 'upload-ok');
    }

    public function test_the_form_offers_upload_anyway_only_for_a_guess(): void
    {
        $this->store('Whisperwind', Game::RETAIL);

        $this->upload($this->tsmFile('Normal'), Game::RETAIL);
        $this->actingAs($this->user)->get('/uploads')->assertSee('Upload anyway (choose the file again)')->assertSee('None of this file');

        $this->upload(AddonFile::make([], interface: 16001), Game::RETAIL);
        $this->actingAs($this->user)->get('/uploads')->assertSee('saved by the Forever client')->assertDontSee('Upload anyway (choose the file again)');
    }
}
