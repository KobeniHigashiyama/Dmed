<?php

use App\Http\Controllers\Api\V1\ImageController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('images')->name('images.')->group(function () {
    Route::get('/', [ImageController::class, 'index'])->name('index');

    Route::post('/', [ImageController::class, 'store'])
        ->middleware(['throttle:uploads', 'uploads.size'])
        ->name('store');

    Route::get('{image}', [ImageController::class, 'show'])->name('show');
    Route::get('{image}/content', [ImageController::class, 'content'])->name('content');
    Route::delete('{image}', [ImageController::class, 'destroy'])->name('destroy');
});
