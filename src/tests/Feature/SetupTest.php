<?php

namespace Tests\Feature;

use App\Livewire\SetupProgress;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SeedsUserRows;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;
    use SeedsUserRows;

    private function upload(User $user, string $status, ?string $error = null): void
    {
        $this->seedUpload($user, $status, ['error' => $error]);
    }

    public function test_setup_page_links_to_the_addon_and_the_upload_step(): void
    {
        config(['ledger.addon.url' => 'https://example.test/addon']);

        $this->actingAs(User::factory()->create())
            ->get('/setup')->assertOk()
            ->assertSee('https://example.test/addon', false)
            ->assertSee(route('uploads.index'), false)->assertSee('/reload');
    }

    public function test_progress_waits_then_reports_the_first_upload(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->upload($other, Upload::STATUS_OK);

        Livewire::actingAs($user)->test(SetupProgress::class)
            ->assertSee('Waiting for your first upload')->assertSeeHtml('wire:poll.visible.3s');

        $this->upload($user, Upload::STATUS_FAILED, 'Not a TSM file');

        Livewire::actingAs($user)->test(SetupProgress::class)->assertSee('Not a TSM file')->assertSee('Waiting');

        $this->upload($user, Upload::STATUS_OK);

        Livewire::actingAs($user)->test(SetupProgress::class)
            ->assertSee('First upload received')->assertDontSeeHtml('wire:poll');
    }
}
