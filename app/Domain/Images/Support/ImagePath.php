<?php

namespace App\Domain\Images\Support;

/**
 * Content addressed paths sharded over 65 536 directories: a flat directory
 * holding tens of millions of files is where filesystem lookups fall apart.
 * The path derives purely from the hash, which makes writes idempotent.
 */
final class ImagePath
{
    public static function for(string $hash, string $extension): string
    {
        return sprintf(
            '%s/%s/%s.%s',
            substr($hash, 0, 2),
            substr($hash, 2, 2),
            $hash,
            $extension,
        );
    }
}
