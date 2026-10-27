<?php

use App\Http\Controllers\api\Admin\PermissionController;
use App\Http\Controllers\api\Web\VisitorSessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\api\Admin\{
    BannerController,
    BrandController,
    CategoryController,
    ProductController,
    ProductFilterController,
    HomeController,
    StoreController,
    CustomerController,
    GlobalSearchController,
    TransactionalEmailController,
    EmailMarketingController,
    PromotionController,
    OrderTrackingImportController,
    WebPageController,
    BlogPostController,
    CarouselController,
    LogoController,
    ScriptController,
    CouponCodeController,
    CurrencyController,
    RealtimeController,
    StaffLogController,
    StoreSettingController,
    UrlSettingController,
    StoreProfileController
};
use App\Http\Controllers\api\Web\{
    CartController,
    OrderController,
    ReviewController,
    WishlistController,
    StripePaymentController,
    NewsletterController,
    SearchController,
    StripeWebhookController,
    OrderItemController,
    ShipmentController,
    ContactRequestController,
    BulkInquiryController,
    CheckoutFormDataController,
    CustomerAddressController,
    CustomerMessageController
};
use App\Http\Controllers\api\AuthController;
use App\Http\Controllers\api\SitemapController;
use App\Http\Controllers\api\TwoFactorController;
use App\Http\Controllers\api\FedEx\ShippingZoneController;
use App\Http\Controllers\api\FedEx\ShippingMethodController;
use App\Http\Controllers\api\FedEx\FedexConfigurationController;
use App\Http\Controllers\api\FedEx\CheckoutShippingController;
use App\Http\Controllers\api\FedEx\StoreShippingAddressController;
use App\Http\Controllers\FedExTestController;
use Illuminate\Support\Facades\DB;

// ===============================
// 🔐 AUTHENTICATED USER CHECK
// ===============================
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// ===============================
// 🔐 AUTH ROUTES
// ===============================


// Route::prefix('user')->controller(AuthController::class)->group(function () {
//     Route::post('/register', 'register');

//     Route::post('/login', 'login');
//     Route::post('/forgot-password', 'forgotPassword');
//     Route::post('/reset-password', 'resetPassword');


//     Route::middleware('auth:sanctum')->post('/logout', 'logout');
// });

    // Customer registration: resolve store and connect tenant
    Route::post('/dashboard/user/register', [
        AuthController::class,
        'registerOwner',
    ]);

Route::prefix('user')->controller(AuthController::class)->group(function () {

    
    // Customer registration: resolve store and connect tenant
    Route::post('/register', 'register')
        ->middleware(['store.hash', 'tenant.database']);

    // Admin login: central database only; no store required
    Route::post('/login', 'login');

    // Customer password reset endpoints
    Route::post('/forgot-password', 'forgotPassword')
        ->middleware(['store.hash', 'tenant.database']);

    Route::post('/reset-password', 'resetPassword')
        ->middleware(['store.hash', 'tenant.database']);

    // Customer logout
    Route::middleware([
        'auth:sanctum',
    ])->post('/logout', 'logout');
});

// Admin logout — central database
Route::prefix('dashboard')->middleware(['auth:sanctum'])->group(function () {
    Route::post('/admin/logout', [AuthController::class, 'adminLogout']);
});

// Customer logout — tenant database
Route::prefix('web')->middleware([
    'store.hash',
    'tenant.database',
    'auth:sanctum',
])->group(function () {
    Route::post('/customer/logout', [AuthController::class, 'customerLogout']);
});



Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/customer-login', [AuthController::class, 'customerLogin'])->middleware(['store.hash', 'tenant.database']);
Route::post('/admin/forgot-password', [AuthController::class, 'adminForgotPassword']);
Route::post('/admin/reset-password', [AuthController::class, 'adminResetPassword']);

Route::post('/auth/send-magic-link', [
    AuthController::class,
    'sendMagicLink',
])->middleware(['store.hash', 'tenant.database']);

Route::post('/auth/verify-magic-link', [
    AuthController::class,
    'verifyMagicLink',
])->middleware(['store.hash', 'tenant.database']);

Route::prefix('2fa')->group(function () {
    Route::post('/verify',     [TwoFactorController::class, 'verify']);      // OTP verify → real token
    Route::post('/resend-otp', [TwoFactorController::class, 'resendOtp']);   // Email OTP dobara bhejo
});

// ─── Protected (auth:sanctum middleware) ─────────────────────

Route::middleware('auth:sanctum')->group(function () {

    Route::prefix('2fa')->group(function () {
        Route::get('/status',                  [TwoFactorController::class, 'status']);
        Route::post('/enable/email',           [TwoFactorController::class, 'enableEmail']);
        Route::post('/setup/authenticator',    [TwoFactorController::class, 'setupAuthenticator']);
        Route::post('/confirm/authenticator',  [TwoFactorController::class, 'confirmAuthenticator']);
        Route::post('/disable',                [TwoFactorController::class, 'disable']);
    });
});

// ===============================
// 🛠️ ADMIN ROUTES (Protected)
// ===============================


Route::prefix('dashboard')->middleware([
    'store.hash',
    'tenant.database',
    'auth:sanctum'
])->group(function () {

    // Profile (no permission needed — own profile)
    Route::prefix('profile')->controller(AuthController::class)->group(function () {
        Route::post('/update-email', 'updateEmail');
        Route::put('/update-name', 'updateName');
        Route::post('/update-password', 'updatePassword');
    });

    // Store Profile 
    // Route::get(
    //     '/settings/store-profile',
    //     [StoreProfileController::class, 'show']
    // );

    // Route::put(
    //     '/settings/store-profile',
    //     [StoreProfileController::class, 'update']
    // );

    Route::get('/settings/store-profile', [StoreProfileController::class, 'show'])
        ->middleware(['store.hash', 'tenant.dashboard']);

    Route::put('/settings/store-profile', [StoreProfileController::class, 'update'])
        ->middleware(['store.hash', 'tenant.dashboard']);


    // Route::get('/staff-logs', [StaffLogController::class, 'index']);

    Route::get('/staff-logs', [StaffLogController::class, 'index'])
        ->middleware(['store.hash', 'tenant.dashboard']);

    // Route::get('/admin-users', [AuthController::class, 'getAdminUsers']);
    // Route::get('/admin-users/{id}', [AuthController::class, 'getAdminUser']);
    // Route::put('/admin-users/{id}', [AuthController::class, 'updateAdminUser']);
    // Route::delete('/admin-users/{id}', [AuthController::class, 'deleteAdminUser']);
    Route::get('/admin-users', [AuthController::class, 'getAdminUsers'])
        ->middleware(['store.hash', 'tenant.dashboard']);

    Route::get('/admin-users/{id}', [AuthController::class, 'getAdminUser'])
        ->middleware(['store.hash', 'tenant.dashboard']);

    Route::put('/admin-users/{id}', [AuthController::class, 'updateAdminUser'])
        ->middleware(['store.hash', 'tenant.dashboard']);

    Route::delete('/admin-users/{id}', [AuthController::class, 'deleteAdminUser'])
        ->middleware(['store.hash', 'tenant.dashboard']);

    // Route::post('/login-as-customer', [AuthController::class, 'loginAsCustomer']);

    // // Main Search (no permission needed)
    // Route::get('/global-search', [GlobalSearchController::class, 'search']);

    Route::get('/global-search', [GlobalSearchController::class, 'search'])
        ->middleware(['store.hash', 'tenant.dashboard']);

    Route::post('/login-as-customer', [AuthController::class, 'loginAsCustomer'])
        ->middleware(['store.hash', 'tenant.dashboard']);

    // Store Management (no permission needed — store selection)
    Route::prefix('stores')->controller(StoreController::class)->group(function () {
        Route::get('/list-stores', 'index');
        Route::post('/select-store', 'selectStore');
    });

    // Dashboard (no permission needed — everyone sees dashboard)
    // Route::get('/store-performance', [HomeController::class, 'storePerformance']);
    // Route::get('/store-count-filtered', [HomeController::class, 'storeCountFiltered']);
    // Route::get('/store-count', [HomeController::class, 'storeCount']);
    // Route::post('/track-visit', [HomeController::class, 'track']);
    // Route::get('/total-count', [HomeController::class, 'getStoreStats']);

    Route::middleware(['store.hash', 'tenant.dashboard'])->group(function () {
        Route::get('/store-performance', [HomeController::class, 'storePerformance']);
        Route::get('/store-count-filtered', [HomeController::class, 'storeCountFiltered']);
        Route::get('/store-count', [HomeController::class, 'storeCount']);
        Route::post('/track-visit', [HomeController::class, 'track']);
        Route::get('/total-count', [HomeController::class, 'getStoreStats']);
    });

    // Permissions (no permission needed — self check)
    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::get('/permissions/my', [PermissionController::class, 'myPermissions']);
    Route::get('/permissions/user/{userId}', [PermissionController::class, 'getUserPermissions']);
    Route::post('/permissions/assign/{userId}', [PermissionController::class, 'assignPermissions']);

    // ===================== ORDERS =====================
    Route::prefix('orders')->middleware(['store.hash', 'tenant.dashboard', 'permission:all_orders'])->controller(OrderController::class)->group(function () {

        Route::get('/dashboard/overview', 'overview');
        Route::get('/show-order/{id}', 'show');
        Route::patch('/update-status/{id}', 'updateOrderStatus');
        Route::get('/export-orders', 'exportToExcel');
        Route::get('/get-notes/{orderId}', 'getOrderNotes');
        //  Route::get('/search-advanced', 'advancedOrderSearch');
        Route::put('/update-order/{id}', 'updateFromDashboard');
        Route::get('/invoice/{ids}', 'downloadInvoice');
        Route::post('/resend-invoice/{id}', 'resendInvoice');
        Route::post('/refund/{id}', 'refund');
        Route::get('/timeline/{id}', 'getOrderTimeline');
        Route::get('/customer-by-order/{orderId}', 'getCustomerByOrder');
        Route::get('/send-payment-invoice/{id}', 'sendPaymentInvoice');
        Route::get('/customer/{customerId}/orders', 'getCustomerWithOrders');
    });


    //     ->middleware('auth:sanctum');

    // Orders — Add
    Route::prefix('orders')->middleware(['store.hash', 'tenant.dashboard', 'permission:add_order'])->controller(OrderController::class)->group(function () {

        Route::post('/existing-customer', 'storeFromDashboard');
        Route::post('/new-customer', 'storeNewCustomerOrder');
    });

    // Orders — Search
    // Route::prefix('orders')->middleware('permission:search_orders')->controller(OrderController::class)->group(function () {
    //     Route::get('/search-advanced', 'advancedOrderSearch');
    // });

    // Orders — Export
    Route::prefix('orders')->middleware(['store.hash', 'tenant.dashboard', 'permission:export_orders'])->controller(OrderController::class)->group(function () {
        Route::get('/export-orders', 'exportToExcel');
    });

    // Orders — Draft
    Route::prefix('orders')->middleware('store.hash', 'tenant.dashboard')->controller(OrderController::class)->group(function () {
        Route::get('/list-orders', 'index');
        Route::get('/list-drafts', 'listDraftOrders');
        Route::post('/create-draft', 'createDraftOrder');
        Route::get('/get-draft-order', 'getDraftOrders');
    });

    // Orders — Shipments
    Route::prefix('shipments')->middleware(['store.hash', 'tenant.dashboard', 'permission:shipments'])->controller(ShipmentController::class)->group(function () {
        Route::post('/add-shipment', 'store');
        Route::put('/update-shipment/{id}', 'update');
        Route::delete('/destroy-shipment', 'destroy');
        Route::get('/packing-slip/{ids}', 'printPackingSlip');
        Route::post('/advanced-search', 'advancedSearch');
        Route::get('/export-csv', 'exportCSV');
    });
    Route::prefix('shipments')->middleware(['store.hash', 'tenant.dashboard'])->controller(ShipmentController::class)->group(function () {
        Route::get('/list-shipment', 'index');
        Route::get('/get-shipment/{id}', 'show');
        Route::get('/shipment-by-order', 'getShipmentByOrderId');
    });

    // Orders — Return Request
    Route::prefix('orders')->middleware(['store.hash', 'tenant.dashboard'])->controller(OrderController::class)->group(function () {
        Route::get('admin-return-requests', [OrderController::class, 'listReturnRequestsForAdmin']);
    });

    // Orders — Tracking
    Route::prefix('orders-tracking')->middleware(['store.hash', 'tenant.dashboard', 'permission:shipments'])->controller(OrderTrackingImportController::class)->group(function () {
        Route::post('/tracking/import', 'import');
        Route::get('/tracking/template', 'template');
        Route::get('/tracking/export', 'export');
        Route::post('/{order}/tracking', 'setSingle');
    });

    // Order Items
    Route::prefix('order-items')->middleware(['store.hash', 'tenant.dashboard', 'permission:all_orders'])->controller(OrderItemController::class)->group(function () {
        Route::post('/add/{orderId}', 'store');
        Route::put('/update/{id}', 'update');
        Route::delete('/delete/{id}', 'destroy');
    });
    Route::prefix('order-items')->middleware(['store.hash', 'tenant.dashboard'])->controller(OrderItemController::class)->group(function () {
        Route::get('/{orderId}', 'index');
    });

    // ===================== PRODUCTS =====================
    Route::prefix('products')->middleware(['store.hash', 'tenant.dashboard', 'permission:all_products'])->controller(ProductController::class)->group(function () {
        Route::put('/update-product', 'updateProduct');
        Route::post('/update-single-product/{id}', 'updateSingleProduct');
        Route::get('/product/{id}', 'show');
        Route::delete('/delete-product/categories', 'destroyProductCategories');
        Route::delete('/delete-product/{id?}', 'destroyProduct');
    });
    Route::prefix('products')->middleware(['store.hash', 'tenant.dashboard', 'permission:all_products'])->controller(ProductController::class)->group(function () {
        Route::get('/products-list', 'index');
        Route::get('/purchasable-products', 'purchasableProducts');
        Route::get('/products-filter', 'filterProducts');
        Route::get('/show-product/{slug}', 'showProduct');
        Route::get('/featured-products', 'featuredProducts');
    });

    // Products — Add
    Route::prefix('products')->middleware(['store.hash', 'tenant.dashboard', 'permission:add_product'])->controller(ProductController::class)->group(function () {
        Route::post('/add-product', 'addProduct');
    });

    // Products — Search
    Route::prefix('products')->middleware(['store.hash', 'tenant.dashboard', 'permission:search_products'])->controller(ProductController::class)->group(function () {
        Route::get('/search-product', 'searchProduct');
        // Route::post('/advanced-search', 'searchAdvanced');
    });

    // Products — Import
    Route::prefix('products')->middleware(['store.hash', 'tenant.dashboard', 'permission:import_products'])->controller(ProductController::class)->group(function () {
        Route::post('/import-csv', 'importCSV');
        Route::post('/cancel-import', 'cancelImport');
        Route::get('/import-progress/{progressKey}', 'importProgress');
    });

    // Products — Export
    Route::prefix('products')->middleware(['store.hash', 'tenant.dashboard', 'permission:export_products'])->controller(ProductController::class)->group(function () {
        Route::get('/export-csv', 'exportCSV');
    });

    // Products — Categories
    Route::prefix('categories')->middleware(['store.hash', 'tenant.dashboard', 'permission:categories'])->controller(CategoryController::class)->group(function () {
        Route::post('/add-categories', 'storeCategory');
        Route::put('/update-category/{id}', 'updateSingleCategory');
        Route::put('/update-bulk-category', 'updateBulkCategory');
        Route::delete('/delete-categories', 'destroyCategory');
        Route::delete('/delete-subcategories/{id}', 'destroyOnlySubcategories');
        Route::post('/update-categorySingle/{id}', 'updateCategoryExtras');
    });
    Route::prefix('categories')->middleware(['store.hash', 'tenant.dashboard', 'permission:categories'])->controller(CategoryController::class)->group(function () {
        Route::get('/list-categories', 'listCategory');
        Route::get('/category/{id}', 'show');
        Route::get('/slug/{slug}', 'showBySlug');
    });

    // Products — Reviews

    Route::prefix('reviews')->middleware(['store.hash', 'tenant.dashboard', 'permission:reviews'])->controller(ReviewController::class)->group(function () {
        Route::put('/update-review/{id}', 'update');
        Route::put('/bulk-status-review', 'bulkStatusUpdate');
        Route::post('/approve', 'approveSelected');
        Route::post('/disapprove', 'disapproveSelected');
        Route::post('/delete', 'deleteSelected');
        Route::delete('/bulk-delete-review', 'bulkDelete');
    });
    Route::prefix('reviews')->middleware(['store.hash', 'tenant.dashboard', 'permission:reviews'])->controller(ReviewController::class)->group(function () {
        Route::get('/admin', 'adminIndex');
        Route::get('/get-by-id/{id}', 'show');
    });
    // Products — Brands
    Route::prefix('brands')->middleware(['store.hash', 'tenant.dashboard', 'permission:brands'])->controller(BrandController::class)->group(function () {

        Route::post('/add-brand', 'storeBrands');
        Route::post('/update-brand/{id}', 'updateBrands');
        Route::delete('/delete-brand/{id}', 'destroyBrands');
    });
    Route::prefix('brands')->middleware(['store.hash', 'tenant.dashboard', 'permission:brands'])->controller(BrandController::class)->group(function () {
        Route::get('/brand-list', 'index');
        Route::get('/brand/{id}', 'showBrands');
    });

    // Product Filters
    // Route::prefix('product-filters')->middleware('permission:all_products')->controller(ProductFilterController::class)->group(function () {
    //     Route::get('/filter-products', 'index');
    //     Route::get('/filter-visibility', 'updateVisibility');
    // });

    // ===================== CUSTOMERS =====================
    Route::prefix('customers')->middleware([
        'store.hash',
        'tenant.dashboard',
        'permission:all_customers',
    ])->group(function () {
        Route::get('customers-by-email', [CustomerController::class, 'showByEmail']);
        Route::put('/update-customer/{id}', [CustomerController::class, 'update']);
        Route::delete('/delete-customer/{id?}', [CustomerController::class, 'destroy']);
    });
    Route::prefix('customers')->middleware(['store.hash', 'tenant.dashboard'])->group(function () {
        Route::get('/get-customers', [CustomerController::class, 'index']);
        Route::get('/get-customer/{id}', [CustomerController::class, 'show']);
    });

    Route::prefix('customer-messages')->middleware([
        'store.hash',
        'tenant.dashboard'
    ])->controller(CustomerMessageController::class)->group(function () {

        // Admin APIs
        Route::post('/admin-reply', 'adminReply');
        Route::get('/admin-messages', 'adminMessages');
        Route::get('/show/{id}', 'showMessage');
        Route::post('/mark-read', 'markAsRead');
        Route::post('/mark-unread', 'markAsUnread');
        Route::post('/toggle-flag', 'toggleFlag');
        Route::put('/update/{id}', 'updateMessage');
        Route::post('/delete', 'deleteMessages');
    });

    // Customers — Add
    Route::prefix('customers')->middleware(['store.hash', 'tenant.dashboard', 'permission:add_customer'])->group(function () {
        Route::post('/add-customer', [CustomerController::class, 'store']);
        Route::post('/add-customer/check-email', [CustomerController::class, 'checkEmail']);
    });

    // Customers — Search
    // Route::prefix('customers')->middleware('permission:search_customers')->group(function () {
    //     Route::post('/customer-search', [CustomerController::class, 'advancedSearch']);
    // });

    // Customers — Import
    Route::prefix('customers')->middleware(['store.hash', 'tenant.dashboard', 'permission:import_customers'])->group(function () {
        Route::post('/import', [CustomerController::class, 'importCSV']);
    });

    // Customers — Export
    Route::prefix('customers')->middleware(['store.hash', 'tenant.dashboard', 'permission:export_customers'])->group(function () {
        Route::get('/export', [CustomerController::class, 'exportCSV']);
    });



    Route::prefix('customer-address')->middleware(['store.hash', 'tenant.dashboard'])->controller(CustomerAddressController::class)->group(function () {
        Route::post('/store', 'store');
        Route::put('/update/{id}', 'update');
        Route::delete('/delete/{id}', 'destroy');
        Route::get('/list', 'index');
        Route::get('/show/{id}', 'show');
        Route::delete('/delete-multiple', 'destroyMultiple');
    });


    Route::prefix('saved-addresses')->middleware('auth:sanctum')->controller(OrderController::class)->group(function () {
        Route::get('/list', 'getSavedAddress');
        Route::delete('/delete/{id}', 'destroySavedAddress');
    });
    // // Customer self-service (no permission — own account)
    Route::prefix('customers')->middleware(['store.hash', 'tenant.dashboard'])->group(function () {
        //     Route::post('/account-settings', [CustomerController::class, 'updateAccount']);
        //     Route::get('/my-orders', [CustomerController::class, 'myOrders']);
        //     Route::get('/my-addresses', [CustomerController::class, 'getMyAddresses']);
        Route::delete('/delete-my-address/{addressId}', [CustomerController::class, 'deleteMyAddress']);
    });
    // ===================== STOREFRONT — LOGO =====================
    Route::prefix('logos')->middleware(['store.hash', 'tenant.dashboard', 'permission:logo'])->controller(LogoController::class)->group(function () {
        Route::get('/get-logos', 'index');
        Route::get('/get-logo/{id}', 'showById');
        Route::post('/add-logo', 'store');
        Route::post('/update-logo/{id}', 'update');
        Route::delete('/delete-logo/{ids}', 'destroy');
    });

    // ===================== STOREFRONT — CAROUSEL =====================
    Route::prefix('carousels')->middleware(['store.hash', 'tenant.dashboard', 'permission:home_page_carousel'])->controller(CarouselController::class)->group(function () {
        Route::get('/get-crousel', 'index');
        Route::get('/get-single/{id}', 'show');
        Route::post('/add-crousel', 'store');
        Route::post('/update-carousel/{id}', 'update');
        Route::delete('/delete-carousel/{id}', 'destroy');
    });

    // ===================== STOREFRONT — SCRIPTS =====================
    Route::prefix('scripts')->middleware(['store.hash', 'tenant.dashboard', 'permission:script_manager'])->controller(ScriptController::class)->group(function () {
        Route::get('/get-script', 'index');
        Route::get('/get-script/{id}', 'show');
        Route::post('/add-script', 'store');
        Route::put('/update-script/{id}', 'update');
        Route::delete('/delete-script/{ids}', 'destroy');
    });

    // ===================== STOREFRONT — WEB PAGES =====================
    Route::prefix('webpages')->middleware(['store.hash', 'tenant.dashboard', 'permission:web_pages'])->controller(WebPageController::class)->group(function () {
        Route::get('/dashboard-web-pages', 'dashboardIndex');
        Route::get('/single-page/{id}', 'show');
        Route::post('/create-page', 'store');
        Route::put('update/{id}', 'update');
        Route::put('update-navigation/{id}', 'updateNavigation');
        Route::delete('/delete/{ids}', 'destroy');
    });

    // ===================== STOREFRONT — BLOG =====================
    Route::prefix('blogs')->middleware(['store.hash', 'tenant.dashboard', 'permission:blog'])->controller(BlogPostController::class)->group(function () {

        Route::post('/add-blog', 'store');
        Route::post('/update-blog/{id}', 'update');
        Route::delete('/delete-blog/{ids}', 'destroy');
    });
    Route::prefix('blogs')->middleware(['store.hash', 'tenant.dashboard', 'permission:blog'])->controller(BlogPostController::class)->group(function () {
        Route::get('/get-blog', 'index');
        Route::get('/get-blogSingle/{id}', 'show');
        Route::get('/draft-blogs', 'drafts');
    });


    // ===================== MARKETING — COUPONS =====================
    Route::prefix('coupons')->middleware(['store.hash', 'tenant.dashboard', 'permission:coupon_codes'])->controller(CouponCodeController::class)->group(function () {

        Route::post('/add-couponcode', 'store');
        Route::put('/update-couponcode/{id}', 'update');
        Route::put('/update-enabled/{id}', 'updateEnabled');

        Route::delete('/delete-couponcode/{id}', 'destroy');
    });
    Route::prefix('coupons')->middleware(['store.hash', 'tenant.dashboard', 'permission:coupon_codes'])->controller(CouponCodeController::class)->group(function () {
        Route::get('/get-couponcode', 'index');
        Route::get('/get-couponcode/{id}', 'show');
    });

    Route::prefix('coupons')->middleware(['store.hash', 'tenant.dashboard', 'permission:coupon_codes'])->controller(CouponCodeController::class)->group(function () {
        Route::get('/get-couponcode-dashboard', 'showDashboardCouponCode');
        Route::post('/coupon-usage/draft', 'updateMyCouponDraft');
        Route::delete('/coupon-usage/remove', 'deleteCouponUsageByEmail');
    });

    // ===================== SETTINGS =====================

    // URL Settings
    // Route::post('/url-settings/product', [UrlSettingController::class, 'updateProduct']);
    // Route::post('/url-settings/category', [UrlSettingController::class, 'updateCategory']);
    // Route::post('/url-settings/webpage', [UrlSettingController::class, 'updateWebpage']);
    // Route::get('/url-settings/{entity}', [UrlSettingController::class, 'show']);

    Route::prefix('url-settings')
        ->middleware([
            'store.hash',
            'tenant.dashboard',
            'permission:settings',
        ])
        ->controller(UrlSettingController::class)
        ->group(function () {
            Route::post('/product', 'updateProduct');
            Route::post('/category', 'updateCategory');
            Route::post('/webpage', 'updateWebpage');
            Route::get('/{entity}', 'show');

            // Bulk Update + Redirects
            Route::post('/product/bulk-update', 'bulkUpdateProductUrls');
            Route::get('/product/bulk-update/progress/{progressKey}', 'bulkUpdateProgress');
            Route::post('/category/bulk-update', 'bulkUpdateCategoryUrls');
            Route::post('/webpage/bulk-update', 'bulkUpdateWebpageUrls');
        });

    Route::prefix('currencies')->middleware([
        'store.hash',
        'tenant.dashboard',
        'permission:settings',
    ])->group(function () {

        // Specific routes PEHLE (wildcard se pehle)
        Route::get('/options', [CurrencyController::class, 'options']);
        Route::get('/countries', [CurrencyController::class, 'countries']);
        Route::get('/current-rate', [CurrencyController::class, 'getCurrentRate']);
        Route::get('/default', [CurrencyController::class, 'getDefault']);

        // List
        Route::get('/', [CurrencyController::class, 'index']);

        // CRUD
        Route::post('/', [CurrencyController::class, 'store']);

        // Single currency (ID se) — LAST mein
        Route::get('/{currency}', [CurrencyController::class, 'show']);
        Route::put('/{currency}', [CurrencyController::class, 'update']);
        Route::delete('/{currency}', [CurrencyController::class, 'destroy']);
        Route::post('/{currency}/set-default', [CurrencyController::class, 'setAsDefault']);
    });


    // Email Marketing
    Route::prefix('email-marketings')->middleware([
        'store.hash',
        'tenant.dashboard',
        'permission:settings',
    ])->controller(EmailMarketingController::class)->group(function () {
        Route::get('/get-email-marketing', 'show');
        Route::put('/email-marketing', 'update');
    });
    // Analytics — Visitor Sessions
    Route::prefix('analytics')->middleware(['store.hash', 'tenant.dashboard'])->controller(RealtimeController::class)->group(function () {
        Route::get('/realtime', 'index');
        Route::get('/dashboard/search', 'searchIndex');
        Route::get('/customers/{id}/details', 'showSearchCustomersDetails');
        Route::get('/orders/{id}/details', 'showSearchOrderDetails');
        Route::get('/products/{id}/details', 'showSearchProductDetails');
        Route::get('/analytics-customers', 'analyticsCustomers');
        Route::get('/analytics-orders', 'orderIndex');
        Route::get('/analytics-products', 'productIndex');
    });

    Route::middleware([
        'store.hash',
        'tenant.dashboard',
        'permission:settings',
    ])->group(function () {
        // Store Setting
        Route::prefix('store-setting')->controller(StoreSettingController::class)->group(function () {
            Route::get('/get-store-setting', 'index');
            Route::post('/add-store-setting', 'store');
        });



        // Store Shipping Address
        Route::prefix('store-shipping-address')->controller(StoreShippingAddressController::class)->group(function () {
            Route::get('/get-address', 'index');
            Route::post('/save-address', 'store');
        });

        // Shipping Zones
        Route::prefix('shipping-zones')->controller(ShippingZoneController::class)->group(function () {
            Route::get('/get-zones', 'index');
            Route::post('/add-zone', 'store');
            Route::get('/get-zone/{id}', 'show');
            Route::put('/update-zone/{id}', 'update');
            Route::delete('/delete-zone/{id}', 'destroy');
        });

        // Shipping Methods
        Route::prefix('shipping-methods')->controller(ShippingMethodController::class)->group(function () {
            Route::get('/get-methods/{zoneId}', 'index');
            Route::get('/get-method/{id}', 'show');
            Route::post('/add-method/{zoneId}', 'store');
            Route::put('/update-method/{id}', 'update');
            Route::delete('/delete-method/{id}', 'destroy');
            Route::patch('/toggle-method/{id}', 'toggle');
        });

        // FedEx Configuration
        Route::prefix('fedex-config')->controller(FedexConfigurationController::class)->group(function () {
            Route::get('/get-config/{methodId}', 'show');
            Route::post('/save-config/{methodId}', 'storeOrUpdate');
            Route::post('/test-connection/{methodId}', 'testConnection');
            Route::post('/disconnect/{methodId}', 'disconnect');
            Route::get('/get-quote/{methodId}', 'getQuote');
            Route::post('/get-quote/{methodId}', 'getQuote');
            Route::get('/get-quote-logs/{methodId}', 'quoteLogs');
            Route::get('/get-services', 'availableServices');
        });



        // Transactional Emails
        Route::prefix('transactional-emails')->controller(TransactionalEmailController::class)->group(function () {
            Route::get('/get', 'index');
            Route::get('/get-single/{key}', 'show');
            Route::post('/create-key/{key}', 'upsert');
            Route::patch('/toggle/{key}', 'toggle');
            Route::post('/preview/{key}', 'preview');
            Route::post('/send-test/{key}', 'sendTest');
        });

        // Banners
        Route::prefix('banners')->controller(BannerController::class)->group(function () {
            Route::get('/list-banners', 'index');
            Route::get('/list-banners-by-Id/{id}', 'show');
            Route::post('/add-banner', 'storeBanner');
            Route::patch('/update-banner/{id}', 'updateBanner');
            Route::delete('/delete-banner/{id}', 'destroyBanner');
        });

        // Promotions
        Route::prefix('promotions')->controller(PromotionController::class)->group(function () {
            Route::get('/list-promotion', 'index');
            Route::get('/get-promotion/{id}', 'show');
            Route::post('/add-promotion', 'store');
            Route::post('/update-promotion/{id}', 'update');
            Route::delete('/delete-promotion', 'destroy');
            Route::post('/update-status', 'updateStatus');
            Route::get('/download-codes/{id}', 'downloadCodes');
            Route::post('/add-codes', 'addCodes');
            Route::get('/list-codes/{id}', 'listCodes');
            Route::post('/update-codes', 'updateCodes');
            Route::delete('/delete-codes', 'deleteCodes');
        });
    });

    // Stripe Capture
    // Route::post('/stripe/capture', [StripePaymentController::class, 'capturePayment']);
    Route::post(
        '/stripe/capture',
        [StripePaymentController::class, 'capturePayment']
    )->middleware(['store.hash', 'tenant.dashboard']);
    //Newsletter
    // Route::get('/subscribers/export', [NewsletterController::class, 'exportSubscribers']);
    // Route::delete('/subscribers/delete-all', [NewsletterController::class, 'deleteAllSubscribers']);
    Route::middleware([
        'store.hash',
        'tenant.dashboard',
        'permission:settings',
    ])->group(function () {
        Route::get('/subscribers/export', [NewsletterController::class, 'exportSubscribers']);
        Route::delete('/subscribers/delete-all', [NewsletterController::class, 'deleteAllSubscribers']);
    });
});





// ===============================
// 🌐 PUBLIC + WEB ROUTES
// ===============================


// 🔓 Public Routes (accessible without login)

Route::prefix('web')->middleware([\App\Http\Middleware\ResolveStoreByHash::class, \App\Http\Middleware\ConnectTenantDatabase::class])->group(function () {

    Route::middleware('auth:sanctum')->get('/customer-profile', [AuthController::class, 'getCustomerProfile']);
    Route::post('/customer/check-token', [AuthController::class, 'checkCustomerToken']);

    Route::prefix('shipments')->controller(ShipmentController::class)->group(function () {
        Route::get('/', 'index');      // View only their orders' shipments
    });

    Route::prefix('blogs')->controller(BlogPostController::class)->group(function () {
        Route::get('/blog-posts', [BlogPostController::class, 'index']);
        Route::get('/blog-posts/{id}', [BlogPostController::class, 'show']);
    });

    Route::prefix('categories')->controller(CategoryController::class)->group(function () {
        Route::get('/get-categories', 'index');
        Route::get('/categories/{id}', 'show');
        Route::get('/categories/slug/{slug}', 'showBySlug');
        Route::get('/category-filter', 'filter');
    });    // Public banner
    Route::prefix('banners')->controller(BrandController::class)->group(function () {
        Route::get('/list-banners', 'index');
    });
    Route::prefix('brands')->controller(BrandController::class)->group(function () {
        Route::get('/brands', 'index');
        Route::get('/brands/{id}', 'showBrands');
    });
    // 🏷️ Public Brands
    Route::prefix('webpages')->controller(WebPageController::class)->group(function () {
        Route::get('/web-pages', 'index');
        Route::get('/single-page/{id}', 'show');
    });

    Route::prefix('products')->controller(ProductController::class)->group(function () {
        Route::get('/products', 'index');
        Route::get('/get-product/{slug}', 'showProduct');
        Route::get('/get-product-by-url/{slug}', 'showProductByUrl')->where('slug', '.*');
        Route::get('/search-product', 'searchProduct');
        Route::get('/featured-products', 'featuredProducts');
        Route::get('/related-products', 'relatedProducts');
        Route::get('/products/by-brand', 'productsByBrand');
        Route::get('/popular-products', 'popularProducts');
        Route::get('/last-week-orders', 'lastWeekOrderedProducts');
        Route::get('/last-week-product', 'lastWeekProducts');
        Route::get('/product-byIds/{id?}', 'showProductsByIds')
            ->where('id', '[0-9]+');
    });
    // 🛒 Cart (Guest + Auth dono)
    Route::prefix('cart')->controller(CartController::class)->group(function () {
        Route::get('/list',           'index');       // GET   - Cart list dekho     
        Route::post('/add-cart',      'storeCart');   // POST  - Item add karo
        Route::put('/update/{id}',    'updateCart');  // PUT   - Quantity set karo
        Route::delete('/delete/{id}', 'destroyCart');
        Route::post('/add/shipping-by-rate', 'saveShippingRate');
        Route::get('/get/shipping-by-rate', 'getShippingRate');
        // Route::delete('/cart-remove-product', 'removeProduct');
        Route::delete('/remove-shipping-rate', 'removeShippingRate');
        Route::post('/add-by-sku', 'storeCartBySku');
        Route::delete('/clear-all', 'clearCart');
    });

    Route::get('/customer-discounts', [OrderController::class, 'getCustomerOrderDiscounts']);

    // 🔀 Cart Transfer (auth required)
    Route::prefix('cart')->middleware(['auth:sanctum'])->controller(CartController::class)->group(function () {
        Route::post('/transfer', 'transferGuestCartToUser');
    });

    Route::prefix('cart')->middleware('auth:sanctum')->controller(OrderController::class)->group(function () {
        Route::post('/load-saved-quote', 'loadSavedQuote');
    });

    // ❤️ Wishlist (auth required)
    Route::prefix('wishlist')->middleware('auth:sanctum')->controller(WishlistController::class)->group(function () {
        Route::get('/list', 'index');
        Route::post('/add', 'store');
        Route::delete('/remove/{id}', 'destroy');
    });



    Route::prefix('orders')->controller(OrderController::class)->group(function () {
        Route::post('/place-order', 'store');
        Route::get('/order-detail', 'detail');
        Route::post('/track-order', 'trackOrder');
        Route::post('/add-notes/{orderId}', 'addOrderNote');
        Route::get('/my-orders-list', 'myOrdersList');
    });
    // 🧾 Orders (auth required)
    Route::prefix('orders')->middleware('auth:sanctum')->controller(OrderController::class)->group(function () {
        Route::get('/my-orders', 'index');
        Route::get('/show/{id}', 'show');
        Route::post('/return-order', 'submitReturnRequest');
        Route::post('/order-details', 'showByOrderId');
        Route::delete('/draft-orders/{id}', 'deleteDraftOrder');
        Route::get('/customer/invoices/{ids}', 'downloadCustomerInvoice');
        Route::get('/customer-return-requests', 'listReturnRequestsForCustomer');
    });


    Route::prefix('customer-messages')->middleware('auth:sanctum')->controller(CustomerMessageController::class)->group(function () {
        // Customer APIs
        Route::post('/send', 'store');
        Route::get('/my-messages', 'myMessages');

        // Admin APIs
        // Route::post('/admin-reply', 'adminReply');
        // Route::get('/admin-messages', 'adminMessages');
        // Route::get('/show/{id}', 'showMessage');
        // Route::post('/mark-read', 'markAsRead');
        // Route::post('/mark-unread', 'markAsUnread');
        // Route::post('/toggle-flag', 'toggleFlag');
        // Route::put('/update/{id}', 'updateMessage');
        // Route::post('/delete', 'deleteMessages');
    });

    // Customer self-service (no permission — own account)
    Route::prefix('customers')->middleware('auth:sanctum')->controller(CustomerController::class)->group(function () {
        Route::post('/account-settings', 'updateAccount');
        Route::get('/my-orders', 'myOrders');
        Route::get('/my-addresses', 'getMyAddresses');
        // Route::delete('/delete-my-address/{addressId}', 'deleteMyAddress');
        Route::put('/update-customer/{id}', 'update');
    });
    Route::prefix('order-items')->middleware('auth:sanctum')->controller(OrderController::class)->group(function () {
        Route::get('/order-items/{orderId}', 'webIndex');
    });
    Route::prefix('customer-address')->middleware('auth:sanctum')->controller(CustomerAddressController::class)->group(function () {
        Route::post('/store', 'store');
        Route::get('/list', 'index');
        Route::put('/update/{id}', 'update');
        Route::delete('/delete/{id}', 'destroy');
    });
    // ⭐ Reviews
    Route::prefix('reviews')->controller(ReviewController::class)->group(function () {
        Route::get('/product/{productId}', 'index');
        Route::post('/add', 'store');
        Route::post('/web-reviews', 'getReviews');
    });

    Route::prefix('checkout-form')
        ->controller(CheckoutFormDataController::class)
        ->group(function () {

            Route::post('/save', 'store');

            Route::get('/get', 'index');
        });

    Route::prefix('carousels')->controller(CarouselController::class)->group(function () {
        Route::get('/get-crousel', 'index');
        Route::get('/get-single/{id}', 'show');
    });

    Route::prefix('logos')->controller(LogoController::class)->group(function () {
        Route::get('/get-logos', 'index');          // Paginated list
        Route::get('/get-logo/{id}', 'showById');   // Get single logo by ID
    });

    Route::prefix('email-marketings')->controller(EmailMarketingController::class)->group(function () {
        Route::get('/get-email-marketing', 'show');
    });

    //Contact Us
    Route::prefix('contact-requests')->controller(ContactRequestController::class)->group(function () {

        Route::post('/submit', 'store');           // POST - Form Submit
        Route::get('/', 'index');                  // GET  - All Requests
        Route::get('/{contactRequest}', 'show');   // GET  - Single Request
    });

    Route::prefix('bulk-inquiries')->controller(BulkInquiryController::class)->group(function () {
        Route::post('/submit', 'store');          // Submit bulk inquiry
    });

    Route::prefix('store-setting')->controller(StoreSettingController::class)->group(function () {
        Route::get('/get-store-setting', 'index');
        Route::get('/get-website-seo', 'getWebsiteSeo');
    });


    Route::prefix('coupons')->controller(CouponCodeController::class)->group(function () {
        Route::get('/get-couponcode', 'showWebCouponCode');
        Route::get('/my-coupon-usage', 'getMyCouponUsage');
        Route::delete('/my-coupon-usage/{id?}', 'deleteMyCouponUsage');
    });

    Route::prefix('scripts')->controller(ScriptController::class)->group(function () {
        Route::get('/get-script', 'index');          // Paginated list
        Route::get('/get-script/{id}', 'show');   // Get single logo by ID
    });

    // banner get
    Route::get('banners/by-location', [BannerController::class, 'getByLocation']);
    //Newsletters
    Route::apiResource('newsletters', NewsletterController::class);

    //VisitorSession
    Route::post('/visitor-session', [VisitorSessionController::class, 'store']);
    Route::get('/visitor-session/stats', [VisitorSessionController::class, 'stats']);

    //globalSearch
    Route::get('/search', [SearchController::class, 'globalSearch']);
    //get Currency
    Route::get('/currencies', [CurrencyController::class, 'allCurrencyWeb']);

    // 💳 Stripe - User/Guest (token optional)
    Route::post('/stripe/pay', [StripePaymentController::class, 'pay']);

    // 📩 Webhook (no auth)
    Route::post('/stripe/webhook', [StripePaymentController::class, 'handleWebhook']);

    Route::post(
        '/stripe/express-checkout/create-intent',
        [StripePaymentController::class, 'createExpressCheckoutIntent']
    );

    Route::post(
        'stripe/express-checkout/update-intent',
        [StripePaymentController::class, 'updateExpressCheckoutIntent']
    );

    // ── Checkout Shipping Rates (public) ──
    Route::prefix('checkout')->controller(CheckoutShippingController::class)->group(function () {
        Route::get('/get-shipping-rates', 'getRates');
        Route::post('/get-shipping-rates', 'getRates');
    });
});


// ===================== SITEMAP ROUTES (Clean Version) =====================
// Route::prefix('sitemap')->group(function () {

//     // Main Entry Point (yeh important hai)
//     Route::get('/xmlsitemap', [SitemapController::class, 'index']);

//     // Individual sitemaps
//     Route::get('/pages',       [SitemapController::class, 'pagesXml']);
//     Route::get('/products/{page?}', [SitemapController::class, 'productsXml'])
//         ->where('page', '[0-9]+');

//     Route::get('/categories/{page?}', [SitemapController::class, 'categoriesXml'])
//         ->where('page', '[0-9]+');

//     Route::get('/brands/{page?}', [SitemapController::class, 'brandsXml'])
//         ->where('page', '[0-9]+');

//     Route::post('/clear-cache', [SitemapController::class, 'clearCache']);
// });
Route::get('/check-redirect', function (\Illuminate\Http\Request $request) {
    $path = $request->query('path');
    $storeId = $request->query('store_id');

    if (!$path || !$storeId) {
        return response()->json(['redirect' => null]);
    }

    $seo = \App\Models\ProductSeo::where('store_id', $storeId)
        ->where(function ($q) use ($path) {
            $clean = rtrim($path, '/');
            $q->where('product_url', $path)
                ->orWhere('product_url', $clean)
                ->orWhere('product_url', $clean . '/');
        })
        ->first();

    if ($seo) {
        $redirect = \App\Models\Redirect::where('store_id', $storeId)
            ->where('old_url', $seo->product_url)
            ->where('is_active', true)
            ->first();

        if ($redirect && !empty($redirect->new_url) && $redirect->new_url !== $seo->product_url) {
            return response()->json(['redirect' => $redirect->new_url]);
        }
    }

    return response()->json(['redirect' => null]);
});

Route::prefix('sitemap')
    ->middleware(['store.hash', 'tenant.database'])
    ->group(function () {

        Route::get('/xmlsitemap', [SitemapController::class, 'index']);

        Route::get('/pages', [SitemapController::class, 'pagesXml']);

        Route::get('/products/{page?}', [SitemapController::class, 'productsXml'])
            ->where('page', '[0-9]+');

        Route::get('/categories/{page?}', [SitemapController::class, 'categoriesXml'])
            ->where('page', '[0-9]+');

        Route::get('/brands/{page?}', [SitemapController::class, 'brandsXml'])
            ->where('page', '[0-9]+');

        Route::post('/clear-cache', [SitemapController::class, 'clearCache'])
            ->middleware('auth:sanctum');
    });

Route::get('/test-s3', function () {
    $path = 'debug/tests.txt';

    return [
        'put_result' => Storage::disk('s3')->put($path, 'S3 DEBUG FILE'),
        'exists' => Storage::disk('s3')->exists($path),
        'url' => Storage::disk('s3')->url($path),
    ];
});

// Route::post('/test/fedex/rates', [FedExTestController::class, 'rates']);

// Route::get('/test-store-hash', function (Request $request) {
//     $store = current_store_registry();

//     return response()->json([
//         'success' => true,
//         'store_hash' => current_store_hash(),
//         'registry_id' => $store->id ?? null,
//         'legacy_store_id' => $store->legacy_store_id ?? null,
//         'provisioning_status' => $store->provisioning_status ?? null,
//     ]);
// })->middleware('store.hash');

// Route::get('/test-tenant-connection', function (Request $request) {
//     $store = $request->attributes->get('tenant_store');

//     return response()->json([
//         'success' => true,
//         'store_hash' => $store->store_hash,
//         'database' => DB::connection('tenant')->getDatabaseName(),
//         'tenant_record' => $store->name ?? null,
//     ]);
// })->middleware(['store.hash', 'tenant.database']);

// Route::get('/debug-sodium', function () {
//     return response()->json([
//         'php_version' => PHP_VERSION,
//         'php_binary' => PHP_BINARY,
//         'sodium_loaded' => extension_loaded('sodium'),
//         'secretbox_open_exists' => function_exists('sodium_crypto_secretbox_open'),
//     ]);
// });