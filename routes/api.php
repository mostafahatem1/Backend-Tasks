<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\PhoneVerificationController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductStockNotificationRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

Route::post('/auth/password/forgot', [PasswordResetController::class, 'forgotPassword'])->name('auth.password.forgot');
Route::post('/auth/password/reset', [PasswordResetController::class, 'resetPassword'])->name('auth.password.reset');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/phone-verification/send', [PhoneVerificationController::class, 'sendCode'])->name('auth.phone-verification.send');
    Route::post('/auth/phone-verification/verify', [PhoneVerificationController::class, 'verify'])->name('auth.phone-verification.verify');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::post('/products/{product}/stock-notification-requests', [ProductStockNotificationRequestController::class, 'store'])->name('products.stock-notification-requests.store');

    Route::middleware('admin')->group(function () {
        Route::post('/admin/products', [ProductController::class, 'store'])->name('admin.products.store');
        Route::patch('/admin/products/{product}', [ProductController::class, 'update'])->name('admin.products.update');
        Route::put('/admin/products/{product}', [ProductController::class, 'update']);
        Route::delete('/admin/products/{product}', [ProductController::class, 'destroy'])->name('admin.products.destroy');
    });
});
