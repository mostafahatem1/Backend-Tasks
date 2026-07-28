<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordResetController extends Controller
{
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('phone', $validated['phone'])->first();

        if ($user) {
            $code = (string) random_int(100000, 999999);
            $tokenHash = Hash::make($code);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['phone' => $user->phone],
                [
                    'token' => $tokenHash,
                    'created_at' => now(),
                ]
            );

            $user->notify(new PasswordResetCodeNotification($code));
        }

        return apiResponse(
            message: 'If the phone number is registered, a password reset code has been sent.',
            status: 200
        );
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($validated) {
            $user = User::where('phone', $validated['phone'])->lockForUpdate()->first();
            $record = DB::table('password_reset_tokens')->where('phone', $validated['phone'])->lockForUpdate()->first();

            if (! $user || ! $record) {
                return apiResponse(
                    message: 'The password reset code is invalid or has expired.',
                    status: 422
                );
            }

            $createdAt = Carbon::parse($record->created_at);
            if ($createdAt->addMinutes(10)->isPast()) {
                DB::table('password_reset_tokens')->where('phone', $validated['phone'])->delete();

                return apiResponse(
                    message: 'The password reset code is invalid or has expired.',
                    status: 422
                );
            }

            if (! Hash::check($validated['code'], $record->token)) {
                return apiResponse(
                    message: 'The password reset code is invalid or has expired.',
                    status: 422
                );
            }

            $user->password = $validated['password'];
            $user->save();

            DB::table('password_reset_tokens')->where('phone', $validated['phone'])->delete();

            $user->tokens()->delete();

            return apiResponse(
                message: 'Password reset successfully.',
                status: 200
            );
        });
    }
}
