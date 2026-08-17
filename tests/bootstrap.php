<?php

require __DIR__.'/../vendor/autoload.php';

/*
 * Pins the testing environment.
 *
 * Laravel resolves env() from $_SERVER before anything else, and the Docker
 */
$environment = [
    'APP_ENV' => 'testing',
    'BCRYPT_ROUNDS' => '4',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'IMAGES_DISK' => 'images',
    'IMAGES_SIGNED_URL_TTL' => '0',
];

foreach ($environment as $key => $value) {
    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}
