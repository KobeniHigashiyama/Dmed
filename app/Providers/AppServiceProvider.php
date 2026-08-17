<?php

namespace App\Providers;

use App\Domain\Users\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImageManagerInterface::class, function (): ImageManager {
            return new ImageManager(
                config('images.driver') === 'imagick' ? new ImagickDriver : new GdDriver,
            );
        });
    }

    public function boot(): void
    {
        // Morph columns store aliases, so moving a model between namespaces
        // stays a refactor instead of a migration.
        Relation::enforceMorphMap([
            'user' => User::class,
        ]);

        $this->configureRateLimiting();
    }

    /**
     * Keyed by user rather than by IP: a whole office can share one address.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));


        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by((string) $request->ip()),
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email'))),
        ]);
    }
}
