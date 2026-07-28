<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_log_in_successfully_using_their_phone_and_password(): void
    {
        $user = User::factory()->create([
            'phone' => '+1234567890',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'phone' => '+1234567890',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Logged in successfully.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => '+1234567890',
                        'role' => 'user',
                    ],
                    'token_type' => 'Bearer',
                ],
            ])
            ->assertJsonStructure([
                'message',
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'phone',
                        'phone_verified_at',
                        'role',
                        'created_at',
                    ],
                    'token_type',
                    'access_token',
                ],
            ])
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');

        $this->assertNotEmpty($response->json('data.access_token'));

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_login_fails_when_required_fields_are_missing(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone', 'password']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_fails_with_an_invalid_phone_format(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'phone' => '12345',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_fails_when_the_password_is_incorrect(): void
    {
        User::factory()->create([
            'phone' => '+1234567890',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'phone' => '+1234567890',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid phone number or password.',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_fails_when_the_phone_does_not_exist(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'phone' => '+1999999999',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid phone number or password.',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_returned_sanctum_token_can_authenticate_a_protected_api_request(): void
    {
        $user = User::factory()->create([
            'phone' => '+1234567890',
            'password' => 'password123',
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'phone' => '+1234567890',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.access_token');

        $protectedResponse = $this->withToken($token)
            ->getJson('/api/user');

        $protectedResponse->assertStatus(200)
            ->assertJson([
                'id' => $user->id,
            ]);
    }
}
