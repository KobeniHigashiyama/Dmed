<?php

namespace Tests\Feature\Jobs;

use App\Domain\Images\Jobs\CompressImageBlob;
use App\Domain\Images\Models\ImageBlob;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UploadsImages;
use Tests\TestCase;

class CompressImageBlobTest extends TestCase
{
    use RefreshDatabase, UploadsImages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisk();
        Queue::fake();
    }

    public function test_it_replaces_the_original_with_a_smaller_webp(): void
    {
        $user = User::factory()->create();
        $this->uploadAs($user, $this->image('photo.jpg', 1200, 900))->assertCreated();

        $blob = ImageBlob::query()->sole();
        $originalPath = $blob->path;

        $this->runJob(new CompressImageBlob($blob->id));

        $blob->refresh();

        $this->assertSame(ImageBlob::STATUS_READY, $blob->status);
        $this->assertSame('image/webp', $blob->mime);
        $this->assertStringEndsWith('.webp', $blob->path);
        $this->assertLessThan($blob->original_size_bytes, $blob->size_bytes);

        $disk = Storage::disk($this->imageDisk());
        $disk->assertExists($blob->path);
        // The original must not linger next to it, or there are no savings.
        $disk->assertMissing($originalPath);
    }

    public function test_the_hash_still_points_at_the_original_bytes(): void
    {
        $user = User::factory()->create();
        $bytes = $this->pngBytes(3, 400, 300);

        $this->uploadAs($user, UploadedFile::fake()->createWithContent('a.png', $bytes));

        $blob = ImageBlob::query()->sole();
        $this->runJob(new CompressImageBlob($blob->id));

        // The dedup key is computed before compression, so re-uploading the
        // same file has to land on the same blob.
        $this->assertSame(hash('sha256', $bytes), $blob->refresh()->hash);

        $this->uploadAs($user, UploadedFile::fake()->createWithContent('a-copy.png', $bytes))
            ->assertOk();

        $this->assertDatabaseCount('image_blobs', 1);
    }

    public function test_it_keeps_the_original_when_webp_is_not_smaller(): void
    {
        $user = User::factory()->create();
        $this->uploadAs($user, $this->pngUpload());

        $blob = ImageBlob::query()->sole();
        $originalPath = $blob->path;

        // Pretend the original is already perfectly compressed.
        $blob->update(['size_bytes' => 1]);

        $this->runJob(new CompressImageBlob($blob->id));

        $blob->refresh();

        $this->assertSame(ImageBlob::STATUS_READY, $blob->status);
        $this->assertSame($originalPath, $blob->path);
        $this->assertSame('image/png', $blob->mime);
        Storage::disk($this->imageDisk())->assertExists($originalPath);
    }

    public function test_it_is_idempotent_for_an_already_processed_blob(): void
    {
        $user = User::factory()->create();
        $this->uploadAs($user, $this->image('photo.jpg', 800, 600));

        $blob = ImageBlob::query()->sole();
        $this->runJob(new CompressImageBlob($blob->id));

        $processed = $blob->refresh()->only(['path', 'mime', 'size_bytes', 'status']);

        // A second run (a queue retry, a duplicate from a concurrent upload)
        // must neither re-encode nor corrupt the row.
        $this->runJob(new CompressImageBlob($blob->id));

        $this->assertSame($processed, $blob->refresh()->only(['path', 'mime', 'size_bytes', 'status']));
    }

    public function test_it_marks_the_blob_as_failed_when_the_file_is_gone(): void
    {
        $user = User::factory()->create();
        $this->uploadAs($user, $this->pngUpload());

        $blob = ImageBlob::query()->sole();
        Storage::disk($this->imageDisk())->delete($blob->path);

        $this->runJob(new CompressImageBlob($blob->id));

        $this->assertSame(ImageBlob::STATUS_FAILED, $blob->refresh()->status);
        $this->assertNotNull($blob->failure_reason);
    }
}
