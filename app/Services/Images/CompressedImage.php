<?php

namespace App\Services\Images;

final readonly class CompressedImage
{
    public function __construct(
        public string $binary,
        public int $width,
        public int $height,
    ) {}

    public function size(): int
    {
        return strlen($this->binary);
    }
}
