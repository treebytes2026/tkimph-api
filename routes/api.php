<?php

use App\Http\Controllers\Admin\AdminBusinessCategoryController;
use App\Http\Controllers\Admin\AdminBusinessTypeController;
use App\Http\Controllers\Admin\AdminCuisineController;
use App\Http\Controllers\Admin\AdminCommissionCollectionController;
use App\Http\Controllers\Admin\AdminMenuCategoryController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminPartnerApplicationController;
use App\Http\Controllers\Admin\AdminPromotionController;
use App\Http\Controllers\Admin\AdminRegistrationStatsController;
use App\Http\Controllers\Admin\AdminRiderController;
use App\Http\Controllers\Admin\AdminRestaurantController;
use App\Http\Controllers\Admin\AdminRiderApplicationController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminSettlementController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Customer\CustomerAccountController;
use App\Http\Controllers\Customer\CustomerOrderController;
use App\Http\Controllers\Public\PartnerApplicationController;
use App\Http\Controllers\Public\PublicDirectoryController;
use App\Http\Controllers\Public\RegistrationOptionsController;
use App\Http\Controllers\Public\RiderApplicationController;
use App\Http\Controllers\Public\RiderApplicationDocumentController;
use App\Http\Controllers\Rider\RiderOrderController;
use App\Http\Controllers\Rider\RiderProfileController;
use App\Http\Controllers\Partner\PartnerMenuCategoryController;
use App\Http\Controllers\Partner\PartnerMenuController;
use App\Http\Controllers\Partner\PartnerMenuItemController;
use App\Http\Controllers\Partner\PartnerNotificationController;
use App\Http\Controllers\Partner\PartnerOrderController;
use App\Http\Controllers\Partner\PartnerOverviewController;
use App\Http\Controllers\Partner\PartnerPasswordController;
use App\Http\Controllers\Partner\PartnerCommissionCollectionController;
use App\Http\Controllers\Partner\PartnerPromotionController;
use App\Http\Controllers\Partner\PartnerProfileController;
use App\Http\Controllers\Partner\PartnerRestaurantImageController;
use App\Http\Controllers\Partner\PartnerRestaurantProfileController;
use App\Http\Controllers\Partner\PartnerRestaurantProfilePhotoController;
use App\Http\Controllers\Partner\PartnerSettlementController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Http\Request;

Route::middleware('throttle:login')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/admin/login', [AuthController::class, 'adminLogin']);
});

Route::middleware(['throttle:passwords'])->group(function () {
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);
});

Route::middleware(['throttle:public'])->group(function () {
    Route::get('/public/registration-options', RegistrationOptionsController::class);
    Route::get('/public/cuisines', [PublicDirectoryController::class, 'cuisines']);
    Route::get('/public/restaurants/{slug}', [PublicDirectoryController::class, 'show']);
    Route::get('/public/restaurants', [PublicDirectoryController::class, 'restaurants']);
    Route::get('/public/restaurants-menu-feed', [PublicDirectoryController::class, 'restaurantsMenuFeed']);
});

Route::middleware(['throttle:applications'])->group(function () {
    Route::post('/partner-applications', [PartnerApplicationController::class, 'store']);
    Route::post('/rider-applications', [RiderApplicationController::class, 'store']);
});
Route::middleware(['signed', 'throttle:30,1'])
    ->get('/public/rider-applications/{riderApplication}/documents/{type}', [RiderApplicationDocumentController::class, 'show'])
    ->name('public.rider-applications.documents.show');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/broadcasting/auth', function (Request $request) {
        return Broadcast::auth($request);
    });

    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/customer/profile', [CustomerAccountController::class, 'show']);
    Route::patch('/customer/profile', [CustomerAccountController::class, 'updateProfile']);
    Route::post('/customer/email/send-verification', [CustomerAccountController::class, 'sendEmailVerificationCode']);
    Route::post('/customer/email/verify', [CustomerAccountController::class, 'verifyEmailCode']);
    Route::post('/customer/phone/send-verification', [CustomerAccountController::class, 'sendPhoneVerificationCode']);
    Route::post('/customer/phone/verify', [CustomerAccountController::class, 'verifyPhoneCode']);
    Route::post('/customer/change-password', [CustomerAccountController::class, 'changePassword']);
    Route::delete('/customer/account', [CustomerAccountController::class, 'destroy']);
    Route::post('/customer/help-center', [CustomerAccountController::class, 'submitHelpCenterConcern']);
    Route::get('/customer/orders', [CustomerOrderController::class, 'index']);
    Route::get('/customer/orders/{order}', [CustomerOrderController::class, 'show']);
    Route::post('/customer/orders', [CustomerOrderController::class, 'store']);
    Route::post('/customer/promotions/validate', [CustomerOrderController::class, 'validatePromotion']);
    Route::post('/customer/orders/{order}/cancel-request', [CustomerOrderController::class, 'requestCancel']);
    Route::post('/customer/orders/{order}/issues', [CustomerOrderController::class, 'storeIssue']);
    Route::post('/customer/orders/{order}/reviews', [CustomerOrderController::class, 'storeReview']);
    Route::post('/customer/orders/{order}/item-reviews', [CustomerOrderController::class, 'storeItemReview']);
    Route::post('/customer/reviews/{review}/report', [CustomerOrderController::class, 'reportReview']);
    Route::get('/partner/overview', [PartnerOverviewController::class, 'show']);
    Route::get('/partner/orders', [PartnerOrderController::class, 'index']);
    Route::patch('/partner/orders/{order}/status', [PartnerOrderController::class, 'updateStatus']);
    Route::get('/partner/earnings', [PartnerOrderController::class, 'earnings']);
    Route::get('/partner/commission-collections', [PartnerCommissionCollectionController::class, 'index']);
    Route::post('/partner/commission-collections/{collection}/payment-proof', [PartnerCommissionCollectionController::class, 'submitPaymentProof'])->middleware('throttle:uploads');
    Route::get('/partner/settlements', [PartnerSettlementController::class, 'index']);
    Route::post('/partner/settlements/{settlement}/payment-proof', [PartnerSettlementController::class, 'submitPaymentProof'])->middleware('throttle:uploads');
    Route::get('/partner/notifications', [PartnerNotificationController::class, 'index']);
    Route::get('/partner/notifications/unread-count', [PartnerNotificationController::class, 'unreadCount']);
    Route::post('/partner/notifications/{id}/read', [PartnerNotificationController::class, 'markRead']);
    Route::post('/partner/notifications/read-all', [PartnerNotificationController::class, 'markAllRead']);
    Route::patch('/partner/profile', [PartnerProfileController::class, 'update']);
    Route::post('/partner/change-password', [PartnerPasswordController::class, 'update']);
    Route::patch('/partner/restaurants/{restaurant}', [PartnerRestaurantProfileController::class, 'update']);
    Route::patch('/partner/restaurants/{restaurant}/availability', [PartnerRestaurantProfileController::class, 'updateAvailability']);
    Route::post('/partner/restaurants/{restaurant}/profile-image', [PartnerRestaurantProfilePhotoController::class, 'store'])->middleware('throttle:uploads');
    Route::delete('/partner/restaurants/{restaurant}/profile-image', [PartnerRestaurantProfilePhotoController::class, 'destroy']);
    Route::post('/partner/restaurants/{restaurant}/location-images', [PartnerRestaurantImageController::class, 'store'])->middleware('throttle:uploads');
    Route::delete('/partner/restaurants/{restaurant}/location-images/{image}', [PartnerRestaurantImageController::class, 'destroy']);
    Route::get('/partner/restaurants/{restaurant}/promotions', [PartnerPromotionController::class, 'index']);
    Route::post('/partner/restaurants/{restaurant}/promotions', [PartnerPromotionController::class, 'store']);
    Route::patch('/partner/restaurants/{restaurant}/promotions/{promotion}', [PartnerPromotionController::class, 'update']);
    Route::delete('/partner/restaurants/{restaurant}/promotions/{promotion}', [PartnerPromotionController::class, 'destroy']);
    Route::get('/partner/menu-categories', [PartnerMenuCategoryController::class, 'index']);

    Route::get('/partner/restaurants/{restaurant}/menus', [PartnerMenuController::class, 'index']);
    Route::post('/partner/restaurants/{restaurant}/menus', [PartnerMenuController::class, 'store']);
    Route::get('/partner/restaurants/{restaurant}/menus/{menu}', [PartnerMenuController::class, 'show']);
    Route::patch('/partner/restaurants/{restaurant}/menus/{menu}', [PartnerMenuController::class, 'update']);
    Route::delete('/partner/restaurants/{restaurant}/menus/{menu}', [PartnerMenuController::class, 'destroy']);

    Route::post('/partner/restaurants/{restaurant}/menus/{menu}/items', [PartnerMenuItemController::class, 'store']);
    Route::patch('/partner/restaurants/{restaurant}/menus/{menu}/items/{item}', [PartnerMenuItemController::class, 'update']);
    Route::delete('/partner/restaurants/{restaurant}/menus/{menu}/items/{item}', [PartnerMenuItemController::class, 'destroy']);
    Route::post('/partner/restaurants/{restaurant}/menus/{menu}/items/{item}/image', [PartnerMenuItemController::class, 'uploadImage'])->middleware('throttle:uploads');
    Route::delete('/partner/restaurants/{restaurant}/menus/{menu}/items/{item}/image', [PartnerMenuItemController::class, 'deleteImage']);

    Route::get('/rider/overview', [RiderOrderController::class, 'overview']);
    Route::get('/rider/orders', [RiderOrderController::class, 'index']);
    Route::get('/rider/orders/available', [RiderOrderController::class, 'available']);
    Route::get('/rider/profile', [RiderProfileController::class, 'show']);
    Route::patch('/rider/profile', [RiderProfileController::class, 'update']);
    Route::post('/rider/change-password', [RiderProfileController::class, 'changePassword']);
    Route::patch('/rider/availability', [RiderOrderController::class, 'setAvailability']);
    Route::post('/rider/orders/{order}/claim', [RiderOrderController::class, 'claim']);
    Route::patch('/rider/orders/{order}/status', [RiderOrderController::class, 'updateStatus']);
    Route::post('/rider/orders/{order}/location', [RiderOrderController::class, 'storeLocation']);

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
    Route::patch('restaurants/{restaurant}/operating-status', [AdminRestaurantController::class, 'updateOperatingStatus']);
    Route::patch('restaurants/{restaurant}/public-order-override', [AdminRestaurantController::class, 'setPublicOrderOverride']);
    Route::get('restaurants/{restaurant}/settlement-summary', [AdminRestaurantController::class, 'settlementSummary']);
    Route::post('restaurants/{restaurant}/support-notes', [AdminRestaurantController::class, 'storeSupportNote']);

    Route::apiResource('business-types', AdminBusinessTypeController::class);
    Route::apiResource('business-categories', AdminBusinessCategoryController::class);
    Route::apiResource('cuisines', AdminCuisineController::class);
    Route::apiResource('menu-categories', AdminMenuCategoryController::class);
    Route::get('promotions', [AdminPromotionController::class, 'index']);
    Route::post('promotions', [AdminPromotionController::class, 'store']);
    Route::patch('promotions/{promotion}', [AdminPromotionController::class, 'update']);
    Route::delete('promotions/{promotion}', [AdminPromotionController::class, 'destroy']);

    Route::get('partner-applications', [AdminPartnerApplicationController::class, 'index']);
    Route::get('partner-applications/{partnerApplication}', [AdminPartnerApplicationController::class, 'show']);
    Route::post('partner-applications/{partnerApplication}/approve', [AdminPartnerApplicationController::class, 'approve']);
    Route::post('partner-applications/{partnerApplication}/reject', [AdminPartnerApplicationController::class, 'reject']);

    Route::get('rider-applications', [AdminRiderApplicationController::class, 'index']);
    Route::get('rider-applications/{riderApplication}', [AdminRiderApplicationController::class, 'show']);
    Route::post('rider-applications/{riderApplication}/approve', [AdminRiderApplicationController::class, 'approve']);
    Route::post('rider-applications/{riderApplication}/reject', [AdminRiderApplicationController::class, 'reject']);

    Route::get('orders/summary', [AdminOrderController::class, 'summary']);
    Route::get('orders', [AdminOrderController::class, 'index']);
    Route::get('orders/{order}', [AdminOrderController::class, 'show']);
    Route::patch('orders/{order}/status', [AdminOrderController::class, 'updateStatus']);
    Route::patch('orders/{order}/assign-rider', [AdminOrderController::class, 'assignRider']);
    Route::post('orders/{order}/notes', [AdminOrderController::class, 'storeNote']);
    Route::post('orders/{order}/support-notes', [AdminOrderController::class, 'storeSupportNote']);

    Route::get('riders', [AdminRiderController::class, 'index']);
    Route::get('riders/{rider}', [AdminRiderController::class, 'show']);
    Route::patch('riders/{rider}/active', [AdminRiderController::class, 'setActive']);

    Route::get('settings', [AdminSettingsController::class, 'show']);
    Route::patch('settings', [AdminSettingsController::class, 'update']);
    Route::get('commission-collections', [AdminCommissionCollectionController::class, 'index']);
    Route::post('commission-collections', [AdminCommissionCollectionController::class, 'store']);
    Route::post('commission-collections/generate-all', [AdminCommissionCollectionController::class, 'storeBulk']);
    Route::post('commission-collections/{collection}/mark-received', [AdminCommissionCollectionController::class, 'markReceived']);
    Route::get('settlements', [AdminSettlementController::class, 'index']);
    Route::post('settlements', [AdminSettlementController::class, 'store']);
    Route::post('settlements/{settlement}/mark-settled', [AdminSettlementController::class, 'markSettled']);
    Route::post('settlements/{settlement}/overdue-action', [AdminSettlementController::class, 'enforceOverdue']);
});
