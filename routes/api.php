<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\api\Admin\{
    BannerController, BrandController, CategoryController,
    ProductController, ProductFilterController, HomeController,
    StoreController, CustomerController
};
use App\Http\Controllers\api\Web\{
    CartController, OrderController, ReviewController,
    WishlistController, StripePaymentController, StripeWebhookController,OrderItemController
};
use App\Http\Controllers\api\AuthController;
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
    // Main Search
    Route::get('/global-search', [\App\Http\Controllers\api\Admin\GlobalSearchController::class, 'search']);
    // 🏢 Store Management

    Route::prefix('stores')->controller(StoreController::class)->group(function () {
        Route::get('/list-stores', 'index');
        Route::post('/select-store', 'selectStore');
        
    });
    // 📊 Dashboard
    Route::get('/store-performance', [HomeController::class, 'storePerformance']);
    Route::post('/track-visit', [HomeController::class, 'track']);
    Route::get('/total-count', [HomeController::class, 'getStoreStats']);

    // Orders (Admin Dashboard style)
    Route::prefix('orders')->controller(OrderController::class)->group(function () {
        Route::get('/list-orders', 'index'); // ✅ Get orders with filters, pagination
        Route::get('/show-order/{id}', 'show'); // ✅ View order details
        Route::patch('/update-status/{id}', 'updateOrderStatus'); // ✅ Update order status
        Route::get('/export-orders', 'exportToExcel'); 
        Route::get('/list-drafts', 'listDraftOrders');        // GET all draft orders
        Route::post('/create-draft', 'createDraftOrder');     // POST create new draft
        Route::delete('/delete-draft/{id}', 'deleteDraftOrder'); // DELETE a draft order
    });

    Route::prefix('order-items')->controller(OrderItemController::class)->group(function () {
        Route::get('/{orderId}', 'index');
        Route::post('/add/{orderId}', 'store');
        Route::put('/update/{id}', 'update');
        Route::delete('/delete/{id}', 'destroy');
    });

    // Customers

      Route::prefix('customers')->group(function () {
        Route::get('/get-customers', [CustomerController::class, 'index']);
        Route::get('/get-customer/{id}', [CustomerController::class, 'show']);
        Route::post('/add-customer', [CustomerController::class, 'store']);
        Route::put('/update-customer/{id}', [CustomerController::class, 'update']);
        Route::delete('/delete-customer/{id?}', [CustomerController::class, 'destroy']);
        Route::post('/import', [CustomerController::class, 'importCSV']);
        Route::get('/export', [CustomerController::class, 'exportCSV']);
    });
    // 📁 Categories
    Route::prefix('categories')->controller(CategoryController::class)->group(function () {
        Route::get('/list-categories', 'index');
        Route::get('/category/{id}', 'show');
        Route::get('/slug/{slug}', 'showBySlug');
        Route::post('/add-categories', 'storeCategory');
        Route::put('/update-category/{id}', 'updateSingleCategory');
        Route::put('/update-bulk-category', 'updateBulkCategory');
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
        Route::get('/product/{id}', 'show');
        Route::get('/show-product/{slug}', 'showProduct');
        Route::post('/add-product', 'addProduct');
        Route::put('/update-product', 'updateProduct');
        Route::post('/update-single-product/{id}','updateSingleProduct');
        Route::delete('/delete-product/{id?}', 'destroyProduct');
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

    Route::get('/list-banners',[BannerController::class, 'index']);

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
        Route::post('/add-cart', 'storeCart');
        Route::delete('/delete/{id}', 'destroyCart');
    });

    // ❤️ Wishlist (auth required)
    Route::prefix('wishlist')->middleware('auth:sanctum')->controller(WishlistController::class)->group(function () {
        Route::get('/list', 'index');
        Route::post('/add', 'store');
        Route::delete('/remove/{id}', 'destroy');
    });

    // 🧾 Orders (auth required)
    Route::prefix('orders')->middleware('auth:sanctum')->controller(OrderController::class)->group(function () {
        Route::post('/place-order', 'store');
        Route::get('/my-orders', 'index');
        Route::get('/show/{id}', 'show');

        Route::get('/order-items/{orderId}', [\App\Http\Controllers\api\Web\OrderItemController::class, 'webIndex']);
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
