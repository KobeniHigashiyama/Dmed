<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Images\Actions\ReleaseImage;
use App\Domain\Images\Actions\StoreUploadedImage;
use App\Domain\Images\Models\Image;
use App\Domain\Images\Models\ImageBlob;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Images\StoreImageRequest;
use App\Http\Resources\Api\V1\ImageResource;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ImageController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly StoreUploadedImage $storeImage,
        private readonly ReleaseImage $releaseImage,
    ) {}

    /**
     * Scoped to the user and paged by cursor: at tens of millions of rows an
     * OFFSET has to read everything preceding the page it returns.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $images = $user->images()
            ->with('blob')
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request))
            ->withQueryString();

        return ImageResource::collection($images);
    }

    public function store(StoreImageRequest $request): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('image');
        /** @var User $user */
        $user = $request->user();

        $result = $this->storeImage->handle($file, $user);

        // 200 rather than 201 when the file was already there.
        return ImageResource::make($result->image->load('blob'))
            ->response()
            ->setStatusCode($result->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function show(Image $image): ImageResource
    {
        Gate::authorize('view', $image);

        return ImageResource::make($image->load('blob'));
    }

    /**
     * Files live outside public/, so this is the only way to reach them.
     */
    public function content(Request $request, Image $image): SymfonyResponse
    {
        Gate::authorize('view', $image);

        $blob = $image->blob;

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($blob->disk);

        abort_unless($disk->exists($blob->path), Response::HTTP_NOT_FOUND);

        // Let the object store serve the bytes instead of a PHP worker.
        if ($this->signedUrlTtl() > 0 && $disk->providesTemporaryUrls()) {
            return redirect()->away(
                $disk->temporaryUrl($blob->path, now()->addSeconds($this->signedUrlTtl())),
            );
        }

        $headers = [
            'Content-Type' => $blob->mime,
            'ETag' => $etag = $this->etag($blob),
            'Cache-Control' => $this->cacheControl($blob),
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response()->noContent(Response::HTTP_NOT_MODIFIED, $headers);
        }

        return $disk->response($blob->path, $this->downloadName($image, $blob), $headers);
    }

    public function destroy(Image $image): Response
    {
        Gate::authorize('delete', $image);

        $this->releaseImage->handle($image);

        return response()->noContent();
    }

    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', 25), self::MAX_PER_PAGE));
    }

    /**
     * The representation changes once, when the worker swaps the original for
     * WebP, so the status belongs in the tag.
     */
    private function etag(ImageBlob $blob): string
    {
        return '"'.$blob->hash.':'.$blob->status.'"';
    }

    private function cacheControl(ImageBlob $blob): string
    {
        return $blob->isPending()
            ? 'private, no-cache'
            : 'private, max-age=31536000';
    }

    private function downloadName(Image $image, ImageBlob $blob): string
    {
        $extension = match ($blob->mime) {
            'image/webp' => 'webp',
            'image/png' => 'png',
            default => 'jpg',
        };

        $base = pathinfo($image->original_name, PATHINFO_FILENAME);

        return ($base === '' ? 'image' : $base).'.'.$extension;
    }

    private function signedUrlTtl(): int
    {
        return (int) config('images.signed_url_ttl');
    }
}
