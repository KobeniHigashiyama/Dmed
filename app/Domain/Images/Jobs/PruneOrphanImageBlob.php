<?php

namespace App\Domain\Images\Jobs;

use App\Domain\Images\Models\ImageBlob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a stored file once nothing references it, re-checking under a row
 * lock: between the last reference going away and this job running, another
 * user may have uploaded the very same file.
 */
class PruneOrphanImageBlob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $blobId)
    {
        $this->onQueue((string) config('images.queue'));
    }

    public function handle(): void
    {
        $blob = DB::transaction(function (): ?ImageBlob {
            $blob = ImageBlob::query()->whereKey($this->blobId)->lockForUpdate()->first();

            if ($blob === null) {
                return null;
            }

            // Ownership rows decide, not the counter — see ImageBlob::scopeOrphaned.
            if ($blob->images()->exists()) {
                return null;
            }

            $blob->delete();

            return $blob;
        });

        if ($blob === null) {
            return;
        }

        Storage::disk($blob->disk)->delete($blob->path);
    }
}
