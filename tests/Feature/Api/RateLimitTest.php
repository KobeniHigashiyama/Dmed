<?php

namespace Tests\Feature\Api;

use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_guessing_against_one_account_gets_throttled(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-'.$attempt,
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-again',
        ])->assertStatus(429);
    }

    public function test_the_limit_is_per_account_not_global(): void
    {
        $victim = User::factory()->create();
        $other = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $victim->email,
                'password' => 'wrong',
            ]);
        }

        // Someone else's failed attempts must not lock everybody out.
        $this->postJson('/api/v1/auth/login', [
            'email' => $other->email,
            'password' => 'password',
        ])->assertOk();
    }
}
