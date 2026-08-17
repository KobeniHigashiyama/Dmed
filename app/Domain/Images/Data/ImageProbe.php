<?php

namespace App\Domain\Images\Data;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Facts about an upload, read from its bytes rather than its headers.
 */
final readonly class ImageProbe
{
    public function __construct(
        public string $mime,
        public string $extension,
        public int $width,
        public int $height,
        public int $sizeBytes,
    ) {}

    public static function fromUpload(UploadedFile $file): self
    {
        $info = @getimagesize((string) $file->getRealPath());

        if ($info === false) {
            throw new RuntimeException('Unable to read image dimensions.');
        }

        [$width, $height, $type] = $info;

        return new self(
            mime: image_type_to_mime_type($type),
            extension: $type === IMAGETYPE_PNG ? 'png' : 'jpg',
            width: $width,
            height: $height,
            sizeBytes: (int) $file->getSize(),
        );
    }
}
