<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
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
}
