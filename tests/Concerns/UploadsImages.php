<?php

namespace Tests\Concerns;

use App\Domain\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

trait UploadsImages
{
    protected function fakeImageDisk(): void
    {
        Storage::fake($this->imageDisk());
    }

    protected function imageDisk(): string
    {
        return (string) config('images.disk');
    }

    /**
     * A real image rather than a placeholder: validation reads the content
     * through getimagesize, which an empty stub would never pass.
     */
    protected function image(string $name = 'photo.png', int $width = 600, int $height = 400): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    /**
     * PNG bytes with predictable content: the same seed yields a bit-for-bit
     * identical file, a different seed a different one. Deduplication is
     * tested against that, not against a hope that the fixture generator is
     * deterministic.
     */
    protected function pngBytes(int $seed = 1, int $width = 60, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle(
            $image, 0, 0, $width, $height,
            (int) imagecolorallocate($image, $seed % 256, ($seed * 7) % 256, ($seed * 13) % 256),
        );

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    protected function pngUpload(string $name = 'photo.png', int $seed = 1): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngBytes($seed));
    }

    protected function uploadAs(User $user, UploadedFile $file): TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->post('/api/v1/images', ['image' => $file], ['Accept' => 'application/json']);
    }

    /**
     * Runs a job right here, right now.
     *
     * dispatchSync is no good under Queue::fake(): the queue is swapped out,
     * so the call lands in the list of pushed jobs instead of doing anything.
     */
    protected function runJob(object $job): void
    {
        app()->call([$job, 'handle']);
    }
}
