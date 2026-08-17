<?php

use App\Domain\Images\Console\PruneImageBlobs;
use App\Http\Middleware\RejectFailedUploads;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // One file per resource under a shared version prefix.
            foreach (['auth', 'images'] as $resource) {
                Route::middleware('api')
                    ->prefix('api/v1')
                    ->as('api.v1.')
                    ->group(base_path("routes/api/v1/{$resource}.php"));
            }
        },
    )
    ->withCommands([PruneImageBlobs::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi('api');

        $middleware->alias([
            'uploads.size' => RejectFailedUploads::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // PHP cuts a body larger than post_max_size off before routing, and
        // the default answer for that is an unhelpful 500.
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => sprintf(
                    'The request body exceeds the server limit of %s (post_max_size).',
                    ini_get('post_max_size'),
                ),
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        });
    })->create();
