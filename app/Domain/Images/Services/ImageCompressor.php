<?php

namespace App\Domain\Images\Services;

use App\Domain\Images\Data\CompressedImage;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Interfaces\ImageManagerInterface;

class ImageCompressor
{
    public function __construct(private readonly ImageManagerInterface $manager) {}

    /**
     * Re-encodes as WebP, scaling oversized images down and dropping EXIF
     * (including the GPS tag phones attach) along the way.
     */
    public function toWebp(string $binary): CompressedImage
    {
        $image = $this->manager->read($binary);

        $limit = (int) config('images.max_dimension');
        $image->scaleDown($limit, $limit);

        $encoded = $image->encode(
            new WebpEncoder(quality: (int) config('images.webp_quality')),
        );

        return new CompressedImage(
            binary: (string) $encoded,
            width: $image->width(),
            height: $image->height(),
        );
    }
}
