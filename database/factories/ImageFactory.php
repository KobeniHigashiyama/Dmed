<?php

namespace Database\Factories;

use App\Domain\Images\Models\Image;
use App\Domain\Images\Models\ImageBlob;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Image>
 */
class ImageFactory extends Factory
{
    protected $model = Image::class;

    /** @return array{user_id: Factory<User>, image_blob_id: Factory<ImageBlob>, original_name: string} */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'image_blob_id' => ImageBlob::factory()->state(['references' => 1]),
            'original_name' => fake()->word().'.jpg',
        ];
    }
}
