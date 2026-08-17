<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Turns a PHP-truncated upload into an honest 413. Past upload_max_filesize
 * PHP hands over an empty placeholder with an error code, and the validator
 * reports it as a generic upload failure.
 */
class RejectFailedUploads
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        foreach ($this->files($request->allFiles()) as $file) {
            if ($file->getError() === UPLOAD_ERR_INI_SIZE) {
                return response()->json([
                    'message' => sprintf(
                        'The uploaded file exceeds the server limit of %s (upload_max_filesize).',
                        ini_get('upload_max_filesize'),
                    ),
                ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }
        }

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $files
     * @return iterable<UploadedFile>
     */
    private function files(array $files): iterable
    {
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                yield $file;
            } elseif (is_array($file)) {
                yield from $this->files($file);
            }
        }
    }
}
