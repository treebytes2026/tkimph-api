<?php

use App\Http\Controllers\Admin\AdminRestaurantController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AuthController::class, 'adminLogin']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
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
});
