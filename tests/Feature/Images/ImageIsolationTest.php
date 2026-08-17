<?php

namespace Tests\Feature\Images;

use App\Domain\Images\Models\Image;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UploadsImages;
use Tests\TestCase;

/**
 * A user sees their own images only, both in the listing and one by one.
 */
class ImageIsolationTest extends TestCase
{
    use RefreshDatabase, UploadsImages;

    public function test_the_listing_contains_only_own_images(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        $mine = Image::factory()->count(3)->create(['user_id' => $user->id]);
        Image::factory()->count(2)->create(['user_id' => $stranger->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/images');

        $response->assertOk()->assertJsonCount(3, 'data');

        $this->assertEqualsCanonicalizing(
            $mine->pluck('ulid')->all(),
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_someone_elses_image_is_not_found(): void
    {
        $user = User::factory()->create();
        $theirs = Image::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/images/'.$theirs->ulid)
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/images/'.$theirs->ulid.'/content')
            ->assertNotFound();
    }

    public function test_someone_elses_image_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $theirs = Image::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/images/'.$theirs->ulid)
            ->assertNotFound();

        $this->assertDatabaseHas('images', ['id' => $theirs->id]);
    }

    public function test_an_unknown_identifier_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/images/01hzzzzzzzzzzzzzzzzzzzzzzz')
            ->assertNotFound();
    }

    public function test_the_listing_paginates_by_cursor(): void
    {
        $user = User::factory()->create();
        Image::factory()->count(5)->create(['user_id' => $user->id]);

        $first = $this->actingAs($user, 'sanctum')->getJson('/api/v1/images?per_page=2');

        $first->assertOk()->assertJsonCount(2, 'data');
        $this->assertNotNull($first->json('meta.next_cursor'));

        $second = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/images?per_page=2&cursor='.$first->json('meta.next_cursor'));

        $second->assertOk()->assertJsonCount(2, 'data');

        $this->assertEmpty(array_intersect(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
        ));
    }
}
