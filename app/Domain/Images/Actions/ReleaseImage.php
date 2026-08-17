<?php

namespace App\Domain\Images\Actions;

use App\Domain\Images\Jobs\PruneOrphanImageBlob;
use App\Domain\Images\Models\Image;
use App\Domain\Images\Models\ImageBlob;
use Illuminate\Support\Facades\DB;

class ReleaseImage
{
    /**
     * Detaches an image from its owner. The stored file survives while anyone
     * else references it; a delayed job handles the actual cleanup.
     */
    public function handle(Image $image): void
    {
        $blobId = $image->image_blob_id;

        DB::transaction(function () use ($image, $blobId) {
            ImageBlob::query()->whereKey($blobId)->lockForUpdate()->first();

            $image->delete();

            ImageBlob::query()
                ->whereKey($blobId)
                ->where('references', '>', 0)
                ->decrement('references');
        });

        PruneOrphanImageBlob::dispatch($blobId)
            ->afterCommit()
            ->delay(now()->addSeconds((int) config('images.prune_delay_seconds')));
    }
}
