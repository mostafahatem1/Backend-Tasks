<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function user(Request $request): User
    {
        return $request->user();
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->safe()->only(['name', 'phone', 'password']);

        $user = User::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'password' => $validated['password'],
            'role' => 'user',
            'phone_verified_at' => null,
        ]);

        return apiResponse(
            data: new UserResource($user),
            message: 'Account created successfully.',
            status: 201
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('phone', $validated['phone'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return apiResponse(
                message: 'Invalid phone number or password.',
                status: 401
            );
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return apiResponse(
            data: [
                'user' => new UserResource($user),
                'token_type' => 'Bearer',
                'access_token' => $token,
            ],
            message: 'Logged in successfully.',
            status: 200
        );
    }
}
