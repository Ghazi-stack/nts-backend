<?php

namespace App\Http\Controllers\api\Admin;

use Exception;
use App\Models\User;
use App\Models\Brand;
use App\Models\Order;
use League\Csv\Reader;
use App\Models\Product;
use App\Models\Category;
use App\Models\ProductSeo;
use Illuminate\Support\Str;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use App\Exports\ProductExport;
use App\Imports\ProductImport;
use App\Models\ProductContent;
use App\Models\ProductIdentifier;
use App\Jobs\ProcessProductImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\PopularProductResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\OrderItem;
use Carbon\Carbon;

class ProductController extends Controller
{
    // public function index(Request $request)
    // {
    //     try {
    //         $query = Product::with(['brand', 'categories', 'images','seo','content'])
    //             ->where('store_id', current_store_id());

    //         if ($request->has('isFeatured')) {
    //             $query->where('is_featured', $request->boolean('isFeatured'));
    //         }

    //         if ($request->has('isVisible')) {
    //             $query->where('visible_on_storefront', $request->boolean('isVisible'));
    //         }

    //         if ($request->has('outOfStock') && $request->boolean('outOfStock')) {
    //             $query->where('current_stock', '<=', 0);
    //         }

    //         if ($request->has('inventoryLow')) {
    //             $query->whereColumn('current_stock', '<=', 'low_stock');
    //         }

    //         if ($request->has('search')) {
    //             $q = $request->search;
    //             $query->where(function ($sub) use ($q) {
    //                 $sub->where('name', 'like', "%$q%")
    //                     ->orWhere('sku', 'like', "%$q%");
    //             });
    //         }

    //         if ($request->has('sortBy')) {
    //             $query->orderBy($request->sortBy, $request->get('sortOrder', 'asc'));
    //         } else {
    //             $query->orderBy('id', 'desc');
    //         }

    //         $page = (int) $request->input('page', 1);
    //         $pageSize = (int) $request->input('pageSize', 50);
    //         $products = $query->paginate($pageSize, ['*'], 'page', $page);

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Product list fetched.',
    //             'data' => ProductResource::collection($products),
    //             'pagination' => [
    //                 'total' => $products->total(),
    //                 'page' => $products->currentPage(),
    //                 'pageSize' => $products->perPage(),
    //                 'lastPage' => $products->lastPage()
    //             ]
    //         ]);
    //     } catch (Exception $e) {
    //         return response()->json(['error' => $e->getMessage()], 500);
    //     }
    // }

    public function index(Request $request)
    {
        try {
            $query = Product::with([
                'brand:id,name',
                'categories:id,name',
                'images:id,product_id,path,is_primary'
            ])
                ->select([
                    'id',
                    'name',
                    'slug',
                    'sku',
                    'price',
                    'msrp',
                    'brand_id',
                    'current_stock',
                    'is_featured',
                    'visible_on_storefront',
                    'store_id'
                ])
                ->where('store_id', current_store_id());

            if ($request->has('isFeatured')) {
                $query->where('is_featured', $request->boolean('isFeatured'));
            }
            if ($request->has('freeShipping')) {
                $query->where('free_shipping', $request->boolean('freeShipping'));
            }

            if ($request->has('outOfStock') && $request->boolean('outOfStock')) {
                $query->where('current_stock', '<=', 0);
            }

            if ($request->has('inventoryLow')) {
                $query->whereColumn('current_stock', '<=', 'low_stock');
            }

            if ($request->has('search')) {
                $q = $request->search;
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%$q%")
                        ->orWhere('sku', 'like', "%$q%");
                });
            }

            if ($request->has('sortBy')) {
                $query->orderBy($request->sortBy, $request->get('sortOrder', 'asc'));
            } else {
                $query->orderBy('id', 'desc');
            }

            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 20);

            $products = $query->paginate($pageSize, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'message' => 'Product list fetched.',
                'data' => ProductListResource::collection($products),
                'pagination' => [
                    'total' => $products->total(),
                    'page' => $products->currentPage(),
                    'pageSize' => $products->perPage(),
                    'lastPage' => $products->lastPage()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function filterProducts(Request $request)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            $hasCategory = $request->has('isCategory') && !empty($request->isCategory);
            $hasSku      = $request->has('isSku') && !empty($request->isSku);

            // ✅ If neither filter provided
            if (!$hasCategory && !$hasSku) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Please provide at least one filter: isCategory or isSku.'
                ], 422);
            }

            $categoryIds = [];

            $baseQuery = Product::with([
                'brand:id,name',
                'categories:id,name',
                'images:id,product_id,path,is_primary'
            ])
                ->select([
                    'id',
                    'name',
                    'slug',
                    'sku',
                    'price',
                    'msrp',
                    'brand_id',
                    'category_id',
                    'current_stock',
                    'is_featured',
                    'visible_on_storefront',
                    'store_id'
                ])
                ->where('store_id', $storeId);

            // ✅ Validate category IDs
            if ($hasCategory) {
                $categoryIds = array_filter(array_map('intval', explode(',', $request->isCategory)));

                if (empty($categoryIds)) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Invalid category IDs provided.'
                    ], 422);
                }
            }

            // ✅ Both filters — run separately then merge
            if ($hasCategory && $hasSku) {

                $categoryProducts = (clone $baseQuery)
                    ->whereIn('category_id', $categoryIds)
                    ->orderBy('id', 'desc')
                    ->get();

                $skuProducts = (clone $baseQuery)
                    ->where('sku', $request->isSku)
                    ->orderBy('id', 'desc')
                    ->get();

                $categoryFound = $categoryProducts->isNotEmpty();
                $skuFound      = $skuProducts->isNotEmpty();

                // ✅ Neither found
                if (!$categoryFound && !$skuFound) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'No product found against this SKU and Category.',
                        'data'    => []
                    ], 404);
                }

                // ✅ Merge both (unique by id — same product won't duplicate)
                $merged = $categoryProducts->merge($skuProducts)->unique('id')->values();

                // ✅ Category found, SKU not found — return category data + SKU message
                if ($categoryFound && !$skuFound) {
                    return response()->json([
                        'status'  => true,
                        'message' => 'Products fetched from Category. No product found against this SKU.',
                        'data'    => ProductListResource::collection($merged),
                    ]);
                }

                // ✅ SKU found, Category not found — return SKU data + Category message
                if ($skuFound && !$categoryFound) {
                    return response()->json([
                        'status'  => true,
                        'message' => 'Products fetched from SKU. No product found against this Category.',
                        'data'    => ProductListResource::collection($merged),
                    ]);
                }

                // ✅ Both found — merge, deduplicate by id
                return response()->json([
                    'status'  => true,
                    'message' => 'Filtered products fetched successfully.',
                    'data'    => ProductListResource::collection($merged),
                ]);
            }

            // ✅ Only Category filter
            if ($hasCategory) {
                $baseQuery->whereIn('category_id', $categoryIds);
            }

            // ✅ Only SKU filter
            if ($hasSku) {
                $baseQuery->where('sku', $request->isSku);
            }

            $products = $baseQuery->orderBy('id', 'desc')->get();

            // ✅ Single filter not found messages
            if ($products->isEmpty()) {
                $message = $hasSku
                    ? 'No product found against this SKU.'
                    : 'No product found against this Category.';

                return response()->json([
                    'status'  => false,
                    'message' => $message,
                    'data'    => []
                ], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Filtered products fetched successfully.',
                'data'    => ProductListResource::collection($products),
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $product = Product::with(['brand', 'categories', 'images', 'content', 'seo'])
                ->where('store_id', current_store_id())
                ->findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Product fetched successfully.',
                'data' => new ProductResource($product)
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found.'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function showProduct($sku, Request $request)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected.'
            ], 400);
        }

        $cacheKey = "product_detail_store_{$storeId}_sku_" . md5($sku);

        try {

            // -------------------------
            // STEP 1: Product + LIGHT relations
            // -------------------------
            $product = Cache::remember($cacheKey, 600, function () use ($sku, $storeId) {
                return Product::where('sku', $sku)
                    ->where('store_id', $storeId)
                    ->where('visible_on_storefront', 1)
                    ->with([
                        'brand:id,name,logo',
                        'images:id,product_id,path,is_primary',
                        'content',
                        'identifiers',
                        'seo'
                    ])
                    ->first();
            });

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'error' => 'Product not found',
                    'sku' => $sku
                ], 404);
            }

            // -------------------------
            // STEP 1.5: Visible check
            // -------------------------
            if (!$product->visible_on_storefront) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product is disabled.'
                ], 403);
            }

            // -------------------------
            // STEP 2: Heavy relations (categories + reviews)
            // -------------------------
            // $categories = Cache::remember("product_categories_{$product->id}", 3600, function () use ($product) {
            //     // **LIMIT recursion** to 2 levels only (fast)
            //     return $product->categories()->with('parent.parent')->get();
            // });

            $reviews = Cache::remember("product_reviews_{$product->id}", 300, function () use ($product) {
                // **LIMIT reviews** to 10 (fast)
                return $product->reviews()
                    ->with('user:id,name')
                    ->latest()
                    ->limit(10)
                    ->get();
            });

            // $product->setRelation('categories', $categories);
            $product->setRelation('reviews', $reviews);

            return response()->json([
                'status' => true,
                'message' => 'Product Retrieved',
                'data' => new ProductResource($product)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => 'Something went wrong',
                'debug' => $e->getMessage()
            ], 500);
        }
    }

    public function showProductByUrl($slug, Request $request)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected.'
            ], 400);
        }

        $cacheKey = "product_by_url_store_{$storeId}_slug_" . md5($slug);

        try {

            // -------------------------
            // STEP 1: Try productUrl first, then SKU
            // -------------------------
            $product = Cache::remember($cacheKey, 600, function () use ($slug, $storeId) {

                // First: try to find by productUrl in SEO table
                $product = Product::where('store_id', $storeId)
                    ->where('visible_on_storefront', 1)
                    ->whereHas('seo', function ($q) use ($slug) {
                        $q->where('product_url', $slug);
                    })
                    ->with([
                        'brand:id,name,logo',
                        'images:id,product_id,path,is_primary',
                        'content',
                        'identifiers',
                        'seo'
                    ])
                    ->first();

                // Second: if not found by productUrl, try SKU
                if (!$product) {
                    $product = Product::where('store_id', $storeId)
                        ->where('visible_on_storefront', 1)
                        ->where('sku', $slug)
                        ->with([
                            'brand:id,name,logo',
                            'images:id,product_id,path,is_primary',
                            'content',
                            'identifiers',
                            'seo'
                        ])
                        ->first();
                }

                return $product;
            });

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'error' => 'Product not found',
                ], 404);
            }

            // -------------------------
            // STEP 1.5: Visible check
            // -------------------------
            if (!$product->visible_on_storefront) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product is disabled.'
                ], 403);
            }

            // -------------------------
            // STEP 2: Heavy relations (reviews)
            // -------------------------
            $reviews = Cache::remember("product_reviews_{$product->id}", 300, function () use ($product) {
                return $product->reviews()
                    ->with('user:id,name')
                    ->latest()
                    ->limit(10)
                    ->get();
            });

            $product->setRelation('reviews', $reviews);

            return response()->json([
                'status' => true,
                'message' => 'Product Retrieved',
                'data' => new ProductResource($product)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => 'Something went wrong',
                'debug' => $e->getMessage()
            ], 500);
        }
    }

    public function addProduct(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['error' => 'Store not selected or token invalid.'], 400);
            }

            $validated = $request->validate([
                'name' => 'nullable|string|max:255',
                'sku' => 'nullable|string|unique:products,sku',
                'brandId' => 'required|exists:brands,id',
                'categoryIds' => 'required|array|min:1',
                'categoryIds.*' => 'exists:categories,id',
                'condition' => 'nullable|string',
                'price' => 'nullable|numeric',
                'quantity' => 'nullable|integer',
                'msrp' => 'nullable|integer',
                'costPrice' => 'nullable|numeric',
                'salePrice' => 'nullable|numeric',

                'dimensions' => 'nullable|array',
                'dimensions.weight' => 'nullable|numeric',
                'dimensions.width' => 'nullable|numeric',
                'dimensions.height' => 'nullable|numeric',
                'dimensions.depth' => 'nullable|numeric',

                'trackInventory' => 'nullable|boolean',
                'currentStock' => 'nullable|integer',
                'lowStock' => 'nullable|integer',

                'isFeatured' => 'nullable|boolean',
                'showCondition' => 'nullable|boolean',
                'sortOrder' => 'nullable|integer',
                'searchKeywords' => 'nullable|string',
                'isVisible' => 'nullable|boolean',
                'customFields' => 'nullable|array',

                // ✅ IMAGE FILES
                'image' => 'nullable|array',
                'image.*.file' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
                'image.*.url' => 'nullable|url',
                'image.*.description' => 'nullable|string|max:255',
                'image.*.isPrimary' => 'nullable|boolean',

                'description' => 'nullable|string',
                'warranty' => 'nullable|string',
                'availabilityText' => 'nullable|string',
                'templateLayout' => 'nullable|string',

                'mpn' => 'nullable|string',
                'upc' => 'nullable|string',
                'gtin' => 'nullable|string',
                'bpn' => 'nullable|string',

                'pageTitle' => 'nullable|string',
                'productUrl' => 'nullable|string',
                'metaDescription' => 'nullable|string',

                'purchasabilityStatus' => 'nullable|string',
                'minPurchaseQuantity' => 'nullable|integer',
                'maxPurchaseQuantity' => 'nullable|integer',

                'relatedProducts' => 'nullable|boolean',
                'fixedShippingCost' => 'nullable|numeric',

                'productType' => 'nullable|string',
                'optionSet' => 'nullable|string',
                'optionSetAlign' => 'nullable|string',

                'allowPurchase' => 'nullable|boolean',
                'stopProcessingRules' => 'nullable|boolean',

                'taxClass' => 'nullable|string',
                'taxProviderCode' => 'nullable|string',


                'taxCode' => 'nullable|string|max:255',
                'discountType' => 'nullable|string|max:255',
                'bulkPricingTiers' => 'nullable|array',

                'objectType' => 'nullable|string',
                'useProductName' => 'nullable|boolean',
                'graphDescription' => 'nullable|boolean',
                'useThumbnail' => 'nullable|boolean',
                'dontUse' => 'nullable|boolean',
                'manageCustoms' => 'nullable|boolean',

                'commodityDescription' => 'nullable|string',
                'countryOfOrigin' => 'nullable|string',

                'hsCodes' => 'nullable|array',
                'hsCodes.*.country' => 'nullable|string',
                'hsCodes.*.code' => 'nullable|string',

            ]);

            // ---------------- PRODUCT ----------------
            $product = Product::create([
                'store_id' => $storeId,
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']) . '-' . uniqid(),
                'sku' => $validated['sku'],
                'price' => $validated['price'],
                'quantity' => $validated['quantity'] ?? null,
                'msrp' => $validated['msrp'] ?? null,
                'cost_price' => $validated['costPrice'] ?? null,
                'sale_price' => $validated['salePrice'] ?? null,
                'brand_id' => $validated['brandId'],
                'condition' => $validated['condition'] ?? null,
                'dimensions' => $validated['dimensions'] ?? [],
                'track_inventory' => $validated['trackInventory'] ?? false,
                'current_stock' => $validated['currentStock'] ?? 0,
                'low_stock' => $validated['lowStock'] ?? 0,
                'is_featured' => $validated['isFeatured'] ?? false,
                'show_condition' => $validated['showCondition'] ?? false,
                'sort_order' => $validated['sortOrder'] ?? 0,
                'search_keywords' => $validated['searchKeywords'] ?? null,
                'visible_on_storefront' => $validated['isVisible'] ?? true,
                'custom_fields' => $validated['customFields'] ?? [],
                'purchasability_status' => $validated['purchasabilityStatus'] ?? null,
                'min_purchase_quantity' => $validated['minPurchaseQuantity'] ?? null,
                'max_purchase_quantity' => $validated['maxPurchaseQuantity'] ?? null,
                'related_products_enabled' => $validated['relatedProducts'] ?? false,
                'fixed_shipping_cost' => $validated['fixedShippingCost'] ?? 0,
                'product_type' => $validated['productType'] ?? '',
                'option_set' => $validated['optionSet'] ?? '',
                'option_set_align' => $validated['optionSetAlign'] ?? '',
                'allow_purchase' => $validated['allowPurchase'] ?? true,
                'stop_processing_rules' => $validated['stopProcessingRules'] ?? false,
                'tax_class' => $validated['taxClass'] ?? '',
                'tax_provider_code' => $validated['taxProviderCode'] ?? '',


                'tax_code' => $validated['taxCode'] ?? null,
                'discount_type' => $validated['discountType'] ?? null,
                'bulk_pricing_tiers' => $validated['bulkPricingTiers'] ?? [],

                'object_type' => $validated['objectType'] ?? null,
                'use_product_name' => $validated['useProductName'] ?? false,
                'graph_description' => $validated['graphDescription'] ?? false,
                'use_thumbnail' => $validated['useThumbnail'] ?? false,
                'dont_use' => $validated['dontUse'] ?? false,
                'manage_customs' => $validated['manageCustoms'] ?? false,

                'commodity_description' => $validated['commodityDescription'] ?? null,
                'country_of_origin' => $validated['countryOfOrigin'] ?? null,

                'hs_codes' => $validated['hsCodes'] ?? [],


            ]);

            $product->categories()->sync($validated['categoryIds']);

            $hasPrimary = false;

            // ---------------- IMAGES ----------------
            if (!empty($validated['image'])) {

                foreach ($validated['image'] as $img) {

                    $path = null;

                    // ✅ FILE IMAGE
                    if (!empty($img['file']) && $img['file'] instanceof \Illuminate\Http\UploadedFile) {

                        $file = $img['file'];

                        $name = Str::slug(
                            pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)
                        );

                        $extension = $file->getClientOriginalExtension();
                        $fileName = $name . '.' . $extension;

                        $path = Storage::disk('s3')->putFileAs(
                            "product_images/{$product->id}",
                            $file,
                            $fileName
                        );
                    }

                    // ✅ URL IMAGE
                    elseif (!empty($img['url'])) {

                        $response = Http::timeout(10)->get($img['url']);
                        if (!$response->successful()) continue;

                        $originalName = pathinfo(
                            parse_url($img['url'], PHP_URL_PATH),
                            PATHINFO_FILENAME
                        );

                        $extension = pathinfo(
                            parse_url($img['url'], PHP_URL_PATH),
                            PATHINFO_EXTENSION
                        ) ?: 'jpg';

                        $fileName = Str::slug($originalName) . '.' . $extension;

                        $path = "product_images/{$product->id}/{$fileName}";

                        Storage::disk('s3')->put($path, $response->body());
                    }

                    if (!$path) continue;

                    ProductImage::create([
                        'store_id'   => $storeId,
                        'product_id' => $product->id,
                        'path'       => $path,
                        'is_primary' => !empty($img['isPrimary']) ? 1 : 0,
                        'alt_text'   => $img['description'] ?? $validated['name'] ?? null,
                    ]);
                }
            }
            // ---------------- CONTENT ----------------
            ProductContent::create([
                'store_id' => $storeId,
                'product_id' => $product->id,
                'description' => $validated['description'] ?? null,
                'warranty' => $validated['warranty'] ?? null,
                'availability_text' => $validated['availabilityText'] ?? null,
                'template_layout' => $validated['templateLayout'] ?? null,
            ]);

            // ---------------- IDENTIFIERS ----------------
            ProductIdentifier::create([
                'store_id' => $storeId,
                'product_id' => $product->id,
                'mpn' => $validated['mpn'] ?? null,
                'upc' => $validated['upc'] ?? null,
                'gtin' => $validated['gtin'] ?? null,
                'bpn' => $validated['bpn'] ?? null,
            ]);

            // ---------------- SEO ----------------
            ProductSeo::create([
                'store_id' => $storeId,
                'product_id' => $product->id,
                'page_title' => $validated['pageTitle'] ?? null,
                'product_url' => $validated['productUrl'] ?? null,
                'meta_description' => $validated['metaDescription'] ?? null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Product created successfully',
                'data' => new ProductResource(
                    $product->load([
                        'brand',
                        'categories',
                        'images',
                        'content',
                        'identifiers',
                        'seo'
                    ])
                )
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function updateSingleProduct(Request $request, $id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['error' => 'Store not selected or token invalid.'], 400);
            }

            $product = Product::where('store_id', $storeId)->findOrFail($id);

            $validated = $request->validate([
                'name' => 'nullable|string|max:255',
                'sku' => 'nullable|string|unique:products,sku,' . $product->id . ',id,store_id,' . $storeId,
                'brandId' => 'required|exists:brands,id',
                'categoryIds' => 'required|array',
                'categoryIds.*' => 'exists:categories,id',
                'condition' => 'nullable|string',
                'price' => 'nullable',
                'quantity' => 'nullable',
                'msrp' => 'nullable',
                'costPrice' => 'nullable',
                'salePrice' => 'nullable',
                'dimensions' => 'nullable|array',
                'dimensions.weight' => 'nullable|numeric',
                'dimensions.width' => 'nullable|numeric',
                'dimensions.height' => 'nullable|numeric',
                'dimensions.depth' => 'nullable|numeric',
                'trackInventory' => 'nullable|boolean',
                'currentStock' => 'nullable|integer',
                'lowStock' => 'nullable|integer',
                'isFeatured' => 'nullable|boolean',
                'showCondition' => 'nullable|boolean',
                'sortOrder' => 'nullable|integer',
                'searchKeywords' => 'nullable|string',
                'isVisible' => 'nullable|boolean',
                'customFields' => 'nullable|array',
                'image' => 'nullable|array',
                'image.*' => 'nullable',
                'description' => 'nullable|string',
                'warranty' => 'nullable|string',
                'availabilityText' => 'nullable|string',
                'templateLayout' => 'nullable|string',
                'mpn' => 'nullable|string',
                'upc' => 'nullable|string',
                'gtin' => 'nullable|string',
                'bpn' => 'nullable|string',
                'pageTitle' => 'nullable|string',
                'productUrl' => 'nullable|string',
                'metaDescription' => 'nullable|string',
                'purchasabilityStatus' => 'nullable|string',
                'minPurchaseQuantity' => 'nullable|integer',
                'maxPurchaseQuantity' => 'nullable|integer',
                'relatedProducts' => 'nullable|boolean',
                'fixedShippingCost' => 'nullable|numeric',
                'productType' => 'nullable|string',
                'optionSet' => 'nullable|string',
                'optionSetAlign' => 'nullable|string',
                'allowPurchase' => 'nullable|boolean',
                'stopProcessingRules' => 'nullable|boolean',
                'taxClass' => 'nullable|string',
                'taxProviderCode' => 'nullable|string',
                'taxCode' => 'nullable|string|max:255',
                'discountType' => 'nullable|string|max:255',
                'bulkPricingTiers' => 'nullable|array',

                'objectType' => 'nullable|string',
                'useProductName' => 'nullable|boolean',
                'graphDescription' => 'nullable|boolean',
                'useThumbnail' => 'nullable|boolean',
                'dontUse' => 'nullable|boolean',
                'manageCustoms' => 'nullable|boolean',

                'commodityDescription' => 'nullable|string',
                'countryOfOrigin' => 'nullable|string',

                'hsCodes' => 'nullable|array',
                'hsCodes.*.country' => 'nullable|string',
                'hsCodes.*.code' => 'nullable|string',
            ]);

            $product->update([
                'name' => $validated['name'] ?? $product->name,
                'slug' => Str::slug($validated['name'] ?? $product->name) . '-' . uniqid(),
                'sku' => $validated['sku'] ?? $product->sku,
                'price' => $validated['price'] ?? $product->price ?? 0,
                'quantity' => $validated['quantity'] ?? $product->quantity ?? null,
                'msrp' => $validated['msrp'] ?? null,
                'cost_price' => $validated['costPrice'] ?? null,
                'sale_price' => $validated['salePrice'] ?? null,
                'brand_id' => $validated['brandId'],
                'category_id' => $validated['categoryIds'][0] ?? null,
                'condition' => $validated['condition'] ?? null,
                'dimensions' => $validated['dimensions'] ?? [],
                'track_inventory' => $validated['trackInventory'] ?? false,
                'current_stock' => $validated['currentStock'] ?? 0,
                'low_stock' => $validated['lowStock'] ?? 0,
                'is_featured' => $validated['isFeatured'] ?? false,
                'show_condition' => $validated['showCondition'] ?? false,
                'sort_order' => $validated['sortOrder'] ?? 0,
                'search_keywords' => $validated['searchKeywords'] ?? null,
                'visible_on_storefront' => $validated['isVisible'] ?? true,
                'custom_fields' => $validated['customFields'] ?? [],
                'purchasability_status' => $validated['purchasabilityStatus'] ?? null,
                'min_purchase_quantity' => $validated['minPurchaseQuantity'] ?? null,
                'max_purchase_quantity' => $validated['maxPurchaseQuantity'] ?? null,
                'related_products_enabled' => $validated['relatedProducts'] ?? false,
                'fixed_shipping_cost' => $validated['fixedShippingCost'] ?? 0,
                'product_type' => $validated['productType'] ?? '',
                'option_set' => $validated['optionSet'] ?? '',
                'option_set_align' => $validated['optionSetAlign'] ?? '',
                'allow_purchase' => $validated['allowPurchase'] ?? true,
                'stop_processing_rules' => $validated['stopProcessingRules'] ?? false,
                'tax_class' => $validated['taxClass'] ?? '',
                'tax_provider_code' => $validated['taxProviderCode'] ?? '',
                'tax_code' => $validated['taxCode'] ?? null,
                'discount_type' => $validated['discountType'] ?? null,
                'bulk_pricing_tiers' => $validated['bulkPricingTiers'] ?? [],

                'object_type' => $validated['objectType'] ?? null,
                'use_product_name' => $validated['useProductName'] ?? false,
                'graph_description' => $validated['graphDescription'] ?? false,
                'use_thumbnail' => $validated['useThumbnail'] ?? false,
                'dont_use' => $validated['dontUse'] ?? false,
                'manage_customs' => $validated['manageCustoms'] ?? false,

                'commodity_description' => $validated['commodityDescription'] ?? null,
                'country_of_origin' => $validated['countryOfOrigin'] ?? null,

                'hs_codes' => $validated['hsCodes'] ?? [],
            ]);

            // Sync many-to-many categories
            $product->categories()->sync($validated['categoryIds']);

            // Remove old images only if new ones are provided
            if (!empty($validated['image'])) {
                foreach ($product->images as $oldImage) {
                    $imagePath = public_path($oldImage->path);
                    if (file_exists($imagePath)) {
                        @unlink($imagePath);
                    }
                    $oldImage->delete();
                }
            }

            // Process new images
            $images = [];

            if (!empty($validated['image'])) {
                foreach ($validated['image'] as $img) {
                    // Handle string input as URL
                    if (is_string($img)) {
                        $img = ['url' => $img];
                    }

                    if (is_array($img)) {
                        $imageEntry = [
                            'type' => null,
                            'value' => null,
                            'is_primary' => $img['isThumbnail'] ?? false,
                            'alt_text' => $img['alt_text'] ?? $validated['name'] ?? null,
                            'description' => $img['description'] ?? null,
                        ];

                        // Handle URL input
                        if (!empty($img['url']) && is_string($img['url'])) {
                            $imageEntry['type'] = 'url';
                            $imageEntry['value'] = $img['url'];
                            $images[] = $imageEntry;
                        }

                        // Handle uploaded file
                        if (
                            isset($img['file']) &&
                            $img['file'] instanceof \Illuminate\Http\UploadedFile
                        ) {
                            $imageEntry['type'] = 'file';
                            $imageEntry['value'] = $img['file'];
                            $images[] = $imageEntry;
                        }
                    }
                }
            }

            foreach ($images as $index => $img) {
                $path = null;

                if ($img['type'] === 'url') {
                    $url = $img['value'];

                    if (!filter_var($url, FILTER_VALIDATE_URL)) {
                        continue;
                    }

                    $parsedUrl = parse_url($url);
                    $host = $parsedUrl['host'] ?? '';

                    // Check if it's a self-hosted image already in product_images/
                    if (Str::contains($url, url('/product_images'))) {
                        $path = str_replace(url('/'), '', $url);
                        $path = ltrim($path, '/');
                    }
                    // If internal copy from same host
                    elseif (Str::contains($host, 'ecom.brokercell.com')) {
                        $relativePath = $parsedUrl['path'] ?? null;
                        $sourcePath = public_path($relativePath);

                        if (file_exists($sourcePath)) {
                            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
                            $imageName = time() . '_' . $index . '.' . $extension;
                            $destinationPath = public_path('product_images/' . $imageName);
                            copy($sourcePath, $destinationPath);
                            $path = 'product_images/' . $imageName;
                        } else {
                            continue;
                        }
                    }
                    // External image (e.g., ubuy URL)
                    else {
                        try {
                            $response = Http::timeout(5)->get($url);
                            if ($response->ok()) {
                                $imageContent = $response->body();

                                $directory = public_path('product_images');
                                if (!file_exists($directory)) {
                                    mkdir($directory, 0755, true);
                                }

                                $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
                                $extension = in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? $extension : 'jpg';

                                $imageName = time() . '_' . $index . '.' . $extension;
                                $filePath = public_path('product_images/' . $imageName);
                                file_put_contents($filePath, $imageContent);
                                $path = 'product_images/' . $imageName;
                            }
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                } elseif ($img['type'] === 'file') {
                    $file = $img['value'];
                    $imageName = time() . '_' . $index . '.' . $file->getClientOriginalExtension();
                    $file->move(public_path('product_images'), $imageName);
                    $path = "product_images/{$imageName}";
                }

                if (!$path) continue;

                ProductImage::create([
                    'store_id' => $storeId,
                    'product_id' => $product->id,
                    'path' => $path,
                    'is_primary' => $img['is_primary'] ?? ($index === 0),
                    'alt_text' => $img['alt_text'] ?? null,
                    'description' => $img['description'] ?? null,
                ]);
            }

            // Update content
            $product->content()->updateOrCreate(
                ['store_id' => $storeId, 'product_id' => $product->id],
                [
                    'description' => $validated['description'] ?? null,
                    'warranty' => $validated['warranty'] ?? null,
                    'availability_text' => $validated['availabilityText'] ?? null,
                    'template_layout' => $validated['templateLayout'] ?? null,
                ]
            );

            // Update identifiers
            $product->identifiers()->updateOrCreate(
                ['store_id' => $storeId, 'product_id' => $product->id],
                [
                    'mpn' => $validated['mpn'] ?? null,
                    'upc' => $validated['upc'] ?? null,
                    'gtin' => $validated['gtin'] ?? null,
                    'bpn' => $validated['bpn'] ?? null,
                ]
            );

            // Update SEO
            $product->seo()->updateOrCreate(
                ['store_id' => $storeId, 'product_id' => $product->id],
                [
                    'page_title' => $validated['pageTitle'] ?? null,
                    'product_url' => $validated['productUrl'] ?? null,
                    'meta_description' => $validated['metaDescription'] ?? null,
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'Product updated successfully.',
                'data' => new ProductResource($product->load([
                    'brand',
                    'categories',
                    'images',
                    'content',
                    'identifiers',
                    'seo'
                ]))
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function updateProduct(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['error' => 'Store not selected or token invalid.'], 400);
            }

            $data = $request->validate([
                'products' => 'required|array|min:1',
                'products.*.id' => 'required',
                'products.*.fields' => 'required|array',
            ]);


            $updatedProducts = [];

            foreach ($data['products'] as $item) {
                $fields = $item['fields'];
                $productIds = is_array($item['id']) ? $item['id'] : [$item['id']];

                foreach ($productIds as $productId) {
                    if (!is_numeric($productId)) {
                        continue;
                    }

                    $product = Product::where('store_id', $storeId)->findOrFail($productId);

                    // ✅ Main product fields
                    $product->fill([
                        'name' => $fields['name'] ?? $product->name,
                        'slug' => isset($fields['name']) ? Str::slug($fields['name']) . '-' . uniqid() : $product->slug,
                        'sku' => $fields['sku'] ?? $product->sku,
                        'brand_id' => $fields['brandId'] ?? $product->brand_id,
                        'category_id' => $fields['categoryIds'] ?? $product->category_id,
                        'condition' => $fields['condition'] ?? $product->condition,
                        'price' => $fields['price'] ?? $product->price,
                        'quantity' => $fields['quantity'] ?? $product->quantity,
                        'msrp' => $fields['msrp'] ?? $product->msrp,
                        'cost_price' => $fields['costPrice'] ?? $product->cost_price,
                        'sale_price' => $fields['salePrice'] ?? $product->sale_price,
                        'dimensions' => $fields['dimensions'] ?? $product->dimensions,
                        'track_inventory' => $fields['trackInventory'] ?? $product->track_inventory,
                        'current_stock' => $fields['currentStock'] ?? $product->current_stock,
                        'low_stock' => $fields['lowStock'] ?? $product->low_stock,
                        'is_featured' => $fields['isFeatured'] ?? $product->is_featured,
                        'show_condition' => $fields['showCondition'] ?? $product->show_condition,
                        'sort_order' => $fields['sortOrder'] ?? $product->sort_order,
                        'search_keywords' => $fields['searchKeywords'] ?? $product->search_keywords,
                        'visible_on_storefront' => $fields['isVisible'] ?? $product->visible_on_storefront,
                        'custom_fields' => $fields['customFields'] ?? $product->custom_fields,
                        'purchasability_status' => $fields['purchasabilityStatus'] ?? $product->purchasability_status,
                        'min_purchase_quantity' => $fields['minPurchaseQuantity'] ?? $product->min_purchase_quantity,
                        'max_purchase_quantity' => $fields['maxPurchaseQuantity'] ?? $product->max_purchase_quantity,
                        'related_products_enabled' => $fields['relatedProducts'] ?? $product->related_products_enabled,
                        'fixed_shipping_cost' => $fields['fixedShippingCost'] ?? $product->fixed_shipping_cost,
                        'product_type' => $fields['productType'] ?? $product->product_type,
                        'option_set' => $fields['optionSet'] ?? $product->option_set,
                        'option_set_align' => $fields['optionSetAlign'] ?? $product->option_set_align,
                        'allow_purchase' => $fields['allowPurchase'] ?? $product->allow_purchase,
                        'stop_processing_rules' => $fields['stopProcessingRules'] ?? $product->stop_processing_rules,
                        'tax_class' => $fields['taxClass'] ?? $product->tax_class,
                        'tax_provider_code' => $fields['taxProviderCode'] ?? $product->tax_provider_code,
                        'tax_code' => $fields['taxCode'] ?? $product->tax_code,
                        'discount_type' => $fields['discountType'] ?? $product->discount_type,
                        'bulk_pricing_tiers' => $fields['bulkPricingTiers'] ?? $product->bulk_pricing_tiers,
                    ])->save();

                    // ✅ Sync Categories
                    if (!empty($fields['categoryIds']) && is_array($fields['categoryIds'])) {
                        $categoryIds = array_map(fn($id) => (int)$id, $fields['categoryIds']);
                        $product->categories()->sync($categoryIds);
                    }
                    // ✅ Update Content (description, warranty, etc.)
                    if (isset($fields['description']) || isset($fields['warranty']) || isset($fields['availabilityText']) || isset($fields['templateLayout'])) {
                        $content = $product->content ?: new ProductContent([
                            'store_id' => $storeId,
                            'product_id' => $product->id,
                        ]);

                        $content->fill([
                            'description' => $fields['description'] ?? $content->description,
                            'warranty' => $fields['warranty'] ?? $content->warranty,
                            'availability_text' => $fields['availabilityText'] ?? $content->availability_text,
                            'template_layout' => $fields['templateLayout'] ?? $content->template_layout,
                        ])->save();
                    }

                    // ✅ Update Identifiers (MPN, UPC, GTIN, BPN)
                    if (isset($fields['mpn']) || isset($fields['upc']) || isset($fields['gtin']) || isset($fields['bpn'])) {
                        $identifiers = $product->identifiers ?: new ProductIdentifier([
                            'store_id' => $storeId,
                            'product_id' => $product->id,
                        ]);

                        $identifiers->fill([
                            'mpn' => $fields['mpn'] ?? $identifiers->mpn,
                            'upc' => $fields['upc'] ?? $identifiers->upc,
                            'gtin' => $fields['gtin'] ?? $identifiers->gtin,
                            'bpn' => $fields['bpn'] ?? $identifiers->bpn,
                        ])->save();
                    }

                    // ✅ Update SEO (meta, page title, product URL)
                    if (isset($fields['pageTitle']) || isset($fields['productUrl']) || isset($fields['metaDescription'])) {
                        $seo = $product->seo ?: new ProductSeo([
                            'store_id' => $storeId,
                            'product_id' => $product->id,
                        ]);

                        $seo->fill([
                            'page_title' => $fields['pageTitle'] ?? $seo->page_title,
                            'product_url' => $fields['productUrl'] ?? $seo->product_url,
                            'meta_description' => $fields['metaDescription'] ?? $seo->meta_description,
                        ])->save();
                    }

                    if (
                        array_key_exists('image', $fields) ||
                        array_key_exists('image_url', $fields)
                    ) {

                        $existingPaths = $product->images->pluck('path')->toArray();
                        $keepPaths = [];
                        $hasPrimary = false;

                        // ---------------- FILES (WIN OVER URL) ----------------
                        if (!empty($fields['image']) && is_array($fields['image'])) {

                            foreach ($fields['image'] as $img) {

                                $path = null;

                                // EXISTING IMAGE (KEEP)
                                if (!empty($img['path']) && in_array($img['path'], $existingPaths)) {
                                    $path = $img['path'];
                                }

                                // NEW FILE
                                elseif (
                                    isset($img['file']) &&
                                    $img['file'] instanceof \Illuminate\Http\UploadedFile
                                ) {

                                    $name = Str::slug(
                                        pathinfo($img['file']->getClientOriginalName(), PATHINFO_FILENAME)
                                    );
                                    $ext = $img['file']->getClientOriginalExtension();
                                    $fileName = $name . '.' . $ext;

                                    $path = Storage::disk('s3')->putFileAs(
                                        "product_images/{$product->id}",
                                        $img['file'],
                                        $fileName
                                    );
                                }

                                if (!$path) continue;

                                $keepPaths[] = $path;

                                $product->images()->firstOrCreate(
                                    ['path' => $path],
                                    [
                                        'store_id'   => $storeId,
                                        'is_primary' => $hasPrimary ? 0 : 1,
                                        'alt_text'   => $img['alt_text'] ?? $product->name,
                                        'description' => $img['description'] ?? null,
                                    ]
                                );

                                $hasPrimary = true;
                            }
                        }

                        // ---------------- URLS (ONLY IF NO FILES) ----------------
                        elseif (!empty($fields['image_url']) && is_array($fields['image_url'])) {

                            foreach ($fields['image_url'] as $url) {

                                $path = null;

                                // EXISTING IMAGE (KEEP)
                                $parsedPath = ltrim(parse_url($url, PHP_URL_PATH), '/');
                                if (in_array($parsedPath, $existingPaths)) {
                                    $path = $parsedPath;
                                }

                                // NEW URL → DOWNLOAD → UPLOAD
                                else {
                                    try {
                                        $response = Http::timeout(8)->get($url);
                                        if (!$response->ok()) continue;

                                        $name = Str::slug(
                                            pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME)
                                        );
                                        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';

                                        $fileName = $name . '.' . $ext;
                                        $path = "product_images/{$product->id}/{$fileName}";

                                        Storage::disk('s3')->put($path, $response->body());
                                    } catch (\Exception $e) {
                                        continue;
                                    }
                                }

                                if (!$path) continue;

                                $keepPaths[] = $path;

                                $product->images()->firstOrCreate(
                                    ['path' => $path],
                                    [
                                        'store_id'   => $storeId,
                                        'is_primary' => $hasPrimary ? 0 : 1,
                                        'alt_text'   => $product->name,
                                    ]
                                );

                                $hasPrimary = true;
                            }
                        }

                        // ---------------- DELETE REMOVED IMAGES ----------------
                        $toDelete = array_diff($existingPaths, $keepPaths);

                        foreach ($toDelete as $oldPath) {
                            Storage::disk('s3')->delete($oldPath);
                            $product->images()->where('path', $oldPath)->delete();
                        }
                    }

                    // ✅ Add to response
                    $updatedProducts[] = new ProductResource($product->load([
                        'categories',
                        'content',
                        'seo',
                        'identifiers',
                        'images'
                    ]));
                }

                return response()->json([
                    'status' => true,
                    'message' => 'Products updated successfully.',
                    'data' => $updatedProducts,
                ]);
            }
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function searchProduct(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['error' => 'Store not selected or token invalid.'], 400);
            }

            $request->validate([
                'query' => 'required|string|min:1'
            ]);

            $queryStr = $request->input('query');
            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 50);

            // Cache key with query + page
            $cacheKey = "product_search_store_{$storeId}_query_" . md5($queryStr) . "_page_{$page}_size_{$pageSize}";

            // Cache results for 5 minutes (300 seconds)
           $products = Cache::remember($cacheKey, 30, function () use ($queryStr, $storeId, $page, $pageSize) {
                return Product::where('store_id', $storeId)
                    ->where('visible_on_storefront', 1)
                    ->where(function ($q) use ($queryStr) {
                        $q->where('name', 'like', "%$queryStr%")
                            ->orWhere('sku', 'like', "%$queryStr%")
                            ->orWhere('slug', 'like', "%$queryStr%")
                            ->orWhere('price', 'like', "%$queryStr%")
                            ->orWhere('search_keywords', 'like', "%$queryStr%")
                            ->orWhereHas('categories', fn($q2) => $q2->where('name', 'like', "%$queryStr%"))
                            ->orWhereHas('brand', fn($q3) => $q3->where('name', 'like', "%$queryStr%"));
                    })
                    ->select('id', 'name', 'sku', 'price', 'slug', 'brand_id') // Only essential columns
                    ->with([
                        'categories:id,name',  // Only id and name
                        'brand:id,name,logo'   // Only id, name, logo
                    ])
                    ->orderByRaw("
                    CASE
                        WHEN name = ? THEN 0
                        WHEN name LIKE ? THEN 1
                        WHEN name LIKE ? THEN 2
                        ELSE 3
                    END
                ", [$queryStr, "$queryStr%", "%$queryStr%"])
                    ->orderBy('name', 'asc')
                    ->paginate($pageSize, ['*'], 'page', $page);
            });

            // Background mein search count update (non-blocking)
            if ($products->isNotEmpty()) {
                $productIds = $products->pluck('id')->toArray();

                // Bulk update in single query (fast)
                dispatch(function () use ($productIds, $storeId) {
                    if (empty($productIds)) return;

                    $values = implode(',', array_map(
                        fn($id) => "($id, $storeId, 1, NOW(), NOW())",
                        $productIds
                    ));

                    \DB::statement("
                    INSERT INTO product_searches (product_id, store_id, search_count, created_at, updated_at)
                    VALUES {$values}
                    ON DUPLICATE KEY UPDATE 
                        search_count = search_count + 1,
                        updated_at = NOW()
                ");
                })->afterResponse();
            }

            return response()->json([
                'status'  => true,
                'message' => 'Products retrieved successfully.',
                'data'    => ProductResource::collection($products)
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function newProducts()
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['error' => 'Store not selected.'], 400);
            }

            $products = Product::where('store_id', $storeId)
                ->latest()
                ->with(['categories', 'brand'])
                ->take(10)
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'New products retrieved successfully.',
                'data' => ProductResource::collection($products)
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to load new products.'], 500);
        }
    }


    public function featuredProducts()
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['error' => 'Store not selected.'], 400);
            }

            $products = Product::where('store_id', $storeId)
                ->where('visible_on_storefront', 1)
                ->where('is_featured', true)
                ->with([
                    'categories',
                    'brand',
                    'images',
                    'reviews.user',
                    'content',
                    'identifiers',
                    'seo'
                ])
                ->latest()
                ->take(10)
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Featured products retrieved successfully.',
                'data' => ProductResource::collection($products)
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to load featured products.'], 500);
        }
    }

    public function productsByBrand(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected.'
                ], 400);
            }

            $request->validate([
                'brand_id' => 'required|integer'
            ]);

            $brandId = $request->brand_id;

            $cacheKey = "brand_products_full_store_{$storeId}_brand_{$brandId}";

            $products = Cache::remember($cacheKey, 300, function () use ($storeId, $brandId) {

                // 🔥 STEP 1: PRODUCTS (LIGHT)
                $products = Product::where('store_id', $storeId)
                    ->where('brand_id', $brandId)
                    ->select('id') // ONLY ID FIRST
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get();

                $productIds = $products->pluck('id');

                // 🔥 STEP 2: LOAD ALL RELATIONS OPTIMIZED
                return Product::whereIn('id', $productIds)
                    ->with([
                        'categories:id,name,parent_id',
                        'categories.parent:id,name,parent_id',

                        'brand:id,name,logo',

                        'images:id,product_id,path,is_primary',

                        'reviews' => function ($q) {
                            $q->select('id', 'product_id', 'user_id', 'rating', 'comment', 'created_at')
                                ->latest()
                                ->limit(10);
                        },
                        'reviews.user:id,name',

                        'content:product_id,description,warranty',

                        'identifiers:product_id,mpn,upc,gtin',

                        'seo:product_id,page_title,meta_description'
                    ])
                    ->get();
            });

            return response()->json([
                'status' => true,
                'message' => 'Brand products retrieved successfully.',
                'data' => ProductResource::collection($products)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => 'Something went wrong',
                'debug' => $e->getMessage()
            ], 500);
        }
    }


    // public function popularProducts(Request $request)
    // {
    //     $storeId = current_store_id();
    //     if (!$storeId) {
    //         return response()->json(['status' => false, 'message' => 'Store not selected.'], 400);
    //     }

    //     $limit = (int) $request->input('limit', 10);

    //     $popularProducts = Product::where('store_id', $storeId)
    //         ->whereHas('searchStats') 
    //        ->with([
    //             'categories',
    //             'brand',
    //             'images',
    //             'reviews.user',
    //             'content',
    //             'identifiers',
    //             'seo'
    //         ])
    //         ->withCount(['searchStats as search_count' => function($q) use ($storeId){
    //             $q->where('store_id', $storeId);
    //         }])
    //         ->orderByDesc('search_count')
    //         ->take($limit)
    //         ->get();

    //     return response()->json([
    //         'status' => true,
    //         'message' => 'Popular products retrieved.',
    //         'data' => ProductResource::collection($popularProducts)
    //     ]);
    // }


    // public function popularProducts(Request $request)
    // {
    //     $storeId = current_store_id();
    //     if (!$storeId) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Store not selected.'
    //         ], 400);
    //     }

    //     $limit = (int) $request->input('limit', 10);

    //     $products = Product::where('store_id', $storeId)
    //         ->with([
    //             'categories',
    //             'brand',
    //             'images',
    //             'reviews.user',
    //             'content',
    //             'identifiers',
    //             'seo'
    //         ])
    //         ->withCount(['searchStats as search_count' => function ($q) use ($storeId) {
    //             $q->where('store_id', $storeId);
    //         }])
    //         ->orderByDesc('search_count')   // Popular products first
    //         ->orderByDesc('created_at')     // If search_count same, newest first
    //         ->take($limit)
    //         ->get();

    //     return response()->json([
    //         'status' => true,
    //         'message' => 'Popular and recent products retrieved.',
    //         'data' => ProductListResource::collection($products)
    //     ]);
    // }

    // last code
    // public function popularProducts(Request $request)
    // {
    //     $storeId = current_store_id();
    //     if (!$storeId) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Store not selected.'
    //         ], 400);
    //     }

    //     $limit = (int) $request->input('limit', 10);

    //     // STEP 1: Get top product IDs only (FAST)
    //     $productIds = Product::where('store_id', $storeId)
    //         ->withCount(['searchStats as search_count' => function ($q) use ($storeId) {
    //             $q->where('store_id', $storeId);
    //         }])
    //         ->orderByDesc('search_count')
    //         ->orderByDesc('created_at')
    //         ->limit($limit)
    //         ->pluck('id');

    //     // STEP 2: Load only required fields + relationships
    //     $products = Product::whereIn('id', $productIds)
    //         ->with([
    //             'brand:id,name',
    //             'categories:id,name',
    //             'images:id,product_id,path,is_primary'
    //         ])
    //         ->get();


    //     return response()->json([
    //         'status' => true,
    //         'message' => 'Popular and recent products retrieved.',
    //         'data' => PopularProductResource::collection($products)
    //     ]);
    // }

    public function popularProducts(Request $request)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected.'
            ], 400);
        }

        $limit = (int) $request->input('limit', 10);

        // STEP 1: Get popular product IDs from product_searches (FAST)
        $productIds = DB::table('product_searches')
            ->where('store_id', $storeId)
            ->orderByDesc('search_count')
            ->limit($limit)
            ->pluck('product_id');

        // STEP 2: Load products (lightweight)
        $products = Product::select('id', 'name', 'sku', 'price', 'brand_id')
            ->whereIn('id', $productIds)
            ->with([
                'brand:id,name',
                'categories:id,name',
                'images' => function ($q) {
                    $q->select('id', 'product_id', 'path')
                        ->where('is_primary', 1);
                }
            ])
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Popular products retrieved.',
            'data' => PopularProductResource::collection($products)
        ]);
    }


    //last 7 days order products get
    public function lastWeekOrderedProducts()
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected'
                ], 400);
            }

            // 🔹 Last 7 days orders
            $orderIds = Order::where('store_id', $storeId)
                ->whereBetween('created_at', [
                    Carbon::now()->subWeek(),
                    Carbon::now()
                ])
                ->pluck('id');

            if ($orderIds->isEmpty()) {
                return response()->json([
                    'status' => true,
                    'message' => 'No orders found for last week',
                    'data' => []
                ]);
            }

            // 🔹 Order items → product IDs
            $productIds = OrderItem::whereIn('order_id', $orderIds)
                ->pluck('product_id')
                ->unique();

            // 🔹 Products (same as featuredProducts)
            $products = Product::where('store_id', $storeId)
                ->whereIn('id', $productIds)
                ->with([
                    'categories',
                    'brand',
                    'images',
                    'reviews.user',
                    'content',
                    'identifiers',
                    'seo'
                ])
                ->latest()
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Last week ordered products retrieved successfully',
                'data' => ProductResource::collection($products)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //last 7 days products get

    // public function lastWeekProducts()
    // {
    //     try {
    //         $storeId = current_store_id();

    //         if (!$storeId) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Store not selected'
    //             ], 400);
    //         }

    //         $cacheKey = "last_week_products_store_{$storeId}";

    //         $products = Cache::remember($cacheKey, 300, function () use ($storeId) {

    //             // 🔥 STEP 1: FAST PRODUCT IDS
    //             $productIds = Product::where('store_id', $storeId)
    //                 ->where('created_at', '>=', Carbon::now()->subWeek())
    //                 ->orderByDesc('id')
    //                 ->pluck('id');

    //             if ($productIds->isEmpty()) {
    //                 return collect();
    //             }

    //             // 🔥 STEP 2: LOAD FULL DATA (OPTIMIZED)
    //             return Product::whereIn('id', $productIds)
    //                 ->with([
    //                     // categories (LIMIT recursion)
    //                     'categories:id,name,parent_id',
    //                     'categories.parent:id,name,parent_id',

    //                     // brand
    //                     'brand:id,name,logo',

    //                     // images
    //                     'images:id,product_id,path,is_primary',

    //                     // reviews (LIMITED)
    //                     'reviews' => function ($q) {
    //                         $q->select(
    //                             'id',
    //                             'product_id',
    //                             'user_id',
    //                             'rating',
    //                             'comment',
    //                             'created_at'
    //                         )
    //                             ->latest()
    //                             ->limit(10);
    //                     },
    //                     'reviews.user:id,name',

    //                     // content
    //                     'content:product_id,description,warranty',

    //                     // identifiers
    //                     'identifiers:product_id,mpn,upc,gtin',

    //                     // seo
    //                     'seo:product_id,page_title,meta_description'
    //                 ])
    //                 ->orderByDesc('id')
    //                 ->get();
    //         });

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Last week products retrieved successfully',
    //             'data' => ProductResource::collection($products)
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Something went wrong',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }


    public function lastWeekProducts()
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected'
                ], 400);
            }

            $page    = request()->get('page', 1);
            $perPage = request()->get('per_page', 20); // default 20, max 100
            $perPage = min((int) $perPage, 100);

            $cacheKey = "last_week_products_store_{$storeId}_page_{$page}_per_{$perPage}";

            $products = Cache::remember($cacheKey, 300, function () use ($storeId, $perPage) {
                return Product::where('store_id', $storeId)
                    ->where('created_at', '>=', Carbon::now()->subWeek())
                    ->with([
                        // categories (LIMIT recursion)
                        'categories:id,name,parent_id',
                        'categories.parent:id,name,parent_id',
                        // brand
                        'brand:id,name,logo',
                        // images
                        'images:id,product_id,path,is_primary',
                        // reviews (LIMITED)
                        'reviews' => function ($q) {
                            $q->select(
                                'id',
                                'product_id',
                                'user_id',
                                'rating',
                                'comment',
                                'created_at'
                            )
                                ->latest()
                                ->limit(10);
                        },
                        'reviews.user:id,name',
                        // content
                        'content:product_id,description,warranty',
                        // identifiers
                        'identifiers:product_id,mpn,upc,gtin',
                        // seo
                        'seo:product_id,page_title,meta_description'
                    ])
                    ->orderByDesc('id')
                    ->paginate($perPage);
            });

            return response()->json([
                'status'  => true,
                'message' => 'Last week products retrieved successfully',
                'data'    => ProductResource::collection($products->items()),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'per_page'     => $products->perPage(),
                    'total'        => $products->total(),
                    'last_page'    => $products->lastPage(),
                    'has_more'     => $products->hasMorePages(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    public function searchAdvanced(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['error' => 'Store not selected.'], 400);
            }

            $query = Product::with(['brand', 'categories', 'images'])
                ->where('store_id', $storeId);

            // 🔍 Search by name, SKU, etc.
            if ($request->filled('searchKeywords')) {
                $keywords = $request->searchKeywords;
                $query->where(function ($q) use ($keywords) {
                    $q->where('name', 'like', "%{$keywords}%")
                        ->orWhere('sku', 'like', "%{$keywords}%")
                        ->orWhere('search_keywords', 'like', "%{$keywords}%");
                });
            }

            // 🔍 Filter by brand
            if ($request->filled('brandId')) {
                $query->where('brand_id', $request->brandId);
            }

            // 🔍 Filter by categories (multiple IDs allowed)
            if ($request->filled('categoryIds')) {
                $categoryIds = is_array($request->categoryIds)
                    ? $request->categoryIds
                    : [$request->categoryIds];

                $query->whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                });
            }

            // 🔍 Filter by price range
            if ($request->filled('priceMin')) {
                $query->where('price', '>=', $request->priceMin);
            }

            if ($request->filled('priceMax')) {
                $query->where('price', '<=', $request->priceMax);
            }

            // 🔍 Filter by inventory
            if ($request->filled('inventoryMin')) {
                $query->where('current_stock', '>=', $request->inventoryMin);
            }

            if ($request->filled('inventoryMax')) {
                $query->where('current_stock', '<=', $request->inventoryMax);
            }

            // 🔍 Filter by visibility
            if ($request->filled('isVisible')) {
                $query->where('visible_on_storefront', $request->boolean('isVisible'));
            }

            // 🔍 Filter by featured
            if ($request->filled('isFeatured')) {
                $query->where('is_featured', $request->boolean('isFeatured'));
            }

            // 🔍 Optional: freeShipping (if such a column exists in DB)
            if ($request->filled('freeShipping')) {
                $query->where('free_shipping', $request->boolean('freeShipping'));
            }

            // 🔁 Sorting
            $sortBy = $request->get('sortBy', 'id');
            $sortOrder = $request->get('sortOrder', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // ✅ Pagination
            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 50);
            $products = $query->paginate($pageSize, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'message' => 'Advanced product search result.',
                'data' => ProductResource::collection($products),
                'pagination' => [
                    'total' => $products->total(),
                    'page' => $products->currentPage(),
                    'pageSize' => $products->perPage(),
                    'lastPage' => $products->lastPage()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    private function getCategoryCounts($storeId)
    {
        $categories = Category::with([
            'childrenRecursive',
            'products' => fn($q) => $q->where('store_id', $storeId)
        ])
            ->whereNull('parent_id')
            ->get();

        return $categories->map(function ($category) use ($storeId) {
            return $this->formatCategoryWithCounts($category, $storeId);
        });
    }

    private function formatCategoryWithCounts($category, $storeId)
    {
        $totalProducts = $category->products->count();

        foreach ($category->childrenRecursive as $child) {
            $totalProducts += $this->countProductsRecursive($child, $storeId);
        }

        return [
            'id' => $category->id,
            'name' => $category->name,
            'total_products' => $totalProducts,
            'subcategories' => $category->childrenRecursive->map(function ($sub) use ($storeId) {
                return $this->formatCategoryWithCounts($sub, $storeId);
            })
        ];
    }

    private function countProductsRecursive($category, $storeId)
    {
        $count = $category->products->where('store_id', $storeId)->count();

        foreach ($category->childrenRecursive as $child) {
            $count += $this->countProductsRecursive($child, $storeId);
        }

        return $count;
    }


    public function destroyProduct(Request $request, $id = null)
    {
        try {
            $storeId = current_store_id(); // ✅ Real dynamic store context

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or session/token is missing.'
                ], 400);
            }

            // 🔹 SINGLE DELETE
            if ($id) {
                $product = Product::where('store_id', $storeId)->find($id);

                if (!$product) {
                    return response()->json([
                        'status' => false,
                        'message' => "Product ID $id not found in store_id $storeId."
                    ], 404);
                }

                $product->delete();

                return response()->json([
                    'status' => true,
                    'message' => 'Product deleted successfully.',
                    'deletedIds' => [$id]
                ]);
            }

            // 🔹 BULK DELETE
            $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer'
            ]);

            $deleted = [];
            $failed = [];

            foreach ($request->ids as $prodId) {
                $product = Product::where('store_id', $storeId)->find($prodId);

                if (!$product) {
                    $failed[] = [
                        'id' => $prodId,
                        'error' => "Product ID $prodId not found in store_id $storeId."
                    ];
                    continue;
                }

                try {
                    $product->delete();
                    $deleted[] = $prodId;
                } catch (Exception $e) {
                    $failed[] = [
                        'id' => $prodId,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Delete process completed.',
                'deletedIds' => $deleted,
                'failed' => $failed
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unexpected error: ' . $e->getMessage()
            ], 500);
        }
    }


    //   public function importCSV(Request $request)
    // {
    //     $request->validate([
    //         'file' => 'required|file|mimes:xlsx,xls,csv',
    //         'ignored_fields' => 'nullable|array', // optional array from frontend
    //         'ignored_fields.*' => 'string',
    //     ]);

    //     $file = $request->file('file');
    //     $ignoredFields = $request->input('ignored_fields', []); // dynamically from frontend

    //     $storeId = current_store_id(); // your helper to get current store
    //     if (!$storeId) {
    //         return response()->json(['error' => 'Store not selected.'], 400);
    //     }

    //     // Save the uploaded file permanently
    //     $storedFilePath = $file->store('imports'); // e.g., imports/file.xlsx

    //     // Unique progress key
    //     $progressKey = 'import_progress_' . Str::uuid();

    //     // Initialize progress tracking
    //     Cache::put($progressKey, [
    //         'current' => 0,
    //         'status'  => 'queued',
    //     ], 7200); // 2 hours

    //     Cache::put($progressKey . "_cancel", false, 7200);
    //     Cache::put($progressKey . "_count", 0, 7200);

    //     // Queue the import and pass ignored fields
    //     Excel::queueImport(
    //         new ProductImport(
    //             $storeId,
    //             $progressKey,
    //             storage_path('app/' . $storedFilePath),
    //             $ignoredFields // <- dynamic ignored fields
    //         ),
    //         $storedFilePath
    //     );

    //     return response()->json([
    //         'success' => true,
    //         'progress_key' => $progressKey
    //     ]);
    // }

    public function importCSV(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'ignored_fields' => 'nullable|array', // optional array from frontend
            'ignored_fields.*' => 'string',
        ]);

        $file = $request->file('file');
        $ignoredFields = $request->input('ignored_fields', []);

        $storeId = current_store_id(); // your helper to get current store
        if (!$storeId) {
            return response()->json(['error' => 'Store not selected.'], 400);
        }

        // Save the uploaded file permanently
        $storedFilePath = $file->store('imports'); // e.g., imports/file.xlsx
        $fullPath = storage_path('app/' . $storedFilePath);

        // Unique progress key
        $progressKey = 'import_progress_' . Str::uuid();

        // Initialize progress tracking
        Cache::put($progressKey, [
            'current' => 0,
            'status'  => 'queued',
        ], 7200); // 2 hours

        Cache::put($progressKey . "_cancel", false, 7200);
        Cache::put($progressKey . "_count", 0, 7200);

        // --- Calculate total rows once (before queuing) ---
        $totalRows = 0;
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'xlsx') {
            if (class_exists(\ZipArchive::class)) {
                $zip = new \ZipArchive;
                if ($zip->open($fullPath) === true) {
                    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
                    if ($xml !== false) {
                        preg_match_all('/<row r="(\d+)"/', $xml, $matches);
                        $totalRows = count($matches[1]) - 1; // subtract header row
                    }
                }
            }
        } elseif ($extension === 'csv' || $extension === 'xls') {
            // For CSV/XLS, use a lightweight approach
            $fileHandle = fopen($fullPath, 'r');
            if ($fileHandle) {
                $totalRows = 0;
                while (($line = fgetcsv($fileHandle)) !== false) {
                    $totalRows++;
                }
                $totalRows = max(0, $totalRows - 1); // subtract header
                fclose($fileHandle);
            }
        }

        Cache::put($progressKey . "_total", $totalRows, 7200);

        // Queue the import and pass ignored fields
        Excel::queueImport(
            new ProductImport(
                $storeId,
                $progressKey,
                $fullPath,
                $ignoredFields
            ),
            $storedFilePath
        )->onQueue('imports');

        // $ignoredJson = json_encode($ignoredFields);

        // $command = sprintf(
        //     'php %s import:products %d %s %s %s > /dev/null 2>&1 &',
        //     base_path('artisan'),
        //     $storeId,
        //     escapeshellarg($progressKey),
        //     escapeshellarg($fullPath),
        //     escapeshellarg($ignoredJson)
        // );

        // exec($command);

        return response()->json([
            'success' => true,
            'progress_key' => $progressKey
        ]);
    }

    /**
     * Get the current import progress
     */
    public function importProgress($progressKey)
    {
        $progress = Cache::get($progressKey, [
            'current' => 0,
            'status' => 'unknown',
        ]);

        // Add total from cache (calculated in background)
        $progress['total'] = Cache::get($progressKey . "_total", 0);

        return response()->json($progress);
    }

    /**
     * Cancel an ongoing import
     */
    public function cancelImport(Request $request)
    {
        $progressKey = $request->input('progress_key');

        if (!$progressKey) {
            return response()->json(['error' => 'Progress key is required.'], 400);
        }

        // Set cancel flag
        Cache::put($progressKey . "_cancel", true, 7200);

        return response()->json([
            'status' => 'cancel_requested'
        ]);
    }





    /**
     * Parse custom fields for ProductImport
     */
    private function parseCustomFields($fieldString)
    {
        if (!$fieldString) return [];

        // Try JSON first
        $decoded = json_decode($fieldString, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return collect($decoded)->mapWithKeys(function ($value, $key) {
                return [
                    trim(str_replace('"', '', $key)) => trim(str_replace('"', '', $value))
                ];
            })->toArray();
        }

        // Fallback to key=value;key2=value2 format
        $fields = [];
        $pairs = explode(';', $fieldString);
        foreach ($pairs as $pair) {
            if (strpos($pair, '=') !== false) {
                [$key, $value] = explode('=', trim($pair), 2);
                $fields[trim($key)] = trim($value);
            }
        }
        return $fields;
    }
    public function exportCSV()
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $count = Product::where('store_id', $storeId)->count();
            if ($count === 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'No products found to export.'
                ], 404);
            }

            // ✅ Increase limits for large exports
            ini_set('memory_limit', '512M');
            set_time_limit(300); // 5 minutes

            $fileName = 'products_export_' . now()->format('Ymd_His') . '.xlsx';

            return Excel::download(new ProductExport($storeId), $fileName);
        } catch (\Throwable $e) {
            \Log::error('Export CSV Error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'status'  => false,
                'message' => 'Export failed: ' . $e->getMessage(),
                'line'    => $e->getLine(),
                'file'    => $e->getFile(),
            ], 500);
        }
    }
    public function destroyProductCategories(Request $request, $id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['status' => false, 'message' => 'Store not selected or token invalid.'], 400);
            }

            $request->validate([
                'categoryIds'   => 'required|array|min:1',
                'categoryIds.*' => 'integer'
            ]);

            $product = Product::where('store_id', $storeId)
                ->with('categories:id')
                ->findOrFail($id);

            $attachedIds = $product->categories->pluck('id')->toArray();

            // Only detach categories that are attached and belong to same store
            $validIds = Category::where('store_id', $storeId)
                ->whereIn('id', $request->categoryIds)
                ->pluck('id')
                ->toArray();

            $toDetach = array_values(array_intersect($attachedIds, $validIds));

            if (empty($toDetach)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'No matching categories to detach.'
                ], 422);
            }

            // ✅ Allow removing even the last category (no remaining-count check)
            $product->categories()->detach($toDetach);

            // If primary category removed, set a new one or null
            if (!empty($product->category_id) && in_array((int)$product->category_id, $toDetach, true)) {
                $newPrimary = $product->categories()->pluck('categories.id')->first(); // could be null now
                $product->update(['category_id' => $newPrimary]); // make sure products.category_id is nullable
            }

            return response()->json([
                'status'      => true,
                'message'     => 'Categories detached from product.',
                'deletedIds' => $toDetach,
                'data'        => new ProductResource($product->fresh(['categories', 'brand', 'images', 'content', 'seo']))
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Product not found.'], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
