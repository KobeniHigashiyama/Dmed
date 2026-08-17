<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = User::query()->create($request->safe()->only(['name', 'email', 'password']));

        return response()->json([
            'user' => new UserResource($user),
            'token' => $this->issueToken($user, $request),
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check($request->string('password')->value(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return response()->json([
            'user' => new UserResource($user),
            'token' => $this->issueToken($user, $request),
        ]);
    }

    /**
     * Revokes the current token only, not every session the user has.
     */
    public function logout(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
    }

    private function issueToken(User $user, Request $request): string
    {
        $device = $request->string('device_name')->trim();

        return $user->createToken($device->isEmpty() ? 'api' : $device->limit(100, '')->value())
            ->plainTextToken;
    }
}
