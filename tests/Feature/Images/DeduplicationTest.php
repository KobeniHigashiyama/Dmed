<?php

namespace Tests\Feature\Images;

use App\Domain\Images\Jobs\CompressImageBlob;
use App\Domain\Images\Models\Image;
use App\Domain\Images\Models\ImageBlob;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UploadsImages;
use Tests\TestCase;

class DeduplicationTest extends TestCase
{
    use RefreshDatabase, UploadsImages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisk();
        Queue::fake();
    }

    public function test_the_same_file_from_different_users_is_stored_once(): void
    {
        $bytes = $this->pngBytes();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->uploadAs($first, UploadedFile::fake()->createWithContent('mine.png', $bytes))->assertCreated();
        $this->uploadAs($second, UploadedFile::fake()->createWithContent('theirs.png', $bytes))->assertCreated();

        // Two ownership records, a single file on disk.
        $this->assertDatabaseCount('images', 2);
        $this->assertDatabaseCount('image_blobs', 1);
        $this->assertCount(1, Storage::disk($this->imageDisk())->allFiles());

        $this->assertSame(2, ImageBlob::query()->sole()->references);

        // And such a file is compressed exactly once.
        Queue::assertPushed(CompressImageBlob::class, 1);
    }

    public function test_reuploading_the_same_file_is_idempotent_for_one_user(): void
    {
        $bytes = $this->pngBytes();
        $user = User::factory()->create();

        $first = $this->uploadAs($user, UploadedFile::fake()->createWithContent('photo.png', $bytes));
        $second = $this->uploadAs($user, UploadedFile::fake()->createWithContent('photo-copy.png', $bytes));

        $first->assertCreated();
        // The repeat creates nothing, hence 200 rather than 201.
        $second->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $this->assertDatabaseCount('images', 1);
        $this->assertSame(1, ImageBlob::query()->sole()->references);
    }

    public function test_different_files_are_stored_separately(): void
    {
        $user = User::factory()->create();

        $this->uploadAs($user, UploadedFile::fake()->createWithContent('one.png', $this->pngBytes(1)))->assertCreated();
        $this->uploadAs($user, UploadedFile::fake()->createWithContent('two.png', $this->pngBytes(2)))->assertCreated();

        $this->assertDatabaseCount('image_blobs', 2);
        $this->assertCount(2, Storage::disk($this->imageDisk())->allFiles());
    }

    public function test_the_checksum_is_the_hash_of_the_original_bytes(): void
    {
        $bytes = $this->pngBytes();
        $user = User::factory()->create();

        $response = $this->uploadAs($user, UploadedFile::fake()->createWithContent('photo.png', $bytes));

        $response->assertCreated()->assertJsonPath('data.checksum', hash('sha256', $bytes));

        $this->assertSame(
            hash('sha256', $bytes),
            Image::query()->sole()->blob->hash,
        );
    }
}
