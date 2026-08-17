<?php

namespace App\Jobs;

use App\Models\ImageBlob;
use App\Services\Images\ImageCompressor;
use App\Services\Images\ImagePath;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Сжимает блоб в WebP и заменяет им оригинал.
 *
 * Перекодирование — самая дорогая часть загрузки по CPU и памяти, поэтому
 * оно вынесено из запроса: при 100k файлов в день web-воркеры иначе стоят в
 * ожидании GD вместо того, чтобы принимать новые запросы. Пока джоб не
 * отработал, на диске лежит валидный оригинал, и отдавать его можно.
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

        // Джоб идемпотентен: повторный запуск по уже обработанному блобу
        // (ретрай, дубль от параллельной загрузки) просто выходит.
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

        // На маленьких PNG с плоской палитрой WebP иногда выходит тяжелее
        // оригинала. Смысла плодить файл ради отрицательной экономии нет.
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

        // Оригинал удаляется последним: пока строка не обновлена, он остаётся
        // единственным, что можно отдать клиенту.
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
     * Статус failed не ломает выдачу: на диске остаётся оригинал, клиент
     * получает своё изображение несжатым.
     */
    private function markFailed(ImageBlob $blob, string $reason): void
    {
        $blob->update([
            'status' => ImageBlob::STATUS_FAILED,
            'failure_reason' => mb_substr($reason, 0, 1000),
        ]);
    }
}
