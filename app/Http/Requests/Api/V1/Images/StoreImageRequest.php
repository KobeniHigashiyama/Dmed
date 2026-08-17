<?php

namespace App\Http\Requests\Api\V1\Images;

use App\Domain\Images\Rules\DecodableImage;
use Illuminate\Foundation\Http\FormRequest;

class StoreImageRequest extends FormRequest
{
    /**
     * Three checks, none of which trusts the client: mimes derives the
     * extension from the content, mimetypes reads the signature rather than
     * the Content-Type header, and DecodableImage actually decodes the file.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var array<int, string> $extensions */
        $extensions = config('images.accepted_extensions');
        /** @var array<int, string> $mimeTypes */
        $mimeTypes = config('images.accepted_mime_types');

        return [
            'image' => [
                'required',
                'file',
                'mimes:'.implode(',', $extensions),
                'mimetypes:'.implode(',', $mimeTypes),
                'max:'.config('images.max_upload_kilobytes'),
                new DecodableImage,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.mimes' => 'Only PNG and JPEG images are accepted.',
            'image.mimetypes' => 'Only PNG and JPEG images are accepted.',
            'image.max' => sprintf(
                'The image may not be larger than %d MB.',
                (int) config('images.max_upload_kilobytes') / 1024,
            ),
        ];
    }
}
