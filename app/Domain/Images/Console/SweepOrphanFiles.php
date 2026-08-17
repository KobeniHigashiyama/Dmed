<?php

namespace App\Domain\Images\Console;

use App\Domain\Images\Models\ImageBlob;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;

/**
 * Deletes files that no row points at.
 *
 * Two narrow windows produce them: a request dying between writing the file
 * and committing its row, and the compressor writing a .webp for a blob the
 * pruner has just removed. `images:prune` cannot help — it walks the table,
 * and these files are exactly the ones missing from it.
 *
 * Listing is lazy and takes a prefix, so at scale this runs shard by shard
 * (`--prefix=ab`) rather than as one pass over tens of millions of objects.
 */
class SweepOrphanFiles extends Command
{
    protected $signature = 'images:sweep
        {--prefix= : Limit the walk to one shard, e.g. ab or ab/cd}
        {--hours=24 : Skip files younger than this, they may still be in flight}
        {--dry-run : Only report what would be deleted}';

    protected $description = 'Delete stored files that no image blob refers to';

    public function handle(): int
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('images.disk'));

        $cutoff = now()->subHours((int) $this->option('hours'))->getTimestamp();
        $dryRun = (bool) $this->option('dry-run');

        $scanned = 0;
        $orphans = 0;

        $listing = $disk->getDriver()->listContents((string) $this->option('prefix'), true);

        /** @var StorageAttributes $item */
        foreach ($listing as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $scanned++;
            $path = $item->path();

            if (ImageBlob::query()->where('path', $path)->exists()) {
                continue;
            }

            if (($item->lastModified() ?? $disk->lastModified($path)) > $cutoff) {
                continue;
            }

            $orphans++;
            $this->line($dryRun ? "would delete {$path}" : "deleting {$path}");

            if (! $dryRun) {
                $disk->delete($path);
            }
        }

        $this->info("Scanned {$scanned} file(s), {$orphans} orphaned.");

        return self::SUCCESS;
    }
}
