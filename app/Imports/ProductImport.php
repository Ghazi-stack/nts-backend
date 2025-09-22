<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductSeo;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToArray;

class ProductImport implements ToArray
{
    protected $storeId;
    protected $existingCategories;
    protected $existingBrands;
    protected $columnMap;

    public function __construct($storeId, $columnMap = [])
    {
        $this->storeId = $storeId;
        $this->columnMap = $columnMap;
    }

    public function array(array $rows)
    {
        if (empty($rows) || !isset($rows[0])) {
            Log::warning("❌ Import aborted: No rows found in the Excel file.");
            return;
        }

        $header = array_map('trim', $rows[0]);
        unset($rows[0]);

        $this->preloadCategories();
        $this->preloadBrands();

        $now = Carbon::now();

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            try {
                if (count($row) !== count($header)) {
                    Log::warning("❌ Row $rowNumber skipped: Header and row column count mismatch.");
                    continue;
                }

                $data = array_combine($header, $row);
                if (!$data) {
                    Log::warning("❌ Row $rowNumber skipped: Invalid row structure.");
                    continue;
                }

                $sku = $this->getMappedValue('Product Code/SKU', $data);
                $productName = $this->getMappedValue('Product Name', $data);

                if (empty($sku) || empty($productName)) {
                    Log::warning("❌ Row $rowNumber skipped: Missing SKU or Product Name.");
                    continue;
                }

                // ✅ Brand
                $brandName = $this->getMappedValue('Brand Name', $data);
                if (empty($brandName)) {
                    Log::warning("❌ Row $rowNumber skipped: No brand name provided.");
                    continue;
                }

                $brandKey = strtolower($brandName);
                $brand = $this->existingBrands->get($brandKey);
                if (!$brand) {
                    $brand = Brand::create([
                        'store_id' => $this->storeId,
                        'name' => $brandName,
                        'slug' => Str::slug($brandName),
                    ]);
                    $this->existingBrands->put($brandKey, $brand);
                    Log::info("✅ Row $rowNumber: Brand '$brandName' created.");
                }

                // ✅ Category
                $categoryPath = $this->getMappedValue('GPS Category', $data) ?? '';
                $category = $this->findOrCreateCategoryPath($categoryPath);
                if (!$category) {
                    Log::warning("❌ Row $rowNumber skipped: Could not resolve category path '$categoryPath'.");
                    continue;
                }

                // ✅ Product Data
                $existingProduct = Product::where('store_id', $this->storeId)
                    ->where('sku', $sku)
                    ->first();

                $productData = [
                    'sku' => $sku,
                    'store_id' => $this->storeId,
                    'item_type' => $this->getMappedValue('Item Type', $data),
                    'product_type' => $this->getMappedValue('Product Type', $data),
                    'name' => $productName,
                    'slug' => $existingProduct ? $existingProduct->slug : Str::slug($productName) . '-' . Str::uuid(),
                    'price' => $this->getMappedValue('Retail Price', $data) ?? 0,
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'condition' => $this->getMappedValue('Product Condition', $data),
                    'dimensions' => [
                        'weight' => $this->getMappedValue('Product Weight', $data),
                        'width' => $this->getMappedValue('Product Width', $data),
                        'height' => $this->getMappedValue('Product Height', $data),
                        'depth' => $this->getMappedValue('Product Depth', $data),
                    ],
                    'track_inventory' => $this->toBool($this->getMappedValue('Track Inventory', $data)),
                    'current_stock' => $this->getMappedValue('Current Stock Level', $data) ?? 0,
                    'low_stock' => $this->getMappedValue('Low Stock Level', $data) ?? 0,
                    'visible_on_storefront' => $this->toBool($this->getMappedValue('Product Visible?', $data)),
                    'free_shipping' => $this->toBool($this->getMappedValue('Free Shipping', $data)),
                    'allow_purchase' => $this->toBool($this->getMappedValue('Allow Purchases?', $data)),
                    'option_set' => $this->getMappedValue('Option Set', $data),
                    'option_set_align' => $this->getMappedValue('Option Set Align', $data),
                    'cost_price' => $this->getMappedValue('Cost Price', $data),
                    'sale_price' => $this->getMappedValue('Sale Price', $data),
                    'fixed_shipping_cost' => $this->getMappedValue('Fixed Shipping Cost', $data),
                    'custom_fields' => $this->parseCustomFields($this->getMappedValue('Product Custom Fields', $data)),
                    'is_featured' => false,
                    'show_condition' => $this->toBool($this->getMappedValue('Show Product Condition?', $data)),
                    'sort_order' => $this->getMappedValue('Sort Order', $data) ?? 0,
                    'search_keywords' => $this->getMappedValue('Search Keywords', $data) ?? '',
                    'stop_processing_rules' => $this->toBool($this->getMappedValue('Stop Processing Rules', $data)),
                    'tax_class' => $this->getMappedValue('Product Tax Class', $data),
                    'tax_provider_code' => $this->getMappedValue('Tax Provider Tax Code', $data),
                    'updated_at' => $now,
                    'created_at' => $existingProduct ? $existingProduct->created_at : $now,
                ];

                if ($existingProduct) {
                    $existingData = collect($existingProduct->toArray())->only(array_keys($productData))->map(function ($v) {
                        return is_array($v) ? json_encode($v) : $v;
                    })->toArray();

                    $newData = collect($productData)->map(function ($v) {
                        return is_array($v) ? json_encode($v) : $v;
                    })->toArray();

                    if ($existingData == $newData) {
                        Log::info("⏩ Row $rowNumber: Duplicate product '$sku' skipped.");
                        continue;
                    }

                    $existingProduct->update($productData);
                    $product = $existingProduct;
                    Log::info("🔄 Row $rowNumber: Product '{$product->name}' updated.");
                } else {
                    $product = Product::create($productData);
                    Log::info("✅ Row $rowNumber: Product '{$product->name}' created.");
                }

                $product->categories()->syncWithoutDetaching([$category->id]);

                ProductContent::updateOrCreate(
                    ['store_id' => $this->storeId, 'product_id' => $product->id],
                    [
                        'description' => $this->getMappedValue('Product Description', $data) ?? '',
                        'warranty' => $this->getMappedValue('Product Warranty', $data) ?? '',
                        'availability_text' => $this->getMappedValue('Product Availability', $data) ?? '',
                        'template_layout' => 'default',
                    ]
                );

                ProductIdentifier::updateOrCreate(
                    ['store_id' => $this->storeId, 'product_id' => $product->id],
                    [
                        'mpn' => $this->getMappedValue('GPS Manufacturer Part Number', $data) ?? '',
                        'upc' => $this->getMappedValue('Product UPC/EAN', $data) ?? '',
                        'gtin' => $this->getMappedValue('GPS Global Trade Item Number', $data) ?? '',
                        'bpn' => $this->getMappedValue('Bin Picking Number', $data) ?? '',
                    ]
                );

                ProductSeo::updateOrCreate(
                    ['store_id' => $this->storeId, 'product_id' => $product->id],
                    [
                        'page_title' => $this->getMappedValue('Page Title', $data) ?? $productName,
                        'product_url' => ltrim($this->getMappedValue('Product Url', $data) ?? '', '/'),
                        'meta_description' => $this->getMappedValue('Meta Description', $data) ?? '',
                        'meta_keywords' => $this->getMappedValue('Meta Keywords', $data) ?? '',
                        'redirect_old_url' => $this->toBool($this->getMappedValue('Redirect Old URL?', $data)),
                    ]
                );

                // ✅ Optional image
                $imagePath = collect($data)->map(fn($v) => trim($v))->first(function ($value) {
                    return is_string($value) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $value);
                });

                if ($imagePath) {
                    $isUrl = filter_var($imagePath, FILTER_VALIDATE_URL);
                    $finalPath = $isUrl ? $imagePath : 'product_images/' . ltrim($imagePath, '/');

                    ProductImage::create([
                        'store_id' => $this->storeId,
                        'product_id' => $product->id,
                        'path' => $finalPath,
                        'alt_text' => $productName,
                        'is_primary' => true,
                    ]);
                }

            } catch (\Throwable $e) {
                Log::error("❌ Row $rowNumber: Error during import");
                Log::error("➡️ Message: " . $e->getMessage());
                Log::error("➡️ Trace: " . $e->getTraceAsString());
            }
        }
    }

    private function getMappedValue($label, $data)
    {
        if (!isset($this->columnMap[$label]) || $this->columnMap[$label] === 'Ignore') {
            return null;
        }

        $mapped = $this->columnMap[$label];
        return $data[$mapped] ?? null;
    }

    private function preloadCategories()
    {
        $this->existingCategories = Category::where('store_id', $this->storeId)->get();
    }

    private function preloadBrands()
    {
        $this->existingBrands = Brand::where('store_id', $this->storeId)
            ->get()
            ->keyBy(fn($brand) => strtolower(trim($brand->name)));
    }

    private function findOrCreateCategoryPath($path)
    {
        $segments = array_map('trim', explode('/', $path));
        $parentId = null;
        $category = null;

        foreach ($segments as $segment) {
            if (!$segment) continue;

            $slug = Str::slug($segment);

            $category = $this->existingCategories->first(function ($cat) use ($slug, $parentId) {
                return $cat->slug === $slug && $cat->parent_id == $parentId;
            });

            if (!$category) {
                $category = Category::create([
                    'store_id' => $this->storeId,
                    'name' => $segment,
                    'slug' => $slug,
                    'parent_id' => $parentId,
                ]);

                $this->existingCategories->push($category);

                Log::info("✅ Category '$segment' created.");
            }

            $parentId = $category->id;
        }

        return $category;
    }

    private function parseCustomFields($fieldString)
    {
        if (!$fieldString) return [];

        $decoded = json_decode($fieldString, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return collect($decoded)->mapWithKeys(function ($value, $key) {
                return [trim(str_replace('"', '', $key)) => trim(str_replace('"', '', $value))];
            })->toArray();
        }

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

    private function toBool($val)
    {
        return in_array(strtolower(trim((string) $val)), ['yes', 'y', 'true', '1']);
    }
}
