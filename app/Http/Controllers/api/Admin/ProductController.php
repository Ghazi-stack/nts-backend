<?php

namespace App\Http\Controllers\api\Admin;

use Exception;
use App\Models\User;
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
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\ProductResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Product::with(['brand', 'category', 'images'])
                ->where('store_id', current_store_id());

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

            if ($request->has('sortBy')) {
                $query->orderBy($request->sortBy, $request->get('sortOrder', 'asc'));
            } else {
                $query->orderBy('id', 'desc');
            }

            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 50);
            $products = $query->paginate($pageSize, ['*'], 'page', $page);

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
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function showProduct($slug)
    {
        try {
            $product = Product::with([
                'brand',
                'category.parent.parent.parent.parent', // support 5-level
                'images',
                'content',
                'identifiers',
                'seo'
            ])->where('slug', $slug)->firstOrFail();

            $product->increment('views');

            return response()->json([
                'status' => true,
                'message' => 'Product Retrieved',
                'data' => new ProductResource($product)
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found.'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve product.'], 500);
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
                'categoryId' => 'required|exists:categories,id',
                'condition' => 'nullable|string',
                'price' => 'nullable',
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
                'image' => 'nullable',
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
            ]);

            $product = Product::create([
                'store_id' => $storeId,
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']) . '-' . uniqid(),
                'sku' => $validated['sku'],
                'price' => $validated['price'],
                'msrp' => $validated['msrp'] ?? null,
                'cost_price' => $validated['costPrice'] ?? null,
                'sale_price' => $validated['salePrice'] ?? null,
                'brand_id' => $validated['brandId'],
                'category_id' => $validated['categoryId'],
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
            ]);

            // ✅ Handle image URLs or file uploads
            $images = [];

            if ($request->hasFile('image')) {
                $images = $request->file('image');
            } elseif (!empty($validated['image'])) {
                $images = is_array($validated['image']) ? $validated['image'] : [$validated['image']];
            }

            foreach ($images as $index => $img) {
                if (is_string($img)) {
                    // It's a URL
                    try {
                        $imageData = file_get_contents($img);
                        $imageName = time() . '_' . $index . '_' . basename(parse_url($img, PHP_URL_PATH));
                        File::put(public_path("product_images/{$imageName}"), $imageData);
                    } catch (\Exception $e) {
                        continue; // skip invalid URL
                    }
                } else {
                    // It's a file
                    $imageName = time() . '_' . $index . '.' . $img->getClientOriginalExtension();
                    $img->move(public_path('product_images'), $imageName);
                }

                ProductImage::create([
                    'store_id' => $storeId,
                    'product_id' => $product->id,
                    'path' => "product_images/{$imageName}",
                    'is_primary' => $index === 0
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
                    'category.parent',
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



   
    public function updateProduct(Request $request)
    {
        try {
            $validated = $request->validate([
                'products' => 'required|array|min:1',
                'products.*.id' => 'required|exists:products,id',
                'products.*.fields' => 'required|array|min:1',
            ]);
        
            $updated = [];
            $failed = [];
        
            foreach ($validated['products'] as $item) {
                try {
                    $product = Product::where('store_id', current_store_id())
                        ->findOrFail($item['id']);
                
                    $fields = $item['fields'];
                
                    // ✅ camelCase to snake_case mapping
                    $fieldMap = [
                        'costPrice' => 'cost_price',
                        'salePrice' => 'sale_price',
                        'brandId' => 'brand_id',
                        'categoryId' => 'category_id',
                        'trackInventory' => 'track_inventory',
                        'currentStock' => 'current_stock',
                        'lowStock' => 'low_stock',
                        'isFeatured' => 'is_featured',
                        'showCondition' => 'show_condition',
                        'sortOrder' => 'sort_order',
                        'searchKeywords' => 'search_keywords',
                        'isVisible' => 'visible_on_storefront',
                        'customFields' => 'custom_fields',
                        'msrp' => 'msrp'
                    ];
                
                    $productFields = [];
                    foreach ($fields as $key => $value) {
                        $dbKey = $fieldMap[$key] ?? $key;
                        $productFields[$dbKey] = $value;
                    }
                
                    if (!empty($productFields)) {
                        $product->update($productFields);
                    }
                
                    // ✅ Content update
                    if (
                        isset($fields['description']) || isset($fields['warranty']) ||
                        isset($fields['availabilityText']) || isset($fields['templateLayout'])
                    ) {
                        $content = $product->content ?? new ProductContent(['store_id' => $product->store_id]);
                        $content->fill([
                            'description' => $fields['description'] ?? $content->description,
                            'warranty' => $fields['warranty'] ?? $content->warranty,
                            'availability_text' => $fields['availabilityText'] ?? $content->availability_text,
                            'template_layout' => $fields['templateLayout'] ?? $content->template_layout,
                        ]);
                        $content->product_id = $product->id;
                        $content->save();
                    }
                
                    // ✅ Identifiers update
                    if (
                        isset($fields['mpn']) || isset($fields['upc']) ||
                        isset($fields['gtin']) || isset($fields['bpn'])
                    ) {
                        $identifiers = $product->identifiers ?? new ProductIdentifier(['store_id' => $product->store_id]);
                        $identifiers->fill([
                            'mpn' => $fields['mpn'] ?? $identifiers->mpn,
                            'upc' => $fields['upc'] ?? $identifiers->upc,
                            'gtin' => $fields['gtin'] ?? $identifiers->gtin,
                            'bpn' => $fields['bpn'] ?? $identifiers->bpn,
                        ]);
                        $identifiers->product_id = $product->id;
                        $identifiers->save();
                    }
                
                    // ✅ SEO update
                    if (
                        isset($fields['pageTitle']) || isset($fields['productUrl']) ||
                        isset($fields['metaDescription'])
                    ) {
                        $seo = $product->seo ?? new ProductSeo(['store_id' => $product->store_id]);
                        $seo->fill([
                            'page_title' => $fields['pageTitle'] ?? $seo->page_title,
                            'product_url' => $fields['productUrl'] ?? $seo->product_url,
                            'meta_description' => $fields['metaDescription'] ?? $seo->meta_description,
                        ]);
                        $seo->product_id = $product->id;
                        $seo->save();
                    }
                
                    // ✅ Images (URL or file)
                    if (isset($fields['image'])) {
                        $images = is_array($fields['image']) ? $fields['image'] : [$fields['image']];
                    
                        // Optional: clear old images first
                        ProductImage::where('product_id', $product->id)->delete();
                    
                        foreach ($images as $index => $img) {
                            if (is_string($img)) {
                                // Image from URL
                                try {
                                    $imageData = file_get_contents($img);
                                    $imageName = time() . '_' . $index . '_' . basename(parse_url($img, PHP_URL_PATH));
                                    File::put(public_path("product_images/{$imageName}"), $imageData);
                                } catch (\Exception $e) {
                                    continue;
                                }
                            } elseif ($img instanceof \Illuminate\Http\UploadedFile) {
                                // Image from file upload (via controller or future extension)
                                $imageName = time() . '_' . $index . '.' . $img->getClientOriginalExtension();
                                $img->move(public_path('product_images'), $imageName);
                            } else {
                                continue; // Skip invalid
                            }
                        
                            ProductImage::create([
                                'store_id' => $product->store_id,
                                'product_id' => $product->id,
                                'path' => "product_images/{$imageName}",
                                'is_primary' => $index === 0
                            ]);
                        }
                    }
                
                    $updated[] = $product->id;
                } catch (Exception $e) {
                    $failed[] = [
                        'id' => $item['id'],
                        'error' => $e->getMessage()
                    ];
                }
            }
        
            return response()->json([
                'status' => true,
                'message' => 'Bulk update completed.',
                'updated' => $updated,
                'failed' => $failed
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function searchProduct(Request $request)
    {
       try {
            $request->validate([
                'query' => 'required|string|min:2'
            ]);

            $query = $request->input('query');
            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 50);

           $products = Product::where(function ($q) use ($query) {
                $q->where('name', 'like', "%$query%")
                  ->orWhere('sku', 'like', "%$query%")
                  ->orWhere('slug', 'like', "%$query%")
                  ->orWhere('price', 'like', "%$query%")
                  ->orWhere('search_keywords', 'like', "%$query%")
                  ->orWhereHas('category', function ($q2) use ($query) {
                      $q2->where('name', 'like', "%$query%");
                  })
                  ->orWhereHas('brand', function ($q3) use ($query) {
                      $q3->where('name', 'like', "%$query%");
                  });
            })
            ->with(['category', 'brand'])
            ->paginate($pageSize, ['*'], 'page', $page);

            return response()->json([
                'status'  => true,
                'message' => 'Products retrieved successfully.',
                'data'    => ProductResource::collection($products)
            ], 200); // ✅ HTTP 200 OK
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function newProducts()
    {
        try {
            $products = Product::latest()
                ->with(['category', 'brand'])
                ->take(10)
                ->get();

            return response()->json([
                'status'  => true,
                'message' => 'New products retrieved successfully.',
                'data'    => ProductResource::collection($products)
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to load new products.'], 500);
        }
    }

    public function featuredProducts()
    {
        try {
            $products = Product::where('is_featured', true)
                ->with(['category', 'brand'])
                ->latest()
                ->take(10)
                ->get();

        return response()->json([
                'status'  => true,
                'message' => 'Featured products retrieved successfully.',
                'data'    => ProductResource::collection($products)
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to load featured products.'], 500);
        }
    }

    public function popularProducts()
    {
        try {
            $products = Product::with(['category', 'brand'])
                ->orderByDesc('views')
                ->take(10)
                ->get();

            return response()->json([
                'status'  => true,
                'message' => 'Popular products retrieved successfully.',
                'data'    => ProductResource::collection($products)
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to load popular products.'], 500);
        }
    }


    public function searchAdvanced(Request $request)
    {
        try {
            $query = Product::with(['brand', 'category', 'images']);

            // 🔍 Search Keywords
            if ($request->filled('searchKeywords')) {
                $keywords = $request->searchKeywords;
                $query->where(function ($q) use ($keywords) {
                    $q->where('name', 'like', "%{$keywords}%")
                      ->orWhere('sku', 'like', "%{$keywords}%");
                });
            }

            // 🔍 Brand
            if ($request->filled('brandId')) {
                $query->where('brand_id', $request->brandId);
            }

            // 🔍 Categories (array or single)
            if ($request->filled('categoryIds')) {
                $categoryIds = is_array($request->categoryIds) ? $request->categoryIds : [$request->categoryIds];
                $query->whereIn('category_id', $categoryIds);
            }

            // 🔍 Price Range
            if ($request->filled('priceMin')) {
                $query->where('price', '>=', $request->priceMin);
            }

            if ($request->filled('priceMax')) {
                $query->where('price', '<=', $request->priceMax);
            }

            // 🔍 Inventory Level
            if ($request->filled('inventoryMin')) {
                $query->where('current_stock', '>=', $request->inventoryMin);
            }

            if ($request->filled('inventoryMax')) {
                $query->where('current_stock', '<=', $request->inventoryMax);
            }

            // 🔍 Visibility
            if ($request->filled('isVisible')) {
                $query->where('visible_on_storefront', $request->boolean('isVisible'));
            }

            // 🔍 Featured
            if ($request->filled('isFeatured')) {
                $query->where('is_featured', $request->boolean('isFeatured'));
            }

            // 🔍 Free Shipping (optional field — add it if exists in DB)
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


    private function getCategoryCounts()
    {
        $categories = Category::with(['childrenRecursive', 'products'])->whereNull('parent_id')->get();

        return $categories->map(function ($category) {
            return $this->formatCategoryWithCounts($category);
        });
    }

    private function formatCategoryWithCounts($category)
    {
        $totalProducts = $category->products->count();

        foreach ($category->childrenRecursive as $child) {
            $totalProducts += $this->countProductsRecursive($child);
        }

        return [
            'id' => $category->id,
            'name' => $category->name,
            'total_products' => $totalProducts,
            'subcategories' => $category->childrenRecursive->map(function ($sub) {
                return $this->formatCategoryWithCounts($sub);
            })
        ];
    }

    private function countProductsRecursive($category)
    {
        $count = $category->products->count();

        foreach ($category->childrenRecursive as $child) {
            $count += $this->countProductsRecursive($child);
        }

        return $count;
    }

     public function destroyProduct(Request $request, $id = null)
    {
        try {
            if ($id) {
                $product = Product::where('store_id', current_store_id())->findOrFail($id);
                $product->delete();
                return response()->json([
                    'status' => true,
                    'message' => 'Product deleted successfully.',
                    'deletedIds' => [$id]
                ]);
            }

            $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'exists:products,id',
            ]);

            $deleted = [];
            $failed = [];

            foreach ($request->ids as $prodId) {
                try {
                    $product = Product::where('store_id', current_store_id())->findOrFail($prodId);
                    $product->delete();
                    $deleted[] = $prodId;
                } catch (Exception $e) {
                    $failed[] = ['id' => $prodId, 'error' => $e->getMessage()];
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Delete process completed.',
                'deletedIds' => $deleted,
                'failed' => $failed
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Product not found.'], 404);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function importCSV(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:csv,txt,xlsx,xls'
            ]);

            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['error' => 'Store not selected.'], 400);
            }

            $file = $request->file('file');
            $ext = $file->getClientOriginalExtension();
            $path = $file->storeAs('imports', time() . '_' . $file->getClientOriginalName());

            $imported = 0;
            $skipped = [];

            if (in_array($ext, ['xlsx', 'xls'])) {
                Excel::import(new ProductImport($storeId), storage_path("app/{$path}"));
                return response()->json([
                    'status' => true,
                    'message' => 'Excel import completed.'
                ]);
            }

            // CSV (plain)
            $csv = Reader::createFromPath(storage_path("app/{$path}"), 'r');
            $csv->setHeaderOffset(0);

            foreach ($csv->getRecords() as $row) {
                try {
                    $row = array_map(fn($val) => mb_convert_encoding($val, 'UTF-8', 'auto'), $row);

                    $brand = \App\Models\Brand::firstOrCreate(
                        ['name' => $row['Brand Name']],
                        ['slug' => Str::slug($row['Brand Name'])]
                    );

                    $category = \App\Models\Category::firstOrCreate(
                        ['name' => $row['Category Name'] ?? 'Uncategorized'],
                        ['slug' => Str::slug($row['Category Name'] ?? 'Uncategorized')]
                    );

                    $product = Product::create([
                        'store_id' => $storeId,
                        'name' => $row['Product Name'],
                        'slug' => Str::slug($row['Product Name']) . '-' . uniqid(),
                        'sku' => $row['Product Code/SKU'],
                        'price' => $row['Retail Price'] ?? 0,
                        'brand_id' => $brand->id,
                        'category_id' => $category->id,
                        'track_inventory' => false,
                        'current_stock' => 0,
                        'visible_on_storefront' => true,
                        'custom_fields' => $this->parseCustomFields($row['Product Custom Fields'] ?? ''),
                        'dimensions' => json_encode([
                            'weight' => $row['Product Weight'] ?? null,
                            'width' => $row['Width'] ?? null,
                            'height' => $row['Height'] ?? null,
                            'depth' => $row['Depth'] ?? null,
                        ]),
                    ]);

                    ProductContent::create([
                        'store_id' => $storeId,
                        'product_id' => $product->id,
                        'description' => $row['Product Description'] ?? '',
                        'warranty' => $row['Warranty'] ?? '',
                        'availability_text' => $row['Availability'] ?? '',
                        'template_layout' => $row['Template Layout'] ?? '',
                    ]);

                    ProductIdentifier::create([
                        'store_id' => $storeId,
                        'product_id' => $product->id,
                        'mpn' => $row['Manufacturer Part Number'] ?? '',
                        'upc' => $row['Product UPC/EAN'] ?? '',
                        'gtin' => $row['Global Trade Item Number'] ?? '',
                        'bpn' => $row['Bin Picking Number'] ?? '',
                    ]);

                    ProductSeo::create([
                        'store_id' => $storeId,
                        'product_id' => $product->id,
                        'product_url' => $row['Product URL'] ?? '',
                        'page_title' => $row['Page Title'] ?? '',
                        'meta_description' => $row['Meta Description'] ?? '',
                    ]);

                    $imported++;
                } catch (\Throwable $e) {
                    $skipped[] = [
                        'row' => $row,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'CSV import completed.',
                'imported' => $imported,
                'skipped' => $skipped
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function parseCustomFields($fieldString)
    {
       if (!$fieldString) return [];

        // Step 1: Try decoding directly (if already JSON but escaped)
        $decoded = json_decode($fieldString, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Clean keys & values from escaped quotes
            return collect($decoded)->mapWithKeys(function ($value, $key) {
                return [
                    trim(str_replace('"', '', $key)) => trim(str_replace('"', '', $value))
                ];
            })->toArray();
        }

        // Step 2: Fallback if it's in semicolon format (key=value;key2=value2)
        $fields = [];
        $pairs = explode(';', $fieldString);
        foreach ($pairs as $pair) {
            if (strpos($pair, '=') !== false) {
                [$key, $value] = explode('=', trim($pair));
                $fields[trim($key)] = trim($value);
            }
        }
        return $fields;
    }


    public function exportCSV(Request $request)
    {
            $fileName = 'products_export_' . now()->format('Ymd_His') . '.xlsx';
            return Excel::download(new ProductExport, $fileName);
    }
      
    
    

}
