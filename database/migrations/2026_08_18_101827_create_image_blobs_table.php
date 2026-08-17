<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stored bytes, decoupled from ownership: one row per unique file, with
     * "references" counting the records pointing at it.
     */
    public function up(): void
    {
        Schema::create('image_blobs', function (Blueprint $table) {
            $table->id();

            // sha256 of the original bytes, computed before compression so a
            // repeat upload skips the re-encoding entirely.
            $table->char('hash', 64)->unique();

            $table->string('disk', 32);
            $table->string('path');

            $table->string('mime', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('original_size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');

            // pending -> ready | failed. While pending the original is on disk
            // and servable, just not compressed yet.
            $table->string('status', 16)->default('pending');
            $table->text('failure_reason')->nullable();

            $table->unsignedBigInteger('references')->default(0);

            $table->timestamps();

            // Cleanup scans: blobs nothing points at anymore.
            $table->index(['references', 'id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_blobs');
    }
};
