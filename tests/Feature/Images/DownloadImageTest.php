<?php

namespace Tests\Feature\Images;

use App\Domain\Images\Models\ImageBlob;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UploadsImages;
use Tests\TestCase;

class DownloadImageTest extends TestCase
{
    use RefreshDatabase, UploadsImages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisk();
    }

    public function test_it_returns_the_original_bytes_before_compression_runs(): void
    {
        Queue::fake();

        $bytes = $this->pngBytes();
        $user = User::factory()->create();

        $id = $this->uploadAs($user, $this->pngUploadWith($bytes))->json('data.id');

        $response = $this->actingAs($user, 'sanctum')->get('/api/v1/images/'.$id.'/content');

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_it_returns_the_compressed_bytes_after_the_job_runs(): void
    {
        $user = User::factory()->create();

        // The queue is synchronous here, so the job runs inside the request.
        $id = $this->uploadAs($user, $this->image('photo.jpg', 800, 600))->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/images/'.$id.'/content')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
    }

    public function test_it_answers_304_for_a_matching_etag(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $id = $this->uploadAs($user, $this->pngUpload())->json('data.id');

        $first = $this->actingAs($user, 'sanctum')->get('/api/v1/images/'.$id.'/content');
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->actingAs($user, 'sanctum')
            ->withHeader('If-None-Match', $etag)
            ->get('/api/v1/images/'.$id.'/content')
            ->assertStatus(304);
    }

    public function test_the_cache_entry_is_marked_private(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $id = $this->uploadAs($user, $this->pngUpload())->json('data.id');

        $response = $this->actingAs($user, 'sanctum')->get('/api/v1/images/'.$id.'/content');

        // Images are private: shared caches must not keep a copy.
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_it_returns_404_when_the_file_disappeared_from_storage(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $id = $this->uploadAs($user, $this->pngUpload())->json('data.id');

        Storage::disk($this->imageDisk())->delete(ImageBlob::query()->sole()->path);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/images/'.$id.'/content')
            ->assertNotFound();
    }

    public function test_metadata_is_returned_for_an_own_image(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $id = $this->uploadAs($user, $this->pngUpload('holiday.png'))->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/images/'.$id)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'holiday.png')
            ->assertJsonPath('data.width', 60)
            ->assertJsonPath('data.height', 40);
    }

    private function pngUploadWith(string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('photo.png', $bytes);
    }
}
