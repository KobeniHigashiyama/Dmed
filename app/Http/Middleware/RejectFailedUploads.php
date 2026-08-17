<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Answers uploads that PHP itself rejected before the framework saw them.
 *
 * Past upload_max_filesize PHP hands over an empty placeholder carrying an
 * error code, and the validator reports it as a generic upload failure — the
 * caller then goes looking for a bug instead of a line in php.ini.
 */
class RejectFailedUploads
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        foreach ($this->files($request->allFiles()) as $file) {
            $error = $file->getError();

            // An empty field is a validation problem, not a transport one.
            if ($error === UPLOAD_ERR_OK || $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            return $this->respond($error);
        }

        return $next($request);
    }

    private function respond(int $error): SymfonyResponse
    {
        [$status, $message] = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => [
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                sprintf(
                    'The uploaded file exceeds the server limit of %s (upload_max_filesize).',
                    ini_get('upload_max_filesize'),
                ),
            ],
            UPLOAD_ERR_PARTIAL => [
                Response::HTTP_BAD_REQUEST,
                'The upload was interrupted before it finished. Please retry.',
            ],
            // No temporary directory, disk full, an extension refusing the
            // file — all of these are the server's problem, not the caller's.
            default => [
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'The server could not store the uploaded file.',
            ],
        };

        if ($status === Response::HTTP_INTERNAL_SERVER_ERROR) {
            Log::error('Upload rejected by PHP', ['upload_error' => $error]);
        }

        return response()->json(['message' => $message], $status);
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
