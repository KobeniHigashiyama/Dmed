<?php

namespace App\Domain\Images\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Asserts the upload really decodes as JPEG or PNG. finfo only inspects the
 * leading signature, so a valid header with a forged body passes the
 * mimes/mimetypes rules. Also enforces the pixel budget.
 */
class DecodableImage implements ValidationRule
{
    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The :attribute failed to upload.');

            return;
        }

        $info = @getimagesize((string) $value->getRealPath());

        if ($info === false) {
            $fail('The :attribute is not a readable image.');

            return;
        }

        [$width, $height, $type] = $info;

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            $fail('The :attribute must be a PNG or JPEG image.');

            return;
        }

        if (($width * $height) / 1_000_000 > (float) config('images.max_megapixels')) {
            $fail(sprintf(
                'The :attribute resolution exceeds %s megapixels.',
                config('images.max_megapixels'),
            ));
        }
    }
}
