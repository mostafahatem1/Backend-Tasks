<?php

namespace Tests\Feature\Auth;

use App\Models\PhoneVerificationCode;
use App\Models\User;
use App\Notifications\PhoneVerificationCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_user_cannot_request_a_verification_code(): void
    {
        $response = $this->postJson('/api/v1/auth/phone-verification/send');

        $response->assertStatus(401);
    }

    public function test_an_unauthenticated_user_cannot_verify_a_phone(): void
    {
        $response = $this->postJson('/api/v1/auth/phone-verification/verify', [
            'code' => '123456',
        ]);

        $response->assertStatus(401);
    }

    public function test_an_unverified_authenticated_user_can_request_a_verification_code(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/phone-verification/send');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Verification code sent successfully.',
            ])
            ->assertJsonMissingPath('code')
            ->assertJsonMissingPath('data.code');

        $this->assertDatabaseCount('phone_verification_codes', 1);

        $record = PhoneVerificationCode::where('user_id', $user->id)->first();
        $this->assertNotNull($record);
        $this->assertNotEquals('123456', $record->code_hash);

        Notification::assertSentTo(
            $user,
            PhoneVerificationCodeNotification::class,
            function (PhoneVerificationCodeNotification $notification) use ($record) {
                return Hash::check($notification->code, $record->code_hash);
            }
        );
    }

    public function test_requesting_a_second_verification_code_replaces_the_first_code(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        // First request
        $this->postJson('/api/v1/auth/phone-verification/send')->assertStatus(200);

        $firstCode = null;
        Notification::assertSentTo($user, PhoneVerificationCodeNotification::class, function ($notification) use (&$firstCode) {
            $firstCode = $notification->code;
            return true;
        });

        // Second request
        Notification::fake();
        $this->postJson('/api/v1/auth/phone-verification/send')->assertStatus(200);

        $secondCode = null;
        Notification::assertSentTo($user, PhoneVerificationCodeNotification::class, function ($notification) use (&$secondCode) {
            $secondCode = $notification->code;
            return true;
        });

        $this->assertDatabaseCount('phone_verification_codes', 1);

        // First code fails
        $failResponse = $this->postJson('/api/v1/auth/phone-verification/verify', [
            'code' => $firstCode,
        ]);
        $failResponse->assertStatus(422);

        // Second code succeeds
        $successResponse = $this->postJson('/api/v1/auth/phone-verification/verify', [
            'code' => $secondCode,
        ]);
        $successResponse->assertStatus(200);
    }

    public function test_a_user_can_verify_their_phone_using_the_correct_code(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        PhoneVerificationCode::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/v1/auth/phone-verification/verify', [
            'code' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Phone number verified successfully.',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role' => $user->role,
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

        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseCount('phone_verification_codes', 0);
    }

    public function test_verification_fails_when_the_code_format_is_invalid(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/phone-verification/verify', [
            'code' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_verification_fails_when_the_code_is_incorrect(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        PhoneVerificationCode::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/v1/auth/phone-verification/verify', [
            'code' => '654321',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The verification code is invalid or has expired.',
            ]);

        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_verification_fails_when_the_code_has_expired(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        PhoneVerificationCode::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->postJson('/api/v1/auth/phone-verification/verify', [
            'code' => '123456',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The verification code is invalid or has expired.',
            ]);

        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseCount('phone_verification_codes', 0);
    }

    public function test_a_verification_code_cannot_be_reused(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        PhoneVerificationCode::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        // First verification succeeds
        $firstResponse = $this->postJson('/api/v1/auth/phone-verification/verify', [
            'code' => '123456',
        ]);
        $firstResponse->assertStatus(200);

        // Second verification fails with 409 because phone is already verified
        $secondResponse = $this->postJson('/api/v1/auth/phone-verification/verify', [
            'code' => '123456',
        ]);
        $secondResponse->assertStatus(409)
            ->assertJson([
                'message' => 'Phone number is already verified.',
            ]);
    }

    public function test_an_already_verified_user_cannot_request_another_code(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'phone_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/phone-verification/send');

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'Phone number is already verified.',
            ]);

        $this->assertDatabaseCount('phone_verification_codes', 0);
        Notification::assertNothingSent();
    }
}
