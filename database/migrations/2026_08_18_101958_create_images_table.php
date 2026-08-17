<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ownership: which user holds which blob. The fastest growing table here
     * (100k uploads a day is ~36M rows a year), hence the ULID and the
     * (user_id, id) index for cursor pagination.
     */
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();

            // Public identifier: leaks no row count, cannot be walked.
            $table->ulid('ulid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('image_blob_id')->constrained('image_blobs');

            $table->string('original_name');

            $table->timestamps();

            // Makes re-uploading the same file idempotent for a given user.
            $table->unique(['user_id', 'image_blob_id']);

            $table->index(['user_id', 'id']);
            $table->index('image_blob_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
