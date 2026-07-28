<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyPhoneRequest;
use App\Http\Resources\UserResource;
use App\Models\PhoneVerificationCode;
use App\Models\User;
use App\Notifications\PhoneVerificationCodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PhoneVerificationController extends Controller
{
    public function sendCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->phone_verified_at !== null) {
            return apiResponse(
                message: 'Phone number is already verified.',
                status: 409
            );
        }

        $code = (string) random_int(100000, 999999);

        PhoneVerificationCode::updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(10),
            ]
        );

        $user->notify(new PhoneVerificationCodeNotification($code));

        return apiResponse(
            message: 'Verification code sent successfully.',
            status: 200
        );
    }

    public function verify(VerifyPhoneRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $user = User::where('id', $request->user()->id)->lockForUpdate()->first();

            if ($user->phone_verified_at !== null) {
                return apiResponse(
                    message: 'Phone number is already verified.',
                    status: 409
                );
            }

            $record = PhoneVerificationCode::where('user_id', $user->id)->lockForUpdate()->first();

            if (! $record) {
                return apiResponse(
                    message: 'The verification code is invalid or has expired.',
                    status: 422
                );
            }

            if ($record->expires_at->isPast()) {
                $record->delete();

                return apiResponse(
                    message: 'The verification code is invalid or has expired.',
                    status: 422
                );
            }

            if (! Hash::check($request->validated('code'), $record->code_hash)) {
                return apiResponse(
                    message: 'The verification code is invalid or has expired.',
                    status: 422
                );
            }

            $user->phone_verified_at = now();
            $user->save();

            $record->delete();

            return apiResponse(
                data: new UserResource($user->fresh()),
                message: 'Phone number verified successfully.',
                status: 200
            );
        });
    }
}
