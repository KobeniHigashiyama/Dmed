<?php

namespace Database\Factories;

use App\Models\Image;
use App\Models\ImageBlob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Image>
 */
class ImageFactory extends Factory
{
    protected $model = Image::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'image_blob_id' => ImageBlob::factory()->state(['references' => 1]),
            'original_name' => fake()->word().'.jpg',
        ];
    }
}
