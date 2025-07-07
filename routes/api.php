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
        Route::get('/list-categories', 'index');               // List all categories
        Route::get('/categories/{id}', 'show');            // Get category by ID
        Route::get('/slug/{slug}', 'showBySlug'); // (Optional) Get category by slug

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/add-categories', 'storeCategory');       // Create category
        Route::put('/update-categories/{id}', 'updateCategory');      // Update category
        Route::delete('/delete-categories/{id}', 'destroyCategory');  // Delete category
        Route::delete('/delete-subcategories/{id}', 'destroyOnlySubcategories'); //Delete Subcategories
    });
});
    // ===============================
    // 🏷️ BRAND MODULE
    // ===============================
    Route::prefix('brands')->controller(BrandController::class)->group(function () {
        Route::get('/show-brands', 'index');
        Route::get('/show-brands/{id}', 'showBrands');
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/store-brands', 'storeBrands');
            Route::post('/update-brands/{id}', 'updateBrands');
            Route::delete('/delete-brands/{id}', 'destroyBrands');
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
            Route::post('/update-product/{id}', 'updateProduct');
            Route::delete('/delete-product/{id}', 'destroyProduct');
            Route::get('/search', 'searchProduct');
        });
    });

    // ===============================
    // 🛒 CART MODULE
    // ===============================
    Route::prefix('cart')->controller(CartController::class)->middleware('auth:sanctum')->group(function () {
        Route::get('/list-cart', 'index');
        Route::post('/store-cart', 'storeCart');
        Route::put('/update-cart/{id}', 'updateCart');
        Route::delete('/delete-cart/{id}', 'destroyCart');
    });

    // ===============================
    // ❤️ WISHLIST MODULE
    // ===============================
    Route::prefix('wishlist')->controller(WishlistController::class)->middleware('auth:sanctum')->group(function () {
        Route::get('/list-wishlist', 'index');
        Route::post('/add-to-wishlist', 'store');
        Route::delete('/remove-from-wishlist/{id}', 'destroy');
    });

    // ===============================
    // 🧾 ORDER MODULE
    // ===============================
    Route::prefix('orders')->controller(OrderController::class)->middleware('auth:sanctum')->group(function () {
        Route::get('/list-order', 'index');
        Route::post('/create-order', 'store');
    });

    // ===============================
    // ⭐ PRODUCT REVIEWS
    // ===============================
    Route::prefix('reviews')->controller(ReviewController::class)->group(function () {
        Route::get('/product-reviews/{productId}', 'index');
        Route::middleware('auth:sanctum')->post('/add-review', 'store');
    });

    // ===============================
    // 🖼️ BANNERS / SLIDERS
    // ===============================
    Route::prefix('banners')->controller(BannerController::class)->group(function () {
        Route::get('/', 'index');
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/add-banner', 'storeBanner');
            Route::post('/update-banner/{id}', 'updateBanner');
            Route::delete('/delete-banner/{id}', 'destroyBanner');
        });
    });