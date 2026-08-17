<?php

namespace App\Services\Images;

use App\Jobs\CompressImageBlob;
use App\Models\Image;
use App\Models\ImageBlob;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageIntakeService
{
    /**
     * Кладёт загруженный файл в хранилище и привязывает его к пользователю.
     *
     * Дедупликация идёт по sha256 исходных байт и считается ДО сжатия:
     * повторная загрузка не тратит ни диск, ни CPU на перекодирование.
     */
    public function store(UploadedFile $file, User $user): UploadResult
    {
        $hash = hash_file('sha256', (string) $file->getRealPath());
        $probe = ImageProbe::fromUpload($file);

        // Быстрый путь: байты уже на диске, писать нечего. Проверка вне
        // транзакции — блокировать строку ради чтения смысла нет.
        if (! ImageBlob::query()->where('hash', $hash)->exists()) {
            $this->putOriginal($file, $hash, $probe);
        }

        [$result, $blobCreated] = DB::transaction(function () use ($file, $user, $hash, $probe) {
            $blobCreated = false;

            // lockForUpdate сериализует нас со сборщиком мусора: пока строка
            // заблокирована, он не может решить, что на блоб никто не ссылается.
            $blob = ImageBlob::query()->where('hash', $hash)->lockForUpdate()->first();

            if ($blob === null) {
                // Либо блоб новый, либо сборщик мусора успел удалить его между
                // проверкой выше и этой транзакцией. Путь детерминирован от
                // хеша, поэтому файл достаточно просто записать заново.
                $blob = $this->createBlob($file, $hash, $probe);
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

            // Уникальный индекс (user_id, image_blob_id) делает повторную
            // загрузку тем же пользователем идемпотентной: insertOrIgnore
            // вернёт 0, счётчик ссылок останется прежним.
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

    private function createBlob(UploadedFile $file, string $hash, ImageProbe $probe): ImageBlob
    {
        $path = $this->putOriginal($file, $hash, $probe);

        // insertOrIgnore вместо create: параллельный запрос с тем же файлом
        // мог успеть вставить строку, и ловить тут исключение уникальности
        // нельзя — в PostgreSQL оно обрывает всю транзакцию.
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

    /**
     * Имя файла клиента попадает только в метаданные и никогда — в путь на
     * диске, поэтому обходить каталоги через него нечем. Всё равно режем:
     * его отдают обратно в API.
     */
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
