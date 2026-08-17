<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Images\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Image
 */
class ImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $blob = $this->blob;

        return [
            'id' => $this->ulid,
            'name' => $this->original_name,
            'mime' => $blob->mime,
            'size' => $blob->size_bytes,
            'width' => $blob->width,
            'height' => $blob->height,
            'status' => $blob->status,
            'checksum' => $blob->hash,
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'links' => [
                'self' => route('api.v1.images.show', $this->resource),
                'content' => route('api.v1.images.content', $this->resource),
            ],
        ];
    }
}
