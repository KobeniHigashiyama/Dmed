<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Проверяет, что файл действительно декодируется как JPEG или PNG.
 *
 * Правила mimes/mimetypes опираются на finfo, а он смотрит только сигнатуру
 * в первых байтах. Файл с корректным заголовком, но битым или подменённым
 * телом (polyglot) их проходит и падает уже в воркере при обработке.
 * Здесь же отсекаются картинки-бомбы: пара килобайт на диске, сотни
 * мегапикселей после декодирования.
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

        $info = @getimagesize($value->getRealPath());

        if ($info === false) {
            $fail('The :attribute is not a readable image.');

            return;
        }

        [$width, $height, $type] = $info;

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            $fail('The :attribute must be a PNG or JPEG image.');

            return;
        }

        $megapixels = ($width * $height) / 1_000_000;

        if ($megapixels > (float) config('images.max_megapixels')) {
            $fail(sprintf(
                'The :attribute resolution exceeds %s megapixels.',
                config('images.max_megapixels')
            ));
        }
    }
}
