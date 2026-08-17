<?php

namespace Tests\Feature\Jobs;

use App\Domain\Images\Jobs\PruneOrphanImageBlob;
use App\Domain\Images\Models\Image;
use App\Domain\Images\Models\ImageBlob;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UploadsImages;
use Tests\TestCase;

class PruneOrphanImageBlobTest extends TestCase
{
    use RefreshDatabase, UploadsImages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisk();
        Queue::fake();
    }

    public function test_it_removes_the_file_when_nothing_references_it(): void
    {
        $user = User::factory()->create();
        $id = $this->uploadAs($user, $this->pngUpload())->json('data.id');

        $blob = ImageBlob::query()->sole();
        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/images/'.$id)->assertNoContent();

        $this->runJob(new PruneOrphanImageBlob($blob->id));

        $this->assertDatabaseCount('image_blobs', 0);
        Storage::disk($this->imageDisk())->assertMissing($blob->path);
    }

    public function test_it_keeps_a_blob_that_is_still_referenced(): void
    {
        $user = User::factory()->create();
        $this->uploadAs($user, $this->pngUpload());

        $blob = ImageBlob::query()->sole();

        $this->runJob(new PruneOrphanImageBlob($blob->id));

        $this->assertDatabaseHas('image_blobs', ['id' => $blob->id]);
        Storage::disk($this->imageDisk())->assertExists($blob->path);
    }

    public function test_a_stale_counter_never_costs_someone_their_file(): void
    {
        $user = User::factory()->create();
        $this->uploadAs($user, $this->pngUpload());

        $blob = ImageBlob::query()->sole();

        // The counter drifted; the images rows are the source of truth.
        $blob->update(['references' => 0]);

        $this->runJob(new PruneOrphanImageBlob($blob->id));

        $this->assertDatabaseHas('image_blobs', ['id' => $blob->id]);
        Storage::disk($this->imageDisk())->assertExists($blob->path);
    }

    public function test_the_prune_command_queues_orphaned_blobs_only(): void
    {
        $orphan = ImageBlob::factory()->create(['references' => 0, 'updated_at' => now()->subDay()]);
        $referenced = ImageBlob::factory()->create(['references' => 1, 'updated_at' => now()->subDay()]);
        Image::factory()->create(['image_blob_id' => $referenced->id]);

        // A fresh blob is left alone: an upload may still be in flight.
        ImageBlob::factory()->create(['references' => 0, 'updated_at' => now()]);

        $this->artisan('images:prune')->assertSuccessful();

        Queue::assertPushed(PruneOrphanImageBlob::class, 1);
        Queue::assertPushed(
            fn (PruneOrphanImageBlob $job) => $job->blobId === $orphan->id,
        );
    }
}
