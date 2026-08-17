<?php

namespace App\Domain\Images\Data;

use App\Domain\Images\Models\Image;

final readonly class UploadResult
{
    public function __construct(
        public Image $image,
        // False when the user had already uploaded these exact bytes.
        public bool $created,
    ) {}
}
