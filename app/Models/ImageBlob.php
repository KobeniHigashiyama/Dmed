<?php

namespace App\Models;

use Database\Factories\ImageBlobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Физический файл на диске, общий для всех, кто загрузил такие же байты.
 *
 * @property int $id
 * @property string $hash
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size_bytes
 * @property int $original_size_bytes
 * @property int $width
 * @property int $height
 * @property string $status
 * @property string|null $failure_reason
 * @property int $references
 */
#[Fillable([
    'hash', 'disk', 'path', 'mime', 'size_bytes', 'original_size_bytes',
    'width', 'height', 'status', 'failure_reason', 'references',
])]
class ImageBlob extends Model
{
    /** @use HasFactory<ImageBlobFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'original_size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'references' => 'integer',
        ];
    }

    /**
     * @return HasMany<Image, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    /**
     * Блобы, на которые больше никто не ссылается — кандидаты на удаление.
     *
     * @param  Builder<ImageBlob>  $query
     */
    public function scopeOrphaned(Builder $query): void
    {
        $query->where('references', '<=', 0);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
