<?php

namespace Tests\Feature\Images;

use App\Domain\Images\Jobs\PruneOrphanImageBlob;
use App\Domain\Images\Models\ImageBlob;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UploadsImages;
use Tests\TestCase;

class DeleteImageTest extends TestCase
{
    use RefreshDatabase, UploadsImages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisk();
        Queue::fake();
    }

    public function test_it_deletes_an_own_image_and_schedules_cleanup(): void
    {
        $user = User::factory()->create();
        $id = $this->uploadAs($user, $this->pngUpload())->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/images/'.$id)
            ->assertNoContent();

        $this->assertDatabaseCount('images', 0);
        $this->assertSame(0, ImageBlob::query()->sole()->references);

        Queue::assertPushed(PruneOrphanImageBlob::class);
    }

    public function test_deleting_one_copy_does_not_touch_the_other_owner(): void
    {
        $bytes = $this->pngBytes();
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->uploadAs($owner, UploadedFile::fake()->createWithContent('a.png', $bytes))->json('data.id');
        $theirs = $this->uploadAs($other, UploadedFile::fake()->createWithContent('b.png', $bytes))->json('data.id');

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/v1/images/'.$mine)->assertNoContent();

        $blob = ImageBlob::query()->sole();

        // The file is shared, so it has to survive one reference going away.
        $this->assertSame(1, $blob->references);
        Storage::disk($this->imageDisk())->assertExists($blob->path);

        $this->actingAs($other, 'sanctum')
            ->get('/api/v1/images/'.$theirs.'/content')
            ->assertOk();
    }

    public function test_a_deleted_image_disappears_from_the_listing(): void
    {
        $user = User::factory()->create();
        $id = $this->uploadAs($user, $this->pngUpload())->json('data.id');

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/images/'.$id)->assertNoContent();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/images')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/images/'.$id)->assertNotFound();
    }

    public function test_the_same_file_can_be_uploaded_again_after_deletion(): void
    {
        $bytes = $this->pngBytes();
        $user = User::factory()->create();

        $id = $this->uploadAs($user, UploadedFile::fake()->createWithContent('a.png', $bytes))->json('data.id');
        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/images/'.$id)->assertNoContent();

        $this->uploadAs($user, UploadedFile::fake()->createWithContent('a.png', $bytes))->assertCreated();

        $this->assertDatabaseCount('images', 1);
        $this->assertSame(1, ImageBlob::query()->sole()->references);
    }
}
