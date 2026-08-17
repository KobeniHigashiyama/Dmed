<?php

namespace App\Services\Images;

use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Interfaces\ImageManagerInterface;

class ImageCompressor
{
    public function __construct(private readonly ImageManagerInterface $manager) {}

    /**
     * Перекодирует изображение в WebP.
     *
     * Заодно решаются две задачи помимо веса: сторона больше max_dimension
     * ужимается с сохранением пропорций, а EXIF (включая геометку с телефона)
     * не переносится в результат — кодировщик пишет только пиксели.
     */
    public function toWebp(string $binary): CompressedImage
    {
        $image = $this->manager->read($binary);

        $limit = (int) config('images.max_dimension');
        $image->scaleDown($limit, $limit);

        $encoded = $image->encode(
            new WebpEncoder(quality: (int) config('images.webp_quality'))
        );

        return new CompressedImage(
            binary: (string) $encoded,
            width: $image->width(),
            height: $image->height(),
        );
    }
}
