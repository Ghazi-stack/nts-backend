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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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
            // Base query with full eager loading
            $query = Product::with(['brand', 'categories', 'images', 'seo', 'content'])
                ->where('store_id', current_store_id());

            // Filters
            if ($request->has('isFeatured')) {
                $query->where('is_featured', $request->boolean('isFeatured'));
            }

            if ($request->has('isVisible')) {
                $query->where('visible_on_storefront', $request->boolean('isVisible'));
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

            // Sorting
            if ($request->has('sortBy')) {
                $query->orderBy($request->sortBy, $request->get('sortOrder', 'asc'));
            } else {
                $query->orderBy('id', 'desc');
            }

            // Pagination
            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 20);

            $products = $query->paginate($pageSize, ['*'], 'page', $page);

            // Return JSON response
            return response()->json([
                'status' => true,
                'message' => 'Product list fetched.',
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



    public function show($id)
    {
        try {
            $product = Product::with(['brand', 'categories', 'images','content','seo'])
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
        try {
            $storeId = current_store_id();
        
            // Logging for debugging
            Log::info('🔍 Sku requested: ' . $sku);
            Log::info('🏬 Store ID: ' . $storeId);
        
            // Main query
            $product = Product::with([
                'brand',
                'categories.parent.parent.parent.parent',
                'images',
                'content',
                'identifiers',
                'seo',
                'reviews.user'
            ])
            ->where('sku', $sku)
            ->where('store_id', $storeId)
            ->first();
            
            // Fallback using LIKE if exact match fails
            if (!$product) {
                Log::warning("⚠️ Exact sku match failed, trying LIKE fallback...");
                $product = Product::with([
                    'brand',
                    'categories.parent.parent.parent.parent',
                    'images',
                    'content',
                    'identifiers',
                    'seo',
                    'reviews.user'
                ])
                ->where('sku', 'like', "%$sku%")
                ->where('store_id', $storeId)
                ->first();
            }
        
            // Still not found? Return 404
            if (!$product) {
                return response()->json([
                    'status' => false,
                    'error' => 'Product not found.',
                    'sku' => $sku,
                    'store_id' => $storeId
                ], 404);
            }
        
            // Return product
            return response()->json([
                'status' => true,
                'message' => 'Product Retrieved',
                'data' => new ProductResource($product)
            ]);
        
        } catch (\Exception $e) {
            Log::error('❌ Exception in showProduct(): ' . $e->getMessage());
        
            return response()->json([
                'status' => false,
                'error' => 'Failed to retrieve product.',
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
                'minPurchaseQuantity' => 'nullable|integer|min:1',
                'maxPurchaseQuantity' => 'nullable|integer|min:1',
                'relatedProducts' => 'nullable|boolean',
                'fixedShippingCost' => 'nullable|numeric',
                'productType' => 'nullable|string',
                'optionSet' => 'nullable|string',
                'optionSetAlign' => 'nullable|string',
                'allowPurchase' => 'nullable|boolean',
                'stopProcessingRules' => 'nullable|boolean',
                'taxClass' => 'nullable|string',
                'taxProviderCode' => 'nullable|string',
            ]);

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
            ]);

            $product->categories()->sync($validated['categoryIds']);
            // ✅ Handle both uploaded files and external URLs
            $images = [];

            if (!empty($validated['image'])) {
                foreach ($validated['image'] as $img) {
                    if (is_array($img)) {
                        $imageEntry = [
                            'type' => null,
                            'value' => null,
                            'is_primary' => $img['isThumbnail'] ?? false,
                            'alt_text' => $img['alt_text'] ?? null,
                            'description' => $img['description'] ?? null,
                        ];
                    
                        if (!empty($img['url']) && is_string($img['url'])) {
                            $imageEntry['type'] = 'url';
                            $imageEntry['value'] = $img['url'];
                            $images[] = $imageEntry;
                        }
                    
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
                    if (!filter_var($img['value'], FILTER_VALIDATE_URL)) {
                        continue;
                    }
                
                    try {
                        $response = Http::timeout(5)->get($img['value']);
                    
                        if ($response->ok()) {
                            $imageContent = $response->body();
                            $extension = pathinfo(parse_url($img['value'], PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                            $imageName = time() . '_' . $index . '.' . $extension;
                        
                            $filePath = public_path('product_images/' . $imageName);
                            file_put_contents($filePath, $imageContent);
                        
                            $path = 'product_images/' . $imageName;
                        }
                    } catch (\Exception $e) {
                        continue;
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
                    'is_primary' => filter_var($img['is_primary'] ?? ($index === 0), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                    'alt_text' => $img['alt_text'] ?? $validated['name'] ?? null,
                    'description' => $img['description'] ?? null,
                ]);
            } 

            // ✅ Add Content
            ProductContent::create([
                'store_id' => $storeId,
                'product_id' => $product->id,
                'description' => $validated['description'] ?? null,
                'warranty' => $validated['warranty'] ?? null,
                'availability_text' => $validated['availabilityText'] ?? null,
                'template_layout' => $validated['templateLayout'] ?? null,
            ]);

            // ✅ Add Identifiers
            ProductIdentifier::create([
                'store_id' => $storeId,
                'product_id' => $product->id,
                'mpn' => $validated['mpn'] ?? null,
                'upc' => $validated['upc'] ?? null,
                'gtin' => $validated['gtin'] ?? null,
                'bpn' => $validated['bpn'] ?? null,
            ]);

            // ✅ Add SEO
            ProductSeo::create([
                'store_id' => $storeId,
                'product_id' => $product->id,
                'page_title' => $validated['pageTitle'] ?? null,
                'product_url' => $validated['productUrl'] ?? null,
                'meta_description' => $validated['metaDescription'] ?? null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Product created successfully.',
                'data' => new ProductResource($product->load([
                    'brand',
                    'categories',
                    'images',
                    'content',
                    'identifiers',
                    'seo'
                ]))
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (Exception $e) {
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
                'sku' => 'nullable|string|unique:products,sku,' . $product->id,
                'brandId' => 'required|exists:brands,id',
                'categoryIds' => 'required|array|min:1',
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
                'minPurchaseQuantity' => 'nullable|integer|min:1',
                'maxPurchaseQuantity' => 'nullable|integer|min:1',
                'relatedProducts' => 'nullable|boolean',
                'fixedShippingCost' => 'nullable|numeric',
                'productType' => 'nullable|string',
                'optionSet' => 'nullable|string',
                'optionSetAlign' => 'nullable|string',
                'allowPurchase' => 'nullable|boolean',
                'stopProcessingRules' => 'nullable|boolean',
                'taxClass' => 'nullable|string',
                'taxProviderCode' => 'nullable|string',
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
                'category_id' => $validated['categoryIds'][0] ?? null, // primary category
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
                        $path = str_replace(url('/'), '', $url); // e.g. product_images/filename.jpg
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
                'products.*.id' => 'required', // Accept both integer and array
                'products.*.fields' => 'required|array',
            ]);


            $updatedProducts = [];

            // foreach ($data['products'] as $item) {
            //     $product = Product::where('store_id', $storeId)->findOrFail($item['id']);
            //     $fields = $item['fields'];
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
                ])->save();

                // ✅ Sync Categories
                if (!empty($fields['categoryIds']) && is_array($fields['categoryIds'])) {
                    $product->categories()->sync($fields['categoryIds']);
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

            // ✅ Update/Add Images
        if (!empty($fields['image'])) {
            // 🗑️ Delete old images
            ProductImage::where('product_id', $product->id)->delete();
        
            $imageInputs = is_array($fields['image']) ? $fields['image'] : [$fields['image']];
            $images = [];
        
            foreach ($imageInputs as $img) {
                if (is_array($img)) {
                    $imageEntry = [
                        'type' => null,
                        'value' => null,
                        'is_primary' => $img['isThumbnail'] ?? false,
                        'alt_text' => $img['alt_text'] ?? $product->name,
                        'description' => $img['description'] ?? null,
                    ];
                
                    if (!empty($img['url']) && is_string($img['url'])) {
                        $imageEntry['type'] = 'url';
                        $imageEntry['value'] = $img['url'];
                        $images[] = $imageEntry;
                    }
                
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
        
            foreach ($images as $index => $img) {
                $path = null;
            
                if ($img['type'] === 'url') {
                    if (!filter_var($img['value'], FILTER_VALIDATE_URL)) {
                        continue;
                    }
                
                    try {
                        $response = Http::timeout(5)->get($img['value']);
                    
                        if ($response->ok()) {
                            $imageContent = $response->body();
                            $extension = pathinfo(parse_url($img['value'], PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                            $imageName = time() . '_' . $index . '.' . $extension;
                        
                            $filePath = public_path('product_images/' . $imageName);
                            file_put_contents($filePath, $imageContent);
                        
                            $path = 'product_images/' . $imageName;
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            
                ProductImage::create([
                    'store_id' => $storeId,
                    'product_id' => $product->id,
                    'path' => $path,
                    'is_primary' => $img['is_primary'] ?? ($index === 0),
                    'alt_text' => $img['alt_text'] ?? null,
                    'description' => $img['description'] ?? null,
                ]);
            }
        }

            // ✅ Add to response
            $updatedProducts[] = new ProductResource($product->load([
                'categories', 'content', 'seo', 'identifiers', 'images'
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
            $products = Product::where('store_id', $storeId)
                ->where(function ($q) use ($queryStr) {
                    $q->where('name', 'like', "%$queryStr%")
                      ->orWhere('sku', 'like', "%$queryStr%")
                      ->orWhere('slug', 'like', "%$queryStr%")
                      ->orWhere('price', 'like', "%$queryStr%")
                      ->orWhere('search_keywords', 'like', "%$queryStr%")
                      ->orWhereHas('categories', fn($q2) => $q2->where('name', 'like', "%$queryStr%"))
                      ->orWhereHas('brand', fn($q3) => $q3->where('name', 'like', "%$queryStr%"));
                })
                ->with(['categories', 'brand'])
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
                foreach ($products as $product) {
            \DB::table('product_searches')->updateOrInsert(
                [
                    'product_id' => $product->id,
                    'store_id'   => $storeId
                ],
                [
                    'search_count' => \DB::raw('search_count + 1'),
                    'updated_at'   => now()
                ]
            );
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

    public function popularProducts(Request $request)
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json(['status' => false, 'message' => 'Store not selected.'], 400);
        }

        $limit = (int) $request->input('limit', 10);

        $popularProducts = Product::where('store_id', $storeId)
            ->whereHas('searchStats') 
           ->with([
                'categories',
                'brand',
                'images',
                'reviews.user',
                'content',
                'identifiers',
                'seo'
            ])
            ->withCount(['searchStats as search_count' => function($q) use ($storeId){
                $q->where('store_id', $storeId);
            }])
            ->orderByDesc('search_count')
            ->take($limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Popular products retrieved.',
            'data' => ProductResource::collection($popularProducts)
        ]);
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



    // public function importCSV(Request $request)
    // {
    //     try {
    //         // ✅ Validate file
    //         $request->validate([
    //             'file' => 'required|file|mimes:csv,txt,xlsx,xls'
    //         ]);
        
    //         // ✅ Get current store
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json(['error' => 'Store not selected.'], 400);
    //         }
        
    //         // ✅ Save uploaded file
    //         $file = $request->file('file');
    //         $path = $file->storeAs('imports', time() . '_' . $file->getClientOriginalName());
        
    //         // ✅ Production-safe column mapping
    //         $columnMapping = [
    //             // Required fields
    //             'Product Name' => 'Product Name',
    //             'Product Code/SKU' => 'Product Code/SKU',
    //             'Brand Name' => 'Brand Name',
    //             'Distributor Name' => 'Distributor Name',
    //             'GPS Category' => 'GPS Category',
            
    //             // Pricing
    //             'Retail Price' => 'Ignore',
    //             'Cost Price' => 'Ignore',
    //             'Sale Price' => 'Ignore',
    //             'Price' => 'Ignore',
    //             'Fixed Shipping Cost' => 'Ignore',
            
    //             // Inventory
    //             'Track Inventory' => 'Track Inventory',
    //             'Current Stock Level' => 'Current Stock Level',
    //             'Low Stock Level' => 'Ignore',
    //             'Allow Purchases?' => 'Allow Purchases?',
    //             'Product Visible?' => 'Product Visible?',
            
    //             // Meta fields
    //             'Product Type' => 'Ignore',
    //             'Item Type' => 'Ignore',
    //             'Product Description' => 'Ignore',
    //             'Product Warranty' => 'Ignore',
    //             'Product Availability' => 'Ignore',
    //             'Option Set' => 'Ignore',
    //             'Option Set Align' => 'Ignore',
            
    //             // Dimensions
    //             'Product Weight' => 'Ignore',
    //             'Product Width' => 'Ignore',
    //             'Product Height' => 'Ignore',
    //             'Product Depth' => 'Ignore',
            
    //             // SEO
    //             'Page Title' => 'Ignore',
    //             'Product Url' => 'Ignore',
    //             'Meta Description' => 'Ignore',
    //             'Meta Keywords' => 'Ignore',
    //             'Redirect Old URL?' => 'Ignore',
            
    //             // Identifiers
    //             'GPS Manufacturer Part Number' => 'GPS Manufacturer Part Number',
    //             'GPS Global Trade Item Number' => 'Ignore',
    //             'Bin Picking Number' => 'Ignore',
            
    //             // Advanced
    //             'Product Condition' => 'Ignore',
    //             'Show Product Condition?' => 'Ignore',
    //             'Sort Order' => 'Ignore',
    //             'Search Keywords' => 'Ignore',
    //             'Stop Processing Rules' => 'Ignore',
    //             'Product Tax Class' => 'Ignore',
    //             'Tax Provider Tax Code' => 'Ignore',
    //             'Product Custom Fields' => 'Ignore',
    //         ];
        
    //         // ✅ Run import with mapping
    //         Excel::import(new ProductImport($storeId, $columnMapping), storage_path("app/{$path}"));
        
    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Import completed successfully.'
    //         ]);
        
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }
    // public function importCSV(Request $request)
    // {
    //     try {
    //         $request->validate([
    //             'file' => 'required|file|mimes:csv,txt,xlsx,xls'
    //         ]);
        
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json(['error' => 'Store not selected.'], 400);
    //         }
        
    //         $file = $request->file('file');
    //         $originalFileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
    //         $path = $file->storeAs('imports', time() . '_' . $file->getClientOriginalName());
    //         session()->put("import_file_name_{$storeId}", $originalFileName);
        
    //         // ✅ Updated column mapping based on your CSV structure
    //         $columnMapping = [
    //             'Product Name' => 'Product Name',
    //             'Product Code/SKU' => 'Product Code/SKU',
    //             'Brand Name' => 'Brand Name',
    //             'GPS Category' => 'Category', // 👈 THIS FIXES THE CATEGORY ISSUE
            
    //             // Optional pricing fields
    //             'Retail Price' => 'Retail Price',
    //             'Cost Price' => 'Cost Price',
    //             'Sale Price' => 'Sale Price',
    //             'Fixed Shipping Cost' => 'Fixed Shipping Cost',
    //             'Free Shipping' => 'Free Shipping',
            
    //             // Inventory
    //             'Track Inventory' => 'Track Inventory',
    //             'Current Stock Level' => 'Current Stock Level',
    //             'Low Stock Level' => 'Low Stock Level',
    //             'Allow Purchases?' => 'Allow Purchases?',
    //             'Product Visible?' => 'Product Visible?',
            
    //             // Product info
    //             'Item Type' => 'Item Type',
    //             'Product Type' => 'Product Type',
    //             'Product Description' => 'Product Description',
    //             'Product Warranty' => 'Product Warranty',
    //             'Product Availability' => 'Product Availability',
    //             'Option Set' => 'Option Set',
    //             'Option Set Align' => 'Option Set Align',
            
    //             // Dimensions
    //             'Product Weight' => 'Product Weight',
    //             'Product Width' => 'Product Width',
    //             'Product Height' => 'Product Height',
    //             'Product Depth' => 'Product Depth',
            
    //             // SEO
    //             'Page Title' => 'Page Title',
    //             'Product Url' => 'Product URL',
    //             'Meta Description' => 'Meta Description',
    //             'Meta Keywords' => 'Meta Keywords',
    //             'Redirect Old URL?' => 'Redirect Old URL?', // You can leave this as 'Ignore' if not used
            
    //             // Identifiers
    //             'GPS Manufacturer Part Number' => 'Manufacturer Part Number',
    //             'GPS Global Trade Item Number' => 'Global Trade Item Number',
    //             'Product UPC/EAN' => 'Product UPC/EAN',
    //             'Bin Picking Number' => 'Bin Picking Number',
            
    //             // Advanced
    //             'Product Condition' => 'Product Condition',
    //             'Show Product Condition?' => 'Show Product Condition?',
    //             'Sort Order' => 'Sort Order',
    //             'Search Keywords' => 'Search Keywords',
    //             'Stop Processing Rules' => 'Stop Processing Rules',
    //             'Product Tax Class' => 'Product Tax Class',
    //             'Tax Provider Tax Code' => 'Tax Provider Tax Code',
    //             'Product Custom Fields' => 'Product Custom Fields',
    //         ];
        
    //         Excel::import(new \App\Imports\ProductImport($storeId, $columnMapping), storage_path("app/{$path}"));
        
    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Import completed successfully.'
    //         ]);
        
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

//    public function importCSV(Request $request)
// {
//     try {
//         $request->validate([
//             'file' => 'required|file|mimes:csv,txt,xlsx,xls',
//             'ignore_columns' => 'nullable|array',
//         ]);

//         $storeId = current_store_id();
//         if (!$storeId) {
//             return response()->json(['error' => 'Store not selected.'], 400);
//         }

//         $file = $request->file('file');
//         $originalFileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
//         $path = $file->storeAs('imports', time() . '_' . $file->getClientOriginalName());
//         session()->put("import_file_name_{$storeId}", $originalFileName);

//         $filePath = storage_path("app/{$path}");
//         $csvHeaders = [];

//         if (($handle = fopen($filePath, 'r')) !== false) {
//             $csvHeaders = fgetcsv($handle);
//             fclose($handle);
//         }

//         if (!$csvHeaders) {
//             return response()->json(['status' => false, 'error' => 'CSV file is empty or invalid.'], 400);
//         }

//         // Allowed columns for import
//         $allowedColumns = [
//             'Item Type', 'Distributor Name', 'Distributor SKU', 'Product Name', 'Product Type',
//             'Product Code/SKU', 'Bin Picking Number', 'Brand Name', 'Option Set Align',
//             'Product Description', 'Price', 'Cost Price', 'Retail Price', 'Sale Price',
//             'Fixed Shipping Cost', 'Free Shipping', 'Product Warranty', 'Product Weight',
//             'Product Width', 'Product Height', 'Product Depth', 'Allow Purchases?', 'Product Visible?',
//             'Product Availability', 'Track Inventory', 'Current Stock Level', 'Low Stock Level',
//             'Category', 'Product Image ID - 1', 'Product Image File - 1', 'Product Image Description - 1',
//             'Product Image Is Thumbnail - 1', 'Product Image Sort - 1', 'Search Keywords', 'Page Title',
//             'Meta Keywords', 'Meta Description', 'Product Condition', 'Product Tax Class', 'Product UPC/EAN',
//             'Product Url', 'GPS Global Trade Item Number', 'GPS Manufacturer Part Number', 'GPS Category',
//             'GPS Enabled', 'Show Product Condition?'
//         ];

//         // Build column mapping with ignore logic
//         $columnMapping = [];
//         foreach ($csvHeaders as $header) {
//             $header = mb_convert_encoding($header, 'UTF-8', 'UTF-8');
//             $columnMapping[$header] = in_array($header, $allowedColumns) ? $header : 'Ignore';
//         }

//         // Override with user-selected ignore columns if any
//         if ($request->has('ignore_columns') && is_array($request->ignore_columns)) {
//             foreach ($request->ignore_columns as $ignoreHeader) {
//                 $ignoreHeader = mb_convert_encoding($ignoreHeader, 'UTF-8', 'UTF-8');
//                 if (array_key_exists($ignoreHeader, $columnMapping)) {
//                     $columnMapping[$ignoreHeader] = 'Ignore';
//                 }
//             }
//         }

//         // Ensure UTF-8 for keys and values
//         $columnMapping = collect($columnMapping)->mapWithKeys(function ($value, $key) {
//             return [mb_convert_encoding($key, 'UTF-8', 'UTF-8') => mb_convert_encoding($value, 'UTF-8', 'UTF-8')];
//         })->toArray();

//         // Dispatch background job
//         ProcessProductImport::dispatch($filePath, $storeId, $columnMapping)->onQueue('imports');

//         return response()->json([
//             'status' => true,
//             'message' => 'Import started. Processing in background.'
//         ]);

//     } catch (\Exception $e) {
//         return response()->json([
//             'status' => false,
//             'error' => $e->getMessage()
//         ], 500);
//     }
// }

public function importCSV(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $file = $request->file('file');

        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json(['error' => 'Store not selected'], 400);
        }

        $storedFilePath = $file->store('imports');

        // unique progress key
        $progressKey = 'import_' . Str::uuid();

        // initialize progress
        Cache::put($progressKey, [
            'current' => 0,
            'status' => 'queued'
        ], 7200);

        Cache::put($progressKey . "_cancel", false, 7200);
        Cache::put($progressKey . "_count", 0, 7200);

        Excel::queueImport(
            new ProductImport($storeId, $progressKey),
            $storedFilePath
        );

        return response()->json([
            'success' => true,
            'progress_key' => $progressKey
        ]);
    }

    public function importProgress($progressKey)
    {
        return response()->json(Cache::get($progressKey));
    }

    public function cancelImport(Request $request)
    {
        $key = $request->progress_key;
        Cache::put($key . "_cancel", true);

        return response()->json(['status' => 'cancel requested']);
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
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        // 🧠 Get original import file name from session (default fallback)
        $originalFileName = session()->get("import_file_name_{$storeId}", 'products_export');

        $fileName = $originalFileName . '_export_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new ProductExport($storeId), $fileName);
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
            'data'        => new ProductResource($product->fresh(['categories','brand','images','content','seo']))
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
