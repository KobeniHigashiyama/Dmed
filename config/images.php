<?php

return [

    // Disk holding image bytes. Always outside public/ — files are served
    // through the controller, never by the web server directly.
    'disk' => env('IMAGES_DISK', 'images'),

    // Must stay below upload_max_filesize / post_max_size, or PHP drops the
    // file before validation runs.
    'max_upload_kilobytes' => (int) env('IMAGES_MAX_UPLOAD_KB', 5120),

    'accepted_mime_types' => ['image/jpeg', 'image/png'],

    'accepted_extensions' => ['jpg', 'jpeg', 'png'],

    // Guards against decompression bombs. 25 MP decodes into ~100 MB of raw
    // bitmap, which sets the floor for the worker's memory_limit.
    'max_megapixels' => (float) env('IMAGES_MAX_MEGAPIXELS', 25),

    'webp_quality' => (int) env('IMAGES_WEBP_QUALITY', 82),

    'max_dimension' => (int) env('IMAGES_MAX_DIMENSION', 4096),

    'driver' => env('IMAGES_DRIVER', 'gd'),

    'queue' => env('IMAGES_QUEUE', 'images'),

    // Grace period before an unreferenced file is deleted.
    'prune_delay_seconds' => (int) env('IMAGES_PRUNE_DELAY', 60),

    // Above zero, downloads redirect to a temporary signed URL of the object
    // store instead of streaming through PHP.
    'signed_url_ttl' => (int) env('IMAGES_SIGNED_URL_TTL', 0),

];
