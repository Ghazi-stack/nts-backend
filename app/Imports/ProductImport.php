<?php
 
namespace App\Imports;
 
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductExtra;
use App\Models\ProductImage;
use App\Models\ProductContent;
use App\Models\ProductIdentifier;
use App\Models\ProductSeo;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Row;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Contracts\Queue\ShouldQueue;
 
class ProductImport implements OnEachRow, WithChunkReading, WithHeadingRow, ShouldQueue
{
    protected $storeId;
    protected $progressKey;
 
    // Small lightweight caches
    protected static $brandCache = [];
    protected static $categoryCache = [];
 
    public function __construct($storeId, $progressKey)
    {
        $this->storeId = $storeId;
        $this->progressKey = $progressKey;
    }
 
    public function chunkSize(): int
    {
        return 500; // very safe for localhost / production
    }
 
    public function onRow(Row $row)
    {
        $rowIndex = $row->getIndex();
 
        try {
            // Read & normalize row
            $raw = $row->toArray();
            $data = array_change_key_case(array_map('trim', $raw), CASE_LOWER);
 
            // Log the raw + normalized row (after $data exists)
            Log::info("Row data", [
                'row' => $rowIndex,
                'raw' => $raw,
                'normalized' => $data
            ]);
 
            // Cancel check
            if (Cache::get($this->progressKey . "_cancel")) {
                Cache::put($this->progressKey, [
                    'current' => Cache::get($this->progressKey . "_count"),
                    'status'  => 'cancelled'
                ]);
                throw new \Exception("Import cancelled");
            }
 
            // --- Flexible field detection: try several possible header names ---
            $sku = $this->getField($data, [
                'product code/sku', 'product code / sku', 'product code sku',
                'product code', 'sku', 'product_code', 'productcode', 'distributor sku' , 'distributor_sku'
            ]);
 
            $productName = $this->getField($data, [
                'product name', 'name', 'product_name', 'productname'
            ]);
 
            if (!$productName) {
                Log::warning("Row skipped — missing SKU or Name", [
                    'row' => $rowIndex,
                    'sku' => $sku,
                    'name' => $productName,
                ]);
                // Still increment progress count so UI doesn't hang if you want:
                Cache::increment($this->progressKey . "_count");
                Cache::put($this->progressKey, [
                    'current' => Cache::get($this->progressKey . "_count"),
                    'status'  => 'processing'
                ]);
                return;
            }
 
            // Brand detection
            $brandName = $this->getField($data, ['brand name', 'brand', 'manufacturer']) ?? 'Unknown Brand';
            $brandKey = strtolower($brandName);
 
            if (!isset(self::$brandCache[$brandKey])) {
                $brand = Brand::firstOrCreate(
                    [
                        'store_id' => $this->storeId,
                        'name' => $brandName
                    ],
                    [
                        'slug' => Str::slug($brandName),
                    ]
                );
 
                self::$brandCache[$brandKey] = $brand->id;
            }
            $brandId = self::$brandCache[$brandKey];
 
            // Category detection: try multiple headers (gps category, gps category, category)
            $categoryRaw = $this->getField($data, [
                'category', 'gps category', 'gps category', 'gps_category', 'gps category'
            ], 'Uncategorized');
 
            $categoryId = $this->resolveCategory($categoryRaw);
 
            // Build product fields with flexible header names
            $price = (float)($this->getField($data, ['price', 'unit price', 'product price']) ?? 0);
            $salePrice = (float)($this->getField($data, ['sale price', 'special price', 'discount price']) ?? 0);
            $costPrice = (float)($this->getField($data, ['cost price', 'cost']) ?? 0);
            $fixedShipping = (float)($this->getField($data, ['fixed shipping cost', 'fixed shipping']) ?? 0);
 
            // Dimensions detection (try weight, product weight, etc.)
            $weight = (float)($this->getField($data, ['weight', 'product weight', 'product_weight']) ?? 0);
            $width  = (float)($this->getField($data, ['width', 'product width', 'product_width']) ?? 0);
            $height = (float)($this->getField($data, ['height', 'product height', 'product_height']) ?? 0);
            $depth  = (float)($this->getField($data, ['depth', 'product depth', 'product_depth']) ?? 0);
 
            // Create / update product
            $product = Product::updateOrCreate(
                [
                    'store_id' => $this->storeId,
                    'sku' => $sku,
                ],
                [
                    'name' => $productName,
                    'slug' => Str::slug($productName) . '-' . Str::random(6),
                    'brand_id' => $brandId,
                    'category_id' => $categoryId,
 
                    'price' => $price,
                    'sale_price' => $salePrice,
                    'cost_price' => $costPrice,
                    'fixed_shipping_cost' => $fixedShipping,
 
                    'dimensions' => json_encode([
                        'weight' => $weight,
                        'width'  => $width,
                        'height' => $height,
                        'depth'  => $depth,
                    ]),
 
                    'allow_purchase' => $this->toBool($this->getField($data, ['allow purchases?', 'allow purchase', 'allow_purchase']) ?? 'Yes'),
                    'track_inventory' => $this->toBool($this->getField($data, ['track inventory', 'track_inventory']) ?? 'No'),
                    'current_stock' => (int)($this->getField($data, ['current stock level', 'stock', 'quantity']) ?? 0),
                    'low_stock' => (int)($this->getField($data, ['low stock level', 'low stock']) ?? 0),
                ]
            );
 
            Log::info("Product imported", [
                'row' => $rowIndex,
                'sku' => $sku,
                'name' => $productName,
                'brand_id' => $brandId,
                'category_id' => $categoryId,
                'product_id' => $product->id,
            ]);
 
            $product->categories()->syncWithoutDetaching([$categoryId]);
 
            // extras/content/identifiers/seo (use getField for flexible keys)
            ProductExtra::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $this->storeId],
                [
                    'retail_price' => (float)($this->getField($data, ['retail price', 'retail_price']) ?? 0),
                    'distributor_name' => $this->getField($data, ['distributor name', 'distributor_name']) ?? '',
                    'distributor_sku' => $this->getField($data, ['distributor sku', 'distributor_sku']) ?? '',
                ]
            );
 
            ProductContent::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $this->storeId],
                [
                    'description' => $this->getField($data, ['product description', 'description']) ?? '',
                    'warranty' => $this->getField($data, ['product warranty', 'warranty']) ?? '',
                ]
            );
 
            ProductIdentifier::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $this->storeId],
                [
                    'mpn' => $this->getField($data, ['gps manufacturer part number', 'gps manufacturer part number', 'mpn']) ?? '',
                    'upc' => $this->getField($data, ['product upc/ean', 'product upc ean', 'upc']) ?? '',
                ]
            );
 
            ProductSeo::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $this->storeId],
                [
                    'page_title' => $this->getField($data, ['page title', 'meta title']) ?? $productName,
                    'meta_description' => $this->getField($data, ['meta description', 'meta_description']) ?? '',
                ]
            );
 
            // Images (try multiple likely header patterns)
            foreach ($data as $key => $val) {
                if ($val && (str_contains($key, 'product image file') || str_contains($key, 'image') || str_contains($key, 'product image'))) {
                    ProductImage::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'path' => trim($val),
                        ],
                        [
                            'alt_text' => $productName,
                            'is_primary' => 0,
                        ]
                    );
                }
            }
 
            // Progress update
            Cache::increment($this->progressKey . "_count");
            Cache::put($this->progressKey, [
                'current' => Cache::get($this->progressKey . "_count"),
                'status'  => 'processing'
            ]);
        } catch (\Throwable $e) {
            // Log and continue
            Log::error("Import row error", [
                'row' => $rowIndex,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
 
            // Optionally increment count so progress moves even on error
            Cache::increment($this->progressKey . "_count");
            Cache::put($this->progressKey, [
                'current' => Cache::get($this->progressKey . "_count"),
                'status'  => 'processing'
            ]);
        }
    }
 
    /**
     * Try a list of possible header keys and return the first non-empty value.
     * $data must already be lowercased and trimmed keys.
     */
    private function getField(array $data, array $possibleKeys, $default = null)
    {
        foreach ($possibleKeys as $k) {
            $kNorm = $this->normalizeKey($k);
            if (array_key_exists($kNorm, $data) && $data[$kNorm] !== null && $data[$kNorm] !== '') {
                return $data[$kNorm];
            }
        }
        // also try raw keys as provided (in case user already lowercased)
        foreach ($possibleKeys as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '') {
                return $data[$k];
            }
        }
        return $default;
    }
 
    /**
     * Normalize a header/key to the same format we used when normalizing row keys.
     * - lowercase
     * - trim
     * - replace multiple spaces with single
     * - replace backslashes with spaces, remove extra chars that may appear in headers
     */
    private function normalizeKey(string $key): string
    {
        $k = mb_strtolower(trim($key));
        // replace non-alphanumeric with single space except slash (we want to consider slashes too)
        $k = preg_replace('/[\/\\\\]+/', '/', $k); // normalize slashes
        $k = preg_replace('/[^\p{L}\p{N}\/]+/u', ' ', $k);
        $k = preg_replace('/\s+/', ' ', $k);
        // also map ' / ' to '/'
        $k = str_replace(' / ', '/', $k);
        return $k;
    }
 
 
   
 
    /*
    |--------------------------------------------------------------------------
    | Resolve multi-level category path
    |--------------------------------------------------------------------------
    */
    private function resolveCategory($path)
    {
        $segments = array_map('trim', explode('/', $path));
        $parent = null;
 
        foreach ($segments as $segment) {
            $slug = Str::slug($segment);
            $key = ($parent ?: 0) . '_' . $slug;
 
            if (!isset(self::$categoryCache[$key])) {
                $category = Category::firstOrCreate(
                    [
                        'store_id' => $this->storeId,
                        'slug' => $slug,
                        'parent_id' => $parent
                    ],
                    [
                        'name' => $segment,
                    ]
                );
 
                self::$categoryCache[$key] = $category->id;
            }
 
            $parent = self::$categoryCache[$key];
        }
 
        return $parent;
    }
 
    private function toBool($val)
    {
        return in_array(strtolower(trim($val)), ['yes', 'y', '1', 'true']);
    }
}