<?php

namespace Tests\Feature\Console;

use App\Domain\Images\Models\ImageBlob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UploadsImages;
use Tests\TestCase;

class SweepOrphanFilesTest extends TestCase
{
    use RefreshDatabase, UploadsImages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisk();
    }

    public function test_it_deletes_a_file_that_no_blob_points_at(): void
    {
        $disk = Storage::disk($this->imageDisk());

        $blob = ImageBlob::factory()->create();
        $disk->put($blob->path, 'referenced bytes');
        $disk->put('ab/cd/left-behind.webp', 'orphaned bytes');

        $this->artisan('images:sweep --hours=0')->assertSuccessful();

        $disk->assertMissing('ab/cd/left-behind.webp');
        $disk->assertExists($blob->path);
    }

    public function test_it_leaves_recent_files_alone(): void
    {
        $disk = Storage::disk($this->imageDisk());
        $disk->put('ab/cd/still-uploading.webp', 'bytes');

        // A file younger than the window may belong to a request that has not
        // committed its row yet.
        $this->artisan('images:sweep')->assertSuccessful();

        $disk->assertExists('ab/cd/still-uploading.webp');
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $disk = Storage::disk($this->imageDisk());
        $disk->put('ab/cd/left-behind.webp', 'bytes');

        $this->artisan('images:sweep --hours=0 --dry-run')
            ->expectsOutputToContain('would delete ab/cd/left-behind.webp')
            ->assertSuccessful();

        $disk->assertExists('ab/cd/left-behind.webp');
    }

    public function test_the_prefix_limits_the_walk(): void
    {
        $disk = Storage::disk($this->imageDisk());
        $disk->put('ab/cd/one.webp', 'bytes');
        $disk->put('ef/01/two.webp', 'bytes');

        $this->artisan('images:sweep --hours=0 --prefix=ab')->assertSuccessful();

        $disk->assertMissing('ab/cd/one.webp');
        $disk->assertExists('ef/01/two.webp');
    }
}
