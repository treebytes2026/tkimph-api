<?php

use App\Http\Controllers\Admin\AdminBusinessCategoryController;
use App\Http\Controllers\Admin\AdminBusinessTypeController;
use App\Http\Controllers\Admin\AdminCuisineController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminPartnerApplicationController;
use App\Http\Controllers\Admin\AdminRegistrationStatsController;
use App\Http\Controllers\Admin\AdminRestaurantController;
use App\Http\Controllers\Admin\AdminRiderApplicationController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Public\PartnerApplicationController;
use App\Http\Controllers\Public\RegistrationOptionsController;
use App\Http\Controllers\Public\RiderApplicationController;
use App\Http\Controllers\Partner\PartnerOverviewController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AuthController::class, 'adminLogin']);

Route::middleware(['throttle:5,1'])->group(function () {
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);
});

Route::middleware(['throttle:60,1'])->group(function () {
    Route::get('/public/registration-options', RegistrationOptionsController::class);
});

Route::middleware(['throttle:20,1'])->group(function () {
    Route::post('/partner-applications', [PartnerApplicationController::class, 'store']);
    Route::post('/rider-applications', [RiderApplicationController::class, 'store']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/partner/overview', [PartnerOverviewController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('registration-stats', AdminRegistrationStatsController::class);

    Route::get('notifications', [AdminNotificationController::class, 'index']);
    Route::get('notifications/unread-count', [AdminNotificationController::class, 'unreadCount']);
    Route::post('notifications/{id}/read', [AdminNotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [AdminNotificationController::class, 'markAllRead']);

    Route::get('users', [AdminUserController::class, 'index']);
    Route::post('users', [AdminUserController::class, 'store']);
    Route::get('users/{user}', [AdminUserController::class, 'show']);
    Route::put('users/{user}', [AdminUserController::class, 'update']);
    Route::patch('users/{user}', [AdminUserController::class, 'update']);
    Route::delete('users/{user}', [AdminUserController::class, 'destroy']);
    Route::patch('users/{user}/toggle-active', [AdminUserController::class, 'toggleActive']);

    Route::get('restaurants/partners', [AdminRestaurantController::class, 'partners']);
    Route::get('restaurants', [AdminRestaurantController::class, 'index']);
    Route::post('restaurants', [AdminRestaurantController::class, 'store']);
    Route::get('restaurants/{restaurant}', [AdminRestaurantController::class, 'show']);
    Route::put('restaurants/{restaurant}', [AdminRestaurantController::class, 'update']);
    Route::patch('restaurants/{restaurant}', [AdminRestaurantController::class, 'update']);
    Route::delete('restaurants/{restaurant}', [AdminRestaurantController::class, 'destroy']);
    Route::patch('restaurants/{restaurant}/toggle-active', [AdminRestaurantController::class, 'toggleActive']);

    Route::apiResource('business-types', AdminBusinessTypeController::class);
    Route::apiResource('business-categories', AdminBusinessCategoryController::class);
    Route::apiResource('cuisines', AdminCuisineController::class);

    Route::get('partner-applications', [AdminPartnerApplicationController::class, 'index']);
    Route::get('partner-applications/{partnerApplication}', [AdminPartnerApplicationController::class, 'show']);
    Route::post('partner-applications/{partnerApplication}/approve', [AdminPartnerApplicationController::class, 'approve']);
    Route::post('partner-applications/{partnerApplication}/reject', [AdminPartnerApplicationController::class, 'reject']);

    Route::get('rider-applications', [AdminRiderApplicationController::class, 'index']);
    Route::get('rider-applications/{riderApplication}', [AdminRiderApplicationController::class, 'show']);
    Route::post('rider-applications/{riderApplication}/approve', [AdminRiderApplicationController::class, 'approve']);
    Route::post('rider-applications/{riderApplication}/reject', [AdminRiderApplicationController::class, 'reject']);
});
