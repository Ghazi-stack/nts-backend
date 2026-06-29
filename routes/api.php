<?php

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
    StoreSettingController,
    UrlSettingController
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




Route::post('/auth/login', [AuthController::class, 'login']);

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


Route::prefix('dashboard')->middleware('auth:sanctum')->group(function () {

    // Profile
    Route::prefix('profile')->controller(AuthController::class)->group(function () {
        Route::post('/update-email', 'updateEmail');
        Route::put('/update-name', 'updateName');
        Route::post('/update-password', 'updatePassword');
    });

    Route::middleware('auth:sanctum')->post('/login-as-customer', [AuthController::class, 'loginAsCustomer']);
    // Main Search
    Route::get('/global-search', [GlobalSearchController::class, 'search']);
    // 🏢 Store Management

    Route::prefix('stores')->controller(StoreController::class)->group(function () {
        Route::get('/list-stores', 'index');
        Route::post('/select-store', 'selectStore');
    });
    // 📊 Dashboard
    Route::get('/store-performance', [HomeController::class, 'storePerformance']);
    Route::get('/store-count', [HomeController::class, 'storeCount']);
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
        Route::get('/get-return-order', 'listReturnRequestsForAdmin');
        Route::get('/get-notes/{orderId}', 'getOrderNotes');
        Route::post('/existing-customer', 'storeFromDashboard');
        Route::get('/get-draft-order', 'getDraftOrders');
        Route::post('/new-customer', 'storeNewCustomerOrder');
        Route::post('/search-advanced', 'advancedOrderSearch');
        Route::put('/update-order/{id}', 'updateFromDashboard');
        Route::get('/invoice/{id}', 'downloadInvoice');
        Route::post('/resend-invoice/{id}', 'resendInvoice');
        Route::post('/refund/{id}', 'refund');
        Route::get('/timeline/{id}', 'getOrderTimeline');
        Route::get('/customer-by-order/{orderId}', 'getCustomerByOrder');
        Route::get('/send-payment-invoice/{id}', 'sendPaymentInvoice');
        Route::get('/customer/{customerId}/orders', 'getCustomerWithOrders');
    });

    // Web Pages
    Route::prefix('webpages')->controller(WebPageController::class)->group(function () {
        Route::get('/web-pages', 'index');
        Route::get('/single-page/{id}', 'show');
        Route::post('/create-page', 'store');
        Route::put('update/{id}', 'update');
        Route::delete('/delete/{ids}', 'destroy');
    });

    Route::prefix('orders-tracking')->controller(OrderTrackingImportController::class)->group(function () {
        Route::post('/tracking/import', 'import');
        Route::get('/tracking/template', 'template');
        Route::get('/tracking/export', 'export');
        Route::post('/{order}/tracking', 'setSingle');
    });

    Route::prefix('order-items')->controller(OrderItemController::class)->group(function () {
        Route::get('/{orderId}', 'index');
        Route::post('/add/{orderId}', 'store');
        Route::put('/update/{id}', 'update');
        Route::delete('/delete/{id}', 'destroy');
    });

    Route::prefix('shipments')->controller(ShipmentController::class)->group(function () {
        Route::get('/list-shipment', 'index');              // List all shipments (paginated)
        Route::get('/get-shipment/{id}', 'show');           // Get one shipment
        Route::get('/shipment-by-order', 'getShipmentByOrderId');           // getShipmentByOrderIdshipment
        Route::post('/add-shipment', 'store');             // Create shipment (optional tracking number)
        Route::put('/update-shipment/{id}', 'update');         // Update shipped_to, date, or tracking
        Route::delete('/destroy-shipment', 'destroy');     // Delete shipment
        Route::get('/packing-slip/{id}', 'printPackingSlip');
        Route::post('/advanced-search', 'advancedSearch');
    });

    // Customers

    Route::prefix('customers')->group(function () {
        Route::post('/account-settings', [CustomerController::class, 'updateAccount']);
        Route::get('/my-orders', [CustomerController::class, 'myOrders']);
        Route::get('/my-addresses', [CustomerController::class, 'getMyAddresses']);
        Route::get('/get-customers', [CustomerController::class, 'index']);
        Route::get('/get-customer/{id}', [CustomerController::class, 'show']);
        Route::post('/add-customer', [CustomerController::class, 'store']);
        Route::put('/update-customer/{id}', [CustomerController::class, 'update']);
        Route::delete('/delete-customer/{id?}', [CustomerController::class, 'destroy']);
        Route::delete('/delete-my-address/{addressId}', [CustomerController::class, 'deleteMyAddress']);
        Route::post('/import', [CustomerController::class, 'importCSV']);
        Route::get('/export', [CustomerController::class, 'exportCSV']);
        Route::post('/customer-search', [CustomerController::class, 'advancedSearch']);
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
        Route::post('/update-categorySingle/{id}', 'updateCategoryExtras');
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
        Route::get('/products-filter', 'filterProducts');
        Route::get('/product/{id}', 'show');
        Route::get('/show-product/{slug}', 'showProduct');
        Route::post('/add-product', 'addProduct');
        Route::put('/update-product', 'updateProduct');
        Route::post('/update-single-product/{id}', 'updateSingleProduct');
        Route::delete('/delete-product/{id?}', 'destroyProduct');
        Route::get('/search-product', 'searchProduct');
        Route::post('/advanced-search', 'searchAdvanced');
        Route::post('/import-csv', 'importCSV');
        Route::post('/cancel-import', 'cancelImport');
        Route::get('/import-progress/{progressKey}', 'importProgress');
        Route::get('/export-csv', 'exportCSV');
        Route::delete('/delete-product/categories/{id}', 'destroyProductCategories');
        Route::get('/featured-products', 'featuredProducts');
    });

    // EMAIL Marketing
    Route::prefix('email-marketings')->controller(EmailMarketingController::class)->group(function () {
        Route::get('/get-email-marketing', 'show');
        Route::put('/email-marketing', 'update');
    });

    // ✉️ Transactional Emails (store-wise)
    Route::prefix('transactional-emails')->controller(TransactionalEmailController::class)->group(function () {
        Route::get('/get', 'index');        // list with pagination & search
        Route::get('/get-single/{key}', 'show');         // get single template by key
        Route::post('/create-key/{key}', 'upsert');       // create/update template
        Route::patch('/toggle/{key}', 'toggle');       // enable/disable
        Route::post('/preview/{key}', 'preview');      // render preview (subject/html/text)
        Route::post('/send-test/{key}', 'sendTest');   // send test email
    });
    // 🏷️ Promotions
    Route::prefix('promotions')->controller(PromotionController::class)->group(function () {
        Route::get('/list-promotion', 'index');           // list (with optional ?status=&type=)
        Route::get('/get-promotion/{id}', 'show');         // single
        Route::post('/add-promotion', 'store');            // create
        Route::post('/update-promotion/{id}', 'update');   // update (POST like banners/brands)
        Route::delete('/delete-promotion/{id}', 'destroy'); // delete
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

    // 📝 Blog Posts
    Route::prefix('blogs')->controller(BlogPostController::class)->group(function () {
        Route::get('/get-blog', [BlogPostController::class, 'index']);
        Route::get('/get-blogSingle/{id}', [BlogPostController::class, 'show']);
        Route::post('/add-blog', [BlogPostController::class, 'store']);
        Route::post('/update-blog/{id}', [BlogPostController::class, 'update']); // or PUT
        Route::delete('/delete-blog/{ids}', [BlogPostController::class, 'destroy']);
        Route::get('/draft-blogs', [BlogPostController::class, 'drafts']);
    });


    Route::prefix('carousels')->controller(CarouselController::class)->group(function () {
        Route::get('/get-crousel', 'index');
        Route::get('/get-single/{id}', 'show');             // Get all carousels
        Route::post('/add-crousel', 'store');
        Route::post('/update-carousel/{id}', 'update');         //    // Update carousel
        Route::delete('/delete-carousel/{id}', 'destroy'); // Delete carousel       // Add new carousel
        // You can add update/delete routes if needed
    });

    Route::prefix('logos')->controller(LogoController::class)->group(function () {
        Route::get('/get-logos', 'index');          // Paginated list
        Route::get('/get-logo/{id}', 'showById');   // Get single logo by ID
        Route::post('/add-logo', 'store');            // Create new logo
        Route::post('/update-logo/{id}', 'update');      // Update existing logo
        Route::delete('/delete-logo/{ids}', 'destroy');  // Delete one or multiple logos
    });

    // scripts api endpoint
    Route::prefix('scripts')->controller(ScriptController::class)->group(function () {
        Route::get('/get-script', 'index');          // Paginated list
        Route::get('/get-script/{id}', 'show');   // Get single logo by ID
        Route::post('/add-script', 'store');            // Create new logo
        Route::put('/update-script/{id}', 'update');      // Update existing logo
        Route::delete('/delete-script/{ids}', 'destroy');  // Delete one or multiple logos
    });

    // CouponCode api endpoint
    Route::prefix('coupons')->controller(CouponCodeController::class)->group(function () {
        Route::get('/get-couponcode', 'index');                 // Get all coupons
        Route::get('/get-couponcode/{id}', 'show');             // Get coupon by ID
        Route::post('/add-couponcode', 'store');                // Add new coupon
        Route::put('/update-couponcode/{id}', 'update');        // Update coupon
        Route::delete('/delete-couponcode/{id}', 'destroy');    // Delete coupon

    });

    //Review dashboard
    Route::prefix('reviews')->controller(ReviewController::class)->group(function () {
        Route::get('/admin', 'adminIndex');
        Route::get('/get-by-id/{id}', 'show');
        Route::put('/update-review/{id}', 'update');
        Route::put('/bulk-status-review', 'bulkStatusUpdate');
        Route::post('/approve', 'approveSelected');
        Route::post('/disapprove', 'disapproveSelected');
        Route::post('/delete', 'deleteSelected');
        Route::delete('/bulk-delete-review', 'bulkDelete');
    });

    // StoreSetting api endpoint
    Route::prefix('store-setting')->controller(StoreSettingController::class)->group(function () {
        Route::get('/get-store-setting', 'index');
        Route::post('/add-store-setting', 'store');
    });

    // UPDATE (Create + Update dono)
    Route::post('/url-settings/product',  [UrlSettingController::class, 'updateProduct']);
    Route::post('/url-settings/category', [UrlSettingController::class, 'updateCategory']);
    Route::post('/url-settings/webpage',  [UrlSettingController::class, 'updateWebpage']);

    // GET current settings
    Route::get('/url-settings/{entity}', [UrlSettingController::class, 'show']);
    // {entity} = product / category / webpage

    // ── Store Pickup/Origin Address ──
    Route::prefix('store-shipping-address')->controller(StoreShippingAddressController::class)->group(function () {
        Route::get('/get-address', 'index');
        Route::post('/save-address', 'store');
    });

    // ── Shipping Zones ──
    Route::prefix('shipping-zones')->controller(ShippingZoneController::class)->group(function () {
        Route::get('/get-zones', 'index');
        Route::post('/add-zone', 'store');
        Route::get('/get-zone/{id}', 'show');
        Route::put('/update-zone/{id}', 'update');
        Route::delete('/delete-zone/{id}', 'destroy');
    });

    // ── Shipping Methods ──
    Route::prefix('shipping-methods')->controller(ShippingMethodController::class)->group(function () {
        Route::get('/get-methods/{zoneId}', 'index');
        Route::post('/add-method/{zoneId}', 'store');
        Route::put('/update-method/{id}', 'update');
        Route::delete('/delete-method/{id}', 'destroy');
        Route::patch('/toggle-method/{id}', 'toggle');
    });

    // ── FedEx Configuration ──
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

    // 💰 stripe Capture 
    Route::post('/stripe/capture', [StripePaymentController::class, 'capturePayment']);
});





// ===============================
// 🌐 PUBLIC + WEB ROUTES
// ===============================


// 🔓 Public Routes (accessible without login)

Route::prefix('web')->group(function () {

    Route::middleware('auth:sanctum')->get('/customer-profile', [AuthController::class, 'getCustomerProfile']);

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
        Route::get('/products/by-brand', 'productsByBrand');
        Route::get('/popular-products', 'popularProducts');
        Route::get('/last-week-orders', 'lastWeekOrderedProducts');
        Route::get('/last-week-product', 'lastWeekProducts');
    });
    // 🛒 Cart (Guest + Auth dono)
    Route::prefix('cart')->controller(CartController::class)->group(function () {
        Route::get('/list',           'index');       // GET   - Cart list dekho
        Route::post('/add-cart',      'storeCart');   // POST  - Item add karo
        Route::put('/update/{id}',    'updateCart');  // PUT   - Quantity set karo
        Route::delete('/delete/{id}', 'destroyCart');
        Route::post('/add/shipping-by-rate', 'saveShippingRate');
        Route::get('/get/shipping-by-rate', 'getShippingRate');
    });

    // 🔀 Cart Transfer (auth required)
    Route::prefix('cart')->middleware('auth:sanctum')->controller(CartController::class)->group(function () {
        Route::post('/transfer', 'transferGuestCartToUser');
    });

    // ❤️ Wishlist (auth required)
    Route::prefix('wishlist')->middleware('auth:sanctum')->controller(WishlistController::class)->group(function () {
        Route::get('/list', 'index');
        Route::post('/add', 'store');
        Route::delete('/remove/{id}', 'destroy');
    });
    Route::prefix('orders')->controller(OrderController::class)->group(function () {
        Route::post('/place-order', 'store');
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
    });

    Route::prefix('customer-messages')->middleware('auth:sanctum')->controller(CustomerMessageController::class)->group(function () {
        // Customer APIs
        Route::post('/send', 'store');
        Route::get('/my-messages', 'myMessages');

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
    Route::prefix('order-items')->middleware('auth:sanctum')->controller(OrderController::class)->group(function () {
        Route::get('/order-items/{orderId}', 'webIndex');
    });
    // ⭐ Reviews
    Route::prefix('reviews')->controller(ReviewController::class)->group(function () {
        Route::get('/product/{productId}', 'index');
        Route::post('/add', 'store');
    });

    Route::prefix('carousels')->controller(CarouselController::class)->group(function () {
        Route::get('/get-crousel', 'index');
        Route::get('/get-single/{id}', 'show');
    });

    Route::prefix('logos')->controller(LogoController::class)->group(function () {
        Route::get('/get-logos', 'index');          // Paginated list
        Route::get('/get-logo/{id}', 'showById');   // Get single logo by ID
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
    });

    Route::prefix('coupons')->controller(CouponCodeController::class)->group(function () {
        Route::get('/get-couponcode', 'showWebCouponCode');
    });

    Route::prefix('scripts')->controller(ScriptController::class)->group(function () {
        Route::get('/get-script', 'index');          // Paginated list
        Route::get('/get-script/{id}', 'show');   // Get single logo by ID
    });

    //Newsletters
    Route::apiResource('newsletters', NewsletterController::class);

    //VisitorSession
    Route::post('/visitor-session', [VisitorSessionController::class, 'store']);

    //globalSearch
    Route::get('/search', [SearchController::class, 'globalSearch']);

    // 💳 Stripe - User/Guest (token optional)
    Route::post('/stripe/pay', [StripePaymentController::class, 'pay']);

    // 📩 Webhook (no auth)
    Route::post('/stripe/webhook', [StripePaymentController::class, 'handleWebhook']);

    // ── Checkout Shipping Rates (public) ──
    Route::prefix('checkout')->controller(CheckoutShippingController::class)->group(function () {
        Route::get('/get-shipping-rates', 'getRates');
        Route::post('/get-shipping-rates', 'getRates');
    });
});


// ===================== SITEMAP ROUTES (Clean Version) =====================
Route::prefix('sitemap')->group(function () {

    // Main Entry Point (yeh important hai)
    Route::get('/xmlsitemap', [SitemapController::class, 'index']);

    // Individual sitemaps
    Route::get('/pages',       [SitemapController::class, 'pagesXml']);
    Route::get('/products/{page?}', [SitemapController::class, 'productsXml'])
        ->where('page', '[0-9]+');

    Route::get('/categories/{page?}', [SitemapController::class, 'categoriesXml'])
        ->where('page', '[0-9]+');

    Route::get('/brands/{page?}', [SitemapController::class, 'brandsXml'])
        ->where('page', '[0-9]+');

    Route::post('/clear-cache', [SitemapController::class, 'clearCache']);
});

Route::get('/test-s3', function () {
    $path = 'debug/tests.txt';

    return [
        'put_result' => Storage::disk('s3')->put($path, 'S3 DEBUG FILE'),
        'exists' => Storage::disk('s3')->exists($path),
        'url' => Storage::disk('s3')->url($path),
    ];
});
