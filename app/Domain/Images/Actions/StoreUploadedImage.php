<?php

namespace App\Domain\Images\Actions;

use App\Domain\Images\Data\ImageProbe;
use App\Domain\Images\Data\UploadResult;
use App\Domain\Images\Jobs\CompressImageBlob;
use App\Domain\Images\Models\Image;
use App\Domain\Images\Models\ImageBlob;
use App\Domain\Images\Support\ImagePath;
use App\Domain\Users\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores an upload and links it to its owner, deduplicating by the sha256 of
 * the original bytes before compression happens.
 */
class StoreUploadedImage
{
    public function handle(UploadedFile $file, User $user): UploadResult
    {
        $hash = hash_file('sha256', (string) $file->getRealPath());
        $probe = ImageProbe::fromUpload($file);

        // Written before the transaction so an object store round trip never
        // happens with a database transaction open. Nothing points at the file
        // yet: if the request dies here, the next upload of the same bytes
        // reuses the path, and `images:sweep` collects what is left.
        $storedPath = ImageBlob::query()->where('hash', $hash)->exists()
            ? null
            : $this->putOriginal($file, $hash, $probe);

        [$result, $blobCreated] = DB::transaction(function () use ($file, $user, $hash, $probe, $storedPath) {
            $blobCreated = false;

            // The lock serialises this against the pruner: while it is held,
            // the pruner cannot decide the blob is unreferenced.
            $blob = ImageBlob::query()->where('hash', $hash)->lockForUpdate()->first();

            if ($blob === null) {
                // $storedPath is null only when the row existed a moment ago
                // and the pruner removed it in between, so the file has to be
                // written again.
                $blob = $this->createBlob($file, $hash, $probe, $storedPath);
                $blobCreated = true;
            }

            $attached = Image::query()->insertOrIgnore([
                'ulid' => strtolower((string) Str::ulid()),
                'user_id' => $user->id,
                'image_blob_id' => $blob->id,
                'original_name' => $this->sanitizeName($file->getClientOriginalName()),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($attached > 0) {
                ImageBlob::query()->whereKey($blob->id)->increment('references');
            }

            $image = Image::query()
                ->where('user_id', $user->id)
                ->where('image_blob_id', $blob->id)
                ->sole();

            return [new UploadResult($image, $attached > 0), $blobCreated];
        });

        if ($blobCreated) {
            CompressImageBlob::dispatch($result->image->image_blob_id)->afterCommit();
        }

        return $result;
    }

    private function createBlob(UploadedFile $file, string $hash, ImageProbe $probe, ?string $storedPath): ImageBlob
    {
        $path = $storedPath ?? $this->putOriginal($file, $hash, $probe);

        // insertOrIgnore rather than create(): a concurrent request may have
        // inserted the same hash, and a unique violation would abort the
        // surrounding transaction in PostgreSQL.
        ImageBlob::query()->insertOrIgnore([
            'hash' => $hash,
            'disk' => $this->diskName(),
            'path' => $path,
            'mime' => $probe->mime,
            'size_bytes' => $probe->sizeBytes,
            'original_size_bytes' => $probe->sizeBytes,
            'width' => $probe->width,
            'height' => $probe->height,
            'status' => ImageBlob::STATUS_PENDING,
            'references' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ImageBlob::query()->where('hash', $hash)->sole();
    }

    private function putOriginal(UploadedFile $file, string $hash, ImageProbe $probe): string
    {
        $path = ImagePath::for($hash, $probe->extension);

        $this->disk()->putFileAs(dirname($path), $file, basename($path));

        return $path;
    }

    private function sanitizeName(?string $name): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '', (string) $name));

        return Str::limit($name === '' ? 'upload' : $name, 200, '');
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    private function diskName(): string
    {
        return (string) config('images.disk');
    }
}
