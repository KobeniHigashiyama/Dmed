<?php

namespace App\Domain\Images\Console;

use App\Domain\Images\Jobs\PruneOrphanImageBlob;
use App\Domain\Images\Models\ImageBlob;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Safety net for cleanup jobs that never ran: a crashed worker, a flushed
 * queue, a restarted Redis.
 */
class PruneImageBlobs extends Command
{
    protected $signature = 'images:prune {--minutes=60 : Leave blobs touched more recently than this alone}';

    protected $description = 'Queue removal of images nothing references anymore';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));
        $queued = 0;

        ImageBlob::query()
            ->orphaned()
            ->where('updated_at', '<', $cutoff)
            ->chunkById(500, function (Collection $blobs) use (&$queued): void {
                /** @var Collection<int, ImageBlob> $blobs */
                foreach ($blobs as $blob) {
                    PruneOrphanImageBlob::dispatch($blob->id);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} orphaned blob(s) for removal.");

        return self::SUCCESS;
    }
}
