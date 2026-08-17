<?php

namespace Tests\Feature\Images;

use App\Domain\Images\Jobs\CompressImageBlob;
use App\Domain\Images\Models\ImageBlob;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UploadsImages;
use Tests\TestCase;

class UploadImageTest extends TestCase
{
    use RefreshDatabase, UploadsImages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisk();
        Queue::fake();
    }

    public function test_it_stores_a_png_and_queues_compression(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/v1/images', ['image' => $this->image('cat.png')], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'cat.png')
            ->assertJsonPath('data.status', ImageBlob::STATUS_PENDING)
            ->assertJsonStructure(['data' => [
                'id', 'name', 'mime', 'size', 'width', 'height', 'status', 'checksum',
                'uploaded_at', 'links' => ['self', 'content'],
            ]]);

        $blob = ImageBlob::query()->sole();

        $this->assertSame('image/png', $blob->mime);
        $this->assertSame(1, $blob->references);
        Storage::disk($this->imageDisk())->assertExists($blob->path);
        Queue::assertPushed(CompressImageBlob::class);
    }

    public function test_a_new_upload_writes_the_file_exactly_once(): void
    {
        // Every extra write is a paid request to the object store, and at 100k
        // uploads a day a duplicate PUT is 100k wasted requests.
        $spy = Mockery::mock(Storage::disk($this->imageDisk()))->makePartial();
        Storage::set($this->imageDisk(), $spy);

        $this->uploadAs(User::factory()->create(), $this->pngUpload())->assertCreated();

        $spy->shouldHaveReceived('putFileAs')->once();
    }

    public function test_it_stores_a_jpeg(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/images', ['image' => $this->image('photo.jpg')], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame('image/jpeg', ImageBlob::query()->sole()->mime);
    }

    public function test_it_shards_storage_paths_by_hash(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/images', ['image' => $this->image()], ['Accept' => 'application/json'])
            ->assertCreated();

        $blob = ImageBlob::query()->sole();

        // A flat directory holding millions of files is not viable; the path
        // has to fan out into subdirectories derived from the hash.
        $this->assertSame(
            sprintf('%s/%s/%s.png', substr($blob->hash, 0, 2), substr($blob->hash, 2, 2), $blob->hash),
            $blob->path,
        );
    }

    /**
     * @return array<string, array{0: UploadedFile}>
     */
    public static function rejectedFiles(): array
    {
        return [
            'gif' => [UploadedFile::fake()->create('animation.gif', 10, 'image/gif')],
            'svg' => [UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')],
            'pdf' => [UploadedFile::fake()->create('document.pdf', 10, 'application/pdf')],
            'text' => [UploadedFile::fake()->createWithContent('notes.txt', 'plain text')],
            'php disguised as png' => [UploadedFile::fake()->createWithContent('shell.png', '<?php echo shell_exec($_GET["c"]); ?>')],
            'pdf disguised as png' => [UploadedFile::fake()->createWithContent('report.png', "%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj")],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_it_rejects_everything_that_is_not_png_or_jpeg(UploadedFile $file): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/images', ['image' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('image');

        $this->assertDatabaseCount('image_blobs', 0);
    }

    public function test_it_rejects_a_file_over_five_megabytes(): void
    {
        $user = User::factory()->create();

        $oversized = $this->image('huge.png')->size(5121);

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/images', ['image' => $oversized], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('image');
    }

    public function test_it_accepts_a_file_just_under_the_limit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/images', ['image' => $this->image('ok.png')->size(5120)], ['Accept' => 'application/json'])
            ->assertCreated();
    }

    public function test_it_rejects_images_beyond_the_pixel_budget(): void
    {
        // The threshold is lowered so the test does not have to generate a
        // few hundred megabytes; the rule is under test, not its constant.
        config(['images.max_megapixels' => 0.1]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/images', ['image' => $this->image('big.png', 800, 600)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('image');
    }

    public function test_a_file_cut_off_by_php_limits_returns_413(): void
    {
        $user = User::factory()->create();

        // This is what a file rejected by upload_max_filesize looks like:
        // the content never reached the app, only the error code did.
        $truncated = new UploadedFile(
            (string) $this->image('big.png')->getRealPath(),
            'big.png',
            'image/png',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/images', ['image' => $truncated], ['Accept' => 'application/json'])
            ->assertStatus(413)
            ->assertJsonStructure(['message']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->post('/api/v1/images', ['image' => $this->image()], ['Accept' => 'application/json'])
            ->assertUnauthorized();

        $this->assertDatabaseCount('image_blobs', 0);
    }

    public function test_it_requires_a_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/images', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('image');
    }
}
