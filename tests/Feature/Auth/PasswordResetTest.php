<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_registered_user_can_request_a_password_reset_code(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'phone' => '+1234567890',
        ]);

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'phone' => '+1234567890',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'If the phone number is registered, a password reset code has been sent.',
            ])
            ->assertJsonMissingPath('code')
            ->assertJsonMissingPath('data.code');

        $this->assertDatabaseHas('password_reset_tokens', [
            'phone' => '+1234567890',
        ]);

        $record = DB::table('password_reset_tokens')->where('phone', '+1234567890')->first();
        $this->assertNotNull($record);
        $this->assertNotEquals('123456', $record->token);

        Notification::assertSentTo(
            $user,
            PasswordResetCodeNotification::class,
            function (PasswordResetCodeNotification $notification) use ($record) {
                return Hash::check($notification->code, $record->token);
            }
        );
    }

    public function test_an_unknown_phone_receives_the_same_forgot_password_response(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'phone' => '+1999999999',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'If the phone number is registered, a password reset code has been sent.',
            ]);

        $this->assertDatabaseMissing('password_reset_tokens', [
            'phone' => '+1999999999',
        ]);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_validation_rejects_missing_or_invalid_phone(): void
    {
        $missingResponse = $this->postJson('/api/v1/auth/password/forgot', []);
        $missingResponse->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $invalidResponse = $this->postJson('/api/v1/auth/password/forgot', [
            'phone' => '1234',
        ]);
        $invalidResponse->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_requesting_another_reset_code_replaces_the_previous_code(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'phone' => '+1234567890',
        ]);

        // First request
        $this->postJson('/api/v1/auth/password/forgot', ['phone' => '+1234567890'])->assertStatus(200);

        $firstCode = null;
        Notification::assertSentTo($user, PasswordResetCodeNotification::class, function ($notification) use (&$firstCode) {
            $firstCode = $notification->code;
            return true;
        });

        // Second request
        Notification::fake();
        $this->postJson('/api/v1/auth/password/forgot', ['phone' => '+1234567890'])->assertStatus(200);

        $secondCode = null;
        Notification::assertSentTo($user, PasswordResetCodeNotification::class, function ($notification) use (&$secondCode) {
            $secondCode = $notification->code;
            return true;
        });

        $this->assertEquals(1, DB::table('password_reset_tokens')->where('phone', '+1234567890')->count());

        // First code fails
        $failResponse = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '+1234567890',
            'code' => $firstCode,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);
        $failResponse->assertStatus(422);

        // Second code succeeds
        $successResponse = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '+1234567890',
            'code' => $secondCode,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);
        $successResponse->assertStatus(200);
    }

    public function test_a_user_can_reset_their_password_using_a_valid_code(): void
    {
        $user = User::factory()->create([
            'phone' => '+1234567890',
            'password' => 'oldpassword123',
        ]);

        DB::table('password_reset_tokens')->insert([
            'phone' => '+1234567890',
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '+1234567890',
            'code' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password reset successfully.',
            ]);

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));
        $this->assertFalse(Hash::check('oldpassword123', $user->password));

        $this->assertEquals(0, DB::table('password_reset_tokens')->where('phone', '+1234567890')->count());
    }

    public function test_reset_password_validation_rejects_invalid_inputs(): void
    {
        $response = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '1234',
            'code' => '12',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone', 'code', 'password']);
    }

    public function test_reset_fails_with_an_incorrect_code(): void
    {
        $user = User::factory()->create([
            'phone' => '+1234567890',
            'password' => 'oldpassword123',
        ]);

        DB::table('password_reset_tokens')->insert([
            'phone' => '+1234567890',
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '+1234567890',
            'code' => '654321',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The password reset code is invalid or has expired.',
            ]);

        $this->assertTrue(Hash::check('oldpassword123', $user->fresh()->password));
    }

    public function test_reset_fails_with_an_expired_code(): void
    {
        $user = User::factory()->create([
            'phone' => '+1234567890',
            'password' => 'oldpassword123',
        ]);

        DB::table('password_reset_tokens')->insert([
            'phone' => '+1234567890',
            'token' => Hash::make('123456'),
            'created_at' => now()->subMinutes(11),
        ]);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '+1234567890',
            'code' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The password reset code is invalid or has expired.',
            ]);

        $this->assertTrue(Hash::check('oldpassword123', $user->fresh()->password));
        $this->assertEquals(0, DB::table('password_reset_tokens')->where('phone', '+1234567890')->count());
    }

    public function test_reset_fails_when_the_phone_does_not_exist(): void
    {
        $response = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '+1999999999',
            'code' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The password reset code is invalid or has expired.',
            ]);
    }

    public function test_a_reset_code_cannot_be_reused(): void
    {
        $user = User::factory()->create([
            'phone' => '+1234567890',
            'password' => 'oldpassword123',
        ]);

        DB::table('password_reset_tokens')->insert([
            'phone' => '+1234567890',
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        // First attempt succeeds
        $firstResponse = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '+1234567890',
            'code' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);
        $firstResponse->assertStatus(200);

        // Second attempt fails
        $secondResponse = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '+1234567890',
            'code' => '123456',
            'password' => 'anotherpassword123',
            'password_confirmation' => 'anotherpassword123',
        ]);
        $secondResponse->assertStatus(422)
            ->assertJson([
                'message' => 'The password reset code is invalid or has expired.',
            ]);
    }

    public function test_existing_sanctum_tokens_are_revoked_after_a_successful_reset(): void
    {
        $user = User::factory()->create([
            'phone' => '+1234567890',
        ]);

        $user->createToken('token1');
        $user->createToken('token2');

        $this->assertEquals(2, $user->tokens()->count());

        DB::table('password_reset_tokens')->insert([
            'phone' => '+1234567890',
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '+1234567890',
            'code' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, $user->tokens()->count());
    }

    public function test_password_reset_does_not_change_phone_verified_at(): void
    {
        // Test with unverified user
        $unverifiedUser = User::factory()->unverified()->create([
            'phone' => '+1234567801',
        ]);
        $this->assertNull($unverifiedUser->phone_verified_at);

        DB::table('password_reset_tokens')->insert([
            'phone' => '+1234567801',
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '+1234567801',
            'code' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(200);

        $this->assertNull($unverifiedUser->fresh()->phone_verified_at);

        // Test with verified user
        $verifiedAt = now()->subDays(5);
        $verifiedUser = User::factory()->create([
            'phone' => '+1234567802',
            'phone_verified_at' => $verifiedAt,
        ]);

        DB::table('password_reset_tokens')->insert([
            'phone' => '+1234567802',
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '+1234567802',
            'code' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(200);

        $this->assertEquals(
            $verifiedAt->toDateTimeString(),
            $verifiedUser->fresh()->phone_verified_at->toDateTimeString()
        );
    }
}
