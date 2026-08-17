<?php

namespace App\Models;

use Database\Factories\ImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись о том, что пользователь владеет изображением.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int $image_blob_id
 * @property string $original_name
 * @property-read ImageBlob $blob
 */
#[Fillable(['user_id', 'image_blob_id', 'original_name'])]
class Image extends Model
{
    /** @use HasFactory<ImageFactory> */
    use HasFactory, HasUlids;

    /**
     * Первичный ключ остаётся автоинкрементным (компактный индекс, быстрая
     * курсорная пагинация), а наружу отдаётся отдельный ulid.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<ImageBlob, $this>
     */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(ImageBlob::class, 'image_blob_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
