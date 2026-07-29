<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_successfully(): void
    {
        $payload = [
            'name' => 'John Doe',
            'phone' => '+1234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Account created successfully.',
                'data' => [
                    'name' => 'John Doe',
                    'phone' => '+1234567890',
                    'phone_verified_at' => null,
                    'role' => 'user',
                ],
            ])
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'name',
                    'phone',
                    'phone_verified_at',
                    'role',
                    'created_at',
                ],
            ])
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->assertDatabaseHas('users', [
            'name' => 'John Doe',
            'phone' => '+1234567890',
            'role' => 'user',
            'phone_verified_at' => null,
        ]);

        $user = User::where('phone', '+1234567890')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_registration_fails_when_required_fields_are_missing(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'phone', 'password']);
    }

    public function test_registration_fails_with_an_invalid_phone_format(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'phone' => '1234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_registration_fails_when_the_phone_already_exists(): void
    {
        User::factory()->create([
            'phone' => '+1234567890',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'phone' => '+1234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->assertEquals(1, User::where('phone', '+1234567890')->count());
    }

    public function test_registration_fails_when_password_confirmation_does_not_match(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'phone' => '+1234567890',
            'password' => 'password123',
            'password_confirmation' => 'mismatch123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_a_client_cannot_create_an_admin_account_through_registration(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Hacker Admin',
            'phone' => '+1234567899',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'role' => 'user',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'phone' => '+1234567899',
            'role' => 'user',
        ]);

        $this->assertDatabaseMissing('users', [
            'phone' => '+1234567899',
            'role' => 'admin',
        ]);
    }
}
