<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\WilayaController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminProductController;
use App\Http\Controllers\Api\Admin\AdminCategoryController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public direct routes
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::post('/orders', [CheckoutController::class, 'store'])->middleware('throttle:30,1');
Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:30,1');

Route::prefix('v1')->group(function () {
    // Public routes
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{slug}', [ProductController::class, 'show']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show'])->middleware('throttle:60,1');
    Route::post('/orders', [CheckoutController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/wilayas', [WilayaController::class, 'index']);
    Route::get('/wilayas/{code}/communes', [WilayaController::class, 'communes'])->middleware('throttle:60,1');
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1');

    // Auth routes
    Route::get('/auth/status', [AuthController::class, 'checkAdminStatus']);
    Route::post('/auth/register-admin', [AuthController::class, 'registerFirstAdmin']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Admin routes (protected via 'auth:sanctum' - Laravel Sanctum)
    Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
        // Auth me & logout
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Analytics
        Route::get('/stats', [DashboardController::class, 'stats']);
        Route::get('/analytics/metrics', [AdminDashboardController::class, 'metrics']);
        Route::get('/analytics/revenue', [AdminDashboardController::class, 'revenueSeries']);
        Route::get('/analytics/top-products', [AdminDashboardController::class, 'topProducts']);
        Route::get('/analytics/top-wilayas', [AdminDashboardController::class, 'topWilayas']);

        // Order management
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::delete('/orders/{id}', [AdminOrderController::class, 'destroy']);
        Route::get('/orders/export', [AdminOrderController::class, 'export']);
        Route::put('/orders/bulk-status', [AdminOrderController::class, 'bulkUpdateStatus']);
        Route::put('/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);

        // Catalog management
        Route::get('/categories', [AdminCategoryController::class, 'index']);
        Route::post('/categories', [AdminCategoryController::class, 'store']);
        Route::get('/categories/{id}', [AdminCategoryController::class, 'show']);
        Route::put('/categories/{id}', [AdminCategoryController::class, 'update']);
        Route::delete('/categories/{id}', [AdminCategoryController::class, 'destroy']);

        // Products (catalog CRUD)
        Route::get('/products', [AdminProductController::class, 'index']);
        Route::post('/products', [AdminProductController::class, 'store']);
        Route::get('/products/{id}', [AdminProductController::class, 'show']);
        Route::put('/products/{id}', [AdminProductController::class, 'update']);
        Route::delete('/products/{id}', [AdminProductController::class, 'destroy']);

        // ConnectedDZ scraper
        Route::post('/scrape/connected-dz', [AdminProductController::class, 'scrape']);
    });
});

Broadcast::routes();