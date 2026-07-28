<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\PhoneVerificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/phone-verification/send', [PhoneVerificationController::class, 'sendCode'])->name('auth.phone-verification.send');
    Route::post('/auth/phone-verification/verify', [PhoneVerificationController::class, 'verify'])->name('auth.phone-verification.verify');
});
