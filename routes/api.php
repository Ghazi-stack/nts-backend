<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\Admin\{
    BannerController, BrandController, CategoryController,
    ProductController, ProductFilterController, HomeController
};
use App\Http\Controllers\API\Web\{
    CartController, OrderController, ReviewController,
    WishlistController, StripePaymentController, StripeWebhookController
};
use App\Http\Controllers\API\AuthController;
// ===============================
// 🔐 AUTHENTICATED USER CHECK
// ===============================
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// ===============================
// 🔐 AUTH ROUTES
// ===============================


Route::prefix('user')->controller(AuthController::class)->group(function () {
    Route::post('/register', 'register');
    Route::post('/login', 'login');
    Route::post('/forgot-password', 'forgotPassword');
    Route::post('/reset-password', 'resetPassword');
    Route::middleware('auth:sanctum')->post('/logout', 'logout');
});

// ===============================
// 🛠️ ADMIN ROUTES (Protected)
// ===============================


Route::prefix('dashboard')->middleware('auth:sanctum')->group(function () {

    // 📊 Dashboard
    Route::get('/store-performance', [HomeController::class, 'storePerformance']);

    // 📁 Categories
    Route::prefix('categories')->controller(CategoryController::class)->group(function () {
        Route::get('/list-categories', 'index');
        Route::get('/category/{id}', 'show');
        Route::get('/slug/{slug}', 'showBySlug');
        Route::post('/add-categories', 'storeCategory');
        Route::put('/update-categories/{id}', 'updateCategory');
        Route::delete('/delete-categories', 'destroyCategory'); // ✅ fixed: accepts body { ids: [] }
        Route::delete('/delete-subcategories/{id}', 'destroyOnlySubcategories');
    });

    // 🏷️ Brands
    Route::prefix('brands')->controller(BrandController::class)->group(function () {
        Route::get('/brand-list', 'index');
        Route::get('/brand/{id}', 'showBrands');
        Route::post('/add-brand', 'storeBrands');
        Route::post('/update-brand/{id}', 'updateBrands');
        Route::delete('/delete-brand/{id}', 'destroyBrands');
    });

    // 📦 Products
    Route::prefix('products')->controller(ProductController::class)->group(function () {
        Route::get('/products-list', 'index');
        Route::get('/show-product/{slug}', 'showProduct');
        Route::post('/add-product', 'addProduct');
        Route::put('/update-product', 'updateProduct');
        Route::delete('/delete-product/{id}', 'destroyProduct');
        Route::get('/search-product', 'searchProduct');
        Route::post('/advanced-search', 'searchAdvanced');
        Route::post('/import-csv', 'importCSV');
        Route::get('/export-csv', 'exportCSV');
    });

    // 🔘 Product Filters
    Route::prefix('product-filters')->controller(ProductFilterController::class)->group(function () {
        Route::get('/filter-products', 'index');
        Route::get('/filter-visibility', 'updateVisibility');
    });

    // 🖼️ Banners
    Route::prefix('banners')->controller(BannerController::class)->group(function () {
        Route::get('/list-banners', 'index');
        Route::post('/add-banner', 'storeBanner');
        Route::post('/update-banner/{id}', 'updateBanner');
        Route::delete('/delete-banner/{id}', 'destroyBanner');
    });
});


// ===============================
// 🌐 PUBLIC + WEB ROUTES
// ===============================


// 🔓 Public Routes (accessible without login)
Route::prefix('web')->group(function () {

    // 📁 Public Categories
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{id}', [CategoryController::class, 'show']);
    Route::get('/categories/slug/{slug}', [CategoryController::class, 'showBySlug']);
    // Public banner

    Route::get('/list-banners', 'index');

    // 🏷️ Public Brands
    Route::get('/brands', [BrandController::class, 'index']);
    Route::get('/brands/{id}', [BrandController::class, 'showBrands']);

    // 📦 Public Products
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/product/{slug}', [ProductController::class, 'showProduct']);
    Route::get('/search-product', [ProductController::class, 'searchProduct']);

    // 🛒 Cart
    Route::prefix('cart')->controller(CartController::class)->group(function () {
        Route::get('/list', 'index');
        Route::post('/add', 'storeCart');
        Route::delete('/delete/{id}', 'destroyCart');
    });

    // ❤️ Wishlist (auth required)
    Route::prefix('wishlist')->middleware('auth:sanctum')->controller(WishlistController::class)->group(function () {
        Route::get('/list', 'index');
        Route::post('/add', 'store');
        Route::delete('/remove/{id}', 'destroy');
    });

    // 🧾 Orders (auth required)
    Route::prefix('order')->middleware('auth:sanctum')->controller(OrderController::class)->group(function () {
        Route::post('/place', 'store');
        Route::get('/my-orders', 'index');
        Route::get('/show/{id}', 'show');
    });

    // ⭐ Reviews
    Route::prefix('reviews')->controller(ReviewController::class)->group(function () {
        Route::get('/product/{productId}', 'index'); // ✅ public
        Route::middleware('auth:sanctum')->post('/add', 'store'); // 🔐 secure
    });

    // 💳 Stripe
    Route::post('/stripe/create-session', [StripePaymentController::class, 'createCheckoutSession']);
    Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);
});
