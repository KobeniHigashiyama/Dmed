<?php

namespace App\Domain\Images\Jobs;

use App\Domain\Images\Models\ImageBlob;
use App\Domain\Images\Services\ImageCompressor;
use App\Domain\Images\Support\ImagePath;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Compresses a blob to WebP and replaces the original with it. Kept out of
 * the request because re-encoding dominates the CPU and memory cost of an
 * upload; until it runs, the original is on disk and servable.
 */
class CompressImageBlob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $blobId)
    {
        $this->onQueue((string) config('images.queue'));
    }

    public function uniqueId(): string
    {
        return 'image-blob:'.$this->blobId;
    }

    public function handle(ImageCompressor $compressor): void
    {
        $blob = ImageBlob::query()->find($this->blobId);
        if ($blob === null || ! $blob->isPending()) {
            return;
        }

        $disk = Storage::disk($blob->disk);

        if (! $disk->exists($blob->path)) {
            $this->markFailed($blob, 'Source file is missing on disk.');

            return;
        }

        $originalPath = $blob->path;
        $compressed = $compressor->toWebp((string) $disk->get($originalPath));

        // Small flat-palette PNGs can come out heavier as WebP.
        if ($compressed->size() >= $blob->size_bytes) {
            $blob->update(['status' => ImageBlob::STATUS_READY]);

            return;
        }

        $webpPath = ImagePath::for($blob->hash, 'webp');
        $disk->put($webpPath, $compressed->binary);

        $blob->update([
            'path' => $webpPath,
            'mime' => 'image/webp',
            'size_bytes' => $compressed->size(),
            'width' => $compressed->width,
            'height' => $compressed->height,
            'status' => ImageBlob::STATUS_READY,
        ]);

        $disk->delete($originalPath);
    }

    public function failed(Throwable $exception): void
    {
        $blob = ImageBlob::query()->find($this->blobId);

        if ($blob !== null && $blob->isPending()) {
            $this->markFailed($blob, $exception->getMessage());
        }
    }

    /**
     * A failed status still serves the original, just uncompressed.
     */
    private function markFailed(ImageBlob $blob, string $reason): void
    {
        $blob->update([
            'status' => ImageBlob::STATUS_FAILED,
            'failure_reason' => mb_substr($reason, 0, 1000),
        ]);
    }
}
