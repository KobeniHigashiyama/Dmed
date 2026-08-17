<?php

namespace Database\Factories;

use App\Models\ImageBlob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageBlob>
 */
class ImageBlobFactory extends Factory
{
    protected $model = ImageBlob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hash = hash('sha256', fake()->unique()->uuid());

        return [
            'hash' => $hash,
            'disk' => config('images.disk'),
            'path' => sprintf('%s/%s/%s.webp', substr($hash, 0, 2), substr($hash, 2, 2), $hash),
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
