<?php

namespace Tests\Unit;

use App\Domain\Images\Support\ImagePath;
use PHPUnit\Framework\TestCase;

class ImagePathTest extends TestCase
{
    public function test_it_shards_by_the_first_two_byte_pairs_of_the_hash(): void
    {
        $hash = str_repeat('a', 60).'beef';

        $this->assertSame("aa/aa/{$hash}.webp", ImagePath::for($hash, 'webp'));
    }

    public function test_the_path_is_a_pure_function_of_hash_and_extension(): void
    {
        $hash = hash('sha256', 'payload');

        // Idempotent writes rest on this: two concurrent requests carrying
        // the same bytes target the same path.
        $this->assertSame(ImagePath::for($hash, 'jpg'), ImagePath::for($hash, 'jpg'));
        $this->assertNotSame(ImagePath::for($hash, 'jpg'), ImagePath::for($hash, 'webp'));
    }

    public function test_different_hashes_spread_across_directories(): void
    {
        $directories = [];

        for ($i = 0; $i < 200; $i++) {
            $path = ImagePath::for(hash('sha256', (string) $i), 'webp');
            $directories[dirname($path)] = true;
        }

        // 65 536 possible directories: a couple hundred files barely
        // collide, and a flat directory never happens.
        $this->assertGreaterThan(150, count($directories));
    }
}
