<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\BrandController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\CartController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\WishlistController;
use App\Http\Controllers\API\ReviewController;
use App\Http\Controllers\API\BannerController;
use App\Http\Controllers\API\SearchController;

    // 🔐 Public route to fetch current user if authenticated
    Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
        return $request->user();
    });

    // ===============================
    // 🔐 AUTH CONTROLLER ROUTES
    // ===============================
    Route::prefix('user')->controller(AuthController::class)->group(function () {
        Route::post('/register', 'register');
        Route::post('/login', 'login');
        Route::post('/forgot-password', 'forgotPassword');
        Route::post('/reset-password', 'resetPassword');
        Route::middleware('auth:sanctum')->post('/logout', 'logout');
    });

    // ===============================
    // 📁 CATEGORY MODULE
    // ===============================
   Route::prefix('categories')->controller(CategoryController::class)->group(function () {
        Route::get('/', 'index');               // List all categories
        Route::get('/{id}', 'show');            // Get category by ID
        Route::get('/slug/{slug}', 'showBySlug'); // (Optional) Get category by slug

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/add-categories', 'storeCategory');       // Create category
        Route::put('/update-category/{id}', 'updateCategory');      // Update category
        Route::delete('/delete-category/{id}', 'destroyCategory');  // Delete category
    });
});
    // ===============================
    // 🏷️ BRAND MODULE
    // ===============================
    Route::prefix('brands')->controller(BrandController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/{id}', 'show');
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/', 'store');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });
    });

    // ===============================
    // 📦 PRODUCT MODULE
    // ===============================
    Route::prefix('products')->controller(ProductController::class)->group(function () {
        Route::get('/get-product', 'index');
        Route::get('/show/{slug}', 'showProduct');
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/add-product', 'addProduct');
            Route::put('/update-product/{id}', 'updateProduct');
            Route::delete('/delete-product{id}', 'destroyProduct');
            Route::get('/search', 'searchProduct');
        });
    });

    // ===============================
    // 🛒 CART MODULE
    // ===============================
    Route::prefix('cart')->controller(CartController::class)->middleware('auth:sanctum')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{id}', 'update');
        Route::delete('/{id}', 'destroy');
    });

    // ===============================
    // ❤️ WISHLIST MODULE
    // ===============================
    Route::prefix('wishlist')->controller(WishlistController::class)->middleware('auth:sanctum')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::delete('/{id}', 'destroy');
    });

    // ===============================
    // 🧾 ORDER MODULE
    // ===============================
    Route::prefix('orders')->controller(OrderController::class)->middleware('auth:sanctum')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
    });

    // ===============================
    // ⭐ PRODUCT REVIEWS
    // ===============================
    Route::prefix('reviews')->controller(ReviewController::class)->group(function () {
        Route::get('/product/{product_id}', 'index');
        Route::middleware('auth:sanctum')->post('/', 'store');
    });

    // ===============================
    // 🖼️ BANNERS / SLIDERS
    // ===============================
    Route::prefix('banners')->controller(BannerController::class)->group(function () {
        Route::get('/', 'index');
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/add-banner', 'storeBanner');
            Route::put('/update-banner/{id}', 'updateBanner');
            Route::delete('/delete-banner/{id}', 'destroyBanner');
        });
    });