<?php

namespace Database\Factories;

use App\Domain\Images\Models\ImageBlob;
use App\Domain\Images\Support\ImagePath;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageBlob>
 */
class ImageBlobFactory extends Factory
{
    protected $model = ImageBlob::class;

    /** @return array{hash: string, disk: mixed, path: string, mime: string, size_bytes: int, original_size_bytes: int, width: int, height: int, status: string, references: int} */
    public function definition(): array
    {
        $hash = hash('sha256', fake()->unique()->uuid());

        return [
            'hash' => $hash,
            'disk' => config('images.disk'),
            'path' => ImagePath::for($hash, 'webp'),
            'mime' => 'image/webp',
            'size_bytes' => fake()->numberBetween(10_000, 400_000),
            'original_size_bytes' => fake()->numberBetween(400_000, 5_000_000),
            'width' => 1200,
            'height' => 800,
            'status' => ImageBlob::STATUS_READY,
            'references' => 0,
        ];
    }

    public function pending(): self
    {
        return $this->state(fn () => [
            'status' => ImageBlob::STATUS_PENDING,
            'mime' => 'image/jpeg',
        ]);
    }
}
