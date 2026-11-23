<?php

use App\Http\Controllers\AdminUserActivityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CafeController;
use App\Http\Controllers\CafeFavouriteController;
use App\Http\Controllers\CafeReviewCommentController;
use App\Http\Controllers\CafeReviewController;
use App\Http\Controllers\CafeRewardController;
use App\Http\Controllers\CafeVisitMemoryController;
use App\Http\Controllers\CafeYatraController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OwnerCustomerActivityController;
use App\Http\Controllers\PersonalActivityChecklistController;
use App\Http\Controllers\PersonalActivityController;
use App\Http\Controllers\PersonalActivityTimerController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CaféVerse API
|--------------------------------------------------------------------------
| Roles: "user" (customer), "cafe_owner" and "admin".
| Every route except register/login needs a Sanctum bearer token.
| Route parameters are numeric only (whereNumber), so /cafes/abc is a 404.
*/

Route::pattern('id', '[0-9]+');
Route::pattern('cafeId', '[0-9]+');
Route::pattern('reviewId', '[0-9]+');
Route::pattern('commentId', '[0-9]+');
Route::pattern('visitId', '[0-9]+');
Route::pattern('memoryId', '[0-9]+');
Route::pattern('rewardId', '[0-9]+');
Route::pattern('activityId', '[0-9]+');
Route::pattern('taskId', '[0-9]+');

// ---------------------------------------------------------------------
// Authentication (rate limited against password guessing)
// ---------------------------------------------------------------------
Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {

    // -----------------------------------------------------------------
    // Every signed-in user (customer, café owner and admin)
    // -----------------------------------------------------------------
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::put('/profile', [ProfileController::class, 'updateProfile']);
    Route::put('/profile/password', [ProfileController::class, 'changePassword'])->middleware('throttle:sensitive');
    Route::post('/profile/photo', [ProfileController::class, 'updatePhoto']);
    Route::delete('/profile/photo', [ProfileController::class, 'deletePhoto']);

    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::apiResource('notifications', NotificationController::class)
        ->only(['index', 'destroy'])
        ->parameters(['notifications' => 'id']);

    // Approved café browsing and reviews
    Route::apiResource('cafes', CafeController::class)
        ->only(['index', 'show'])
        ->parameters(['cafes' => 'id']);
    Route::get('/cafes/{cafeId}/reviews', [CafeReviewController::class, 'index']);

    // Review discussion (only the reviewer and the café owner may post)
    Route::post('/cafe-reviews/{reviewId}/comments', [CafeReviewCommentController::class, 'store']);
    Route::apiResource('cafe-review-comments', CafeReviewCommentController::class)
        ->only(['update', 'destroy'])
        ->parameters(['cafe-review-comments' => 'commentId']);

    // -----------------------------------------------------------------
    // Customers
    // -----------------------------------------------------------------
    Route::middleware('role:user')->group(function () {

        Route::apiResource('cafe-reviews', CafeReviewController::class)
            ->only(['store', 'update', 'destroy'])
            ->parameters(['cafe-reviews' => 'reviewId']);

        Route::apiResource('favourites', CafeFavouriteController::class)
            ->only(['index', 'store', 'destroy'])
            ->parameters(['favourites' => 'cafeId']);

        // Café Yatra: GPS check-in, QR checkout, badges, rewards and memories
        Route::prefix('cafe-yatra')->group(function () {
            Route::post('/check-in', [CafeYatraController::class, 'checkIn']);
            Route::post('/checkout', [CafeYatraController::class, 'checkout']);
            Route::get('/active-visit', [CafeYatraController::class, 'activeVisit']);
            Route::get('/visits', [CafeYatraController::class, 'completedVisits']);
            Route::get('/badges', [CafeYatraController::class, 'earnedBadges']);
            Route::get('/cafes/{cafeId}/badges', [CafeYatraController::class, 'cafeBadges']);
            Route::get('/cafes/{cafeId}/rewards', [CafeYatraController::class, 'cafeRewards']);

            // Memories: listed/created under a visit, edited/deleted on their own (shallow nesting).
            Route::apiResource('visits.memories', CafeVisitMemoryController::class)
                ->only(['index', 'store'])
                ->parameters(['visits' => 'visitId']);
            Route::apiResource('memories', CafeVisitMemoryController::class)
                ->only(['update', 'destroy'])
                ->parameters(['memories' => 'memoryId']);
        });

        // Personal goals and challenges
        // Full CRUD: index, store, show, update (PUT + PATCH), destroy
        Route::apiResource('personal-activities', PersonalActivityController::class)
            ->parameters(['personal-activities' => 'id']);

        Route::apiResource('personal-activities.checklist', PersonalActivityChecklistController::class)
            ->except('show')
            ->parameters(['personal-activities' => 'activityId', 'checklist' => 'taskId']);

        // Timer actions are not CRUD, so they stay as named routes.
        Route::prefix('personal-activities')->group(function () {
            Route::get('/{activityId}/timer', [PersonalActivityTimerController::class, 'show']);
            Route::post('/{activityId}/timer/start', [PersonalActivityTimerController::class, 'start']);
            Route::post('/{activityId}/timer/pause', [PersonalActivityTimerController::class, 'pause']);
            Route::post('/{activityId}/timer/resume', [PersonalActivityTimerController::class, 'resume']);
            Route::post('/{activityId}/timer/reset', [PersonalActivityTimerController::class, 'reset']);
            Route::post('/{activityId}/timer/heartbeat', [PersonalActivityTimerController::class, 'heartbeat']);
        });
    });

    // -----------------------------------------------------------------
    // Café owners
    // -----------------------------------------------------------------
    Route::middleware('role:cafe_owner')->group(function () {

        Route::apiResource('cafes', CafeController::class)
            ->only(['store', 'update', 'destroy'])
            ->parameters(['cafes' => 'id']);

        Route::get('/my-cafes', [CafeController::class, 'myCafes']);
        Route::get('/my-cafes/{id}', [CafeController::class, 'myCafe']);
        Route::patch('/my-cafes/{id}/crowd', [CafeController::class, 'updateCrowd']);
        Route::get('/my-cafe-qrs', [CafeController::class, 'myCafeQrs']);

        Route::prefix('owner')->group(function () {
            // Rewards: listed/created under a café, edited/deleted on their own (shallow nesting).
            Route::apiResource('cafes.rewards', CafeRewardController::class)
                ->only(['index', 'store'])
                ->parameters(['cafes' => 'cafeId'])
                ->names('owner.cafes.rewards');
            Route::apiResource('rewards', CafeRewardController::class)
                ->only(['update', 'destroy'])
                ->parameters(['rewards' => 'rewardId'])
                ->names('owner.rewards');

            Route::post('/rewards/verify-redemption', [CafeRewardController::class, 'verifyRedemption']);
            Route::post('/rewards/confirm-redemption', [CafeRewardController::class, 'confirmRedemption']);

            Route::apiResource('customers', OwnerCustomerActivityController::class)
                ->only(['index', 'show'])
                ->parameters(['customers' => 'id'])
                ->names('owner.customers');
        });
    });

    // -----------------------------------------------------------------
    // Admins
    // -----------------------------------------------------------------
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/cafes', [CafeController::class, 'adminIndex']);
        Route::get('/cafes/pending', [CafeController::class, 'pendingCafes']);
        Route::get('/cafes/statistics', [CafeController::class, 'statistics']);
        Route::patch('/cafes/{id}/approve', [CafeController::class, 'approveCafe']);
        Route::patch('/cafes/{id}/reject', [CafeController::class, 'rejectCafe']);
        Route::delete('/cafes/{id}', [CafeController::class, 'adminDestroy']);

        Route::apiResource('users', AdminUserActivityController::class)
            ->only(['index', 'show'])
            ->parameters(['users' => 'id'])
            ->names('admin.users');
    });
});
