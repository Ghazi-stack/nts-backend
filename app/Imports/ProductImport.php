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

        $chunks = array_chunk($rows, 1000, true);

        foreach ($chunks as $chunkIndex => $chunk) {
            Log::info("🔹 Processing chunk " . ($chunkIndex + 1) . " with " . count($chunk) . " rows.");

            foreach ($chunk as $index => $row) {
                $rowNumber = ($chunkIndex * 1000) + $index + 2;

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

                    // -----------------------------
                    // ✅ Mandatory Fields
                    // -----------------------------
                    $sku = $this->getMappedValue('Product Code/SKU', $data) ?? $data['Product Code/SKU'] ?? null;
                    $productName = $this->getMappedValue('Product Name', $data) ?? $data['Product Name'] ?? null;

                    if (empty($sku) || empty($productName)) {
                        Log::warning("❌ Row $rowNumber skipped: Missing SKU or Product Name.");
                        continue;
                    }

                    // -----------------------------
                    // Brand
                    // -----------------------------
                    $brandName = $this->getMappedValue('Brand Name', $data) ?? $data['Brand Name'] ?? 'Unknown Brand';
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

                    // -----------------------------
                    // Category
                    // -----------------------------
                    $categoryPath = $this->getMappedValue('GPS Category', $data) ?? $data['GPS Category'] ?? 'Uncategorized';
                    $category = $this->findOrCreateCategoryPath($categoryPath);

                    // -----------------------------
                    // Check existing product
                    // -----------------------------
                    $existingProduct = Product::where('store_id', $this->storeId)
                        ->where('sku', $sku)
                        ->first();

                    // -----------------------------
                    // Product Data
                    // -----------------------------
                    $productData = [
                        'sku' => $sku,
                        'store_id' => $this->storeId,
                        'item_type' => $this->getMappedValue('Item Type', $data) ?? $data['Item Type'] ?? null,
                        'product_type' => $this->getMappedValue('Product Type', $data) ?? $data['Product Type'] ?? null,
                        'name' => $productName,
                        'slug' => $existingProduct ? $existingProduct->slug : Str::slug($productName) . '-' . Str::uuid(),
                        'price' => (float) ($this->getMappedValue('Retail Price', $data) ?? $data['Retail Price'] ?? 0),
                        'brand_id' => $brand->id,
                        'category_id' => $category->id,
                        'condition' => $this->getMappedValue('Product Condition', $data) ?? $data['Product Condition'] ?? 'New',
                        'dimensions' => [
                            'weight' => (float) ($this->getMappedValue('Product Weight', $data) ?? $data['Product Weight'] ?? 0),
                            'width' => (float) ($this->getMappedValue('Product Width', $data) ?? $data['Product Width'] ?? 0),
                            'height' => (float) ($this->getMappedValue('Product Height', $data) ?? $data['Product Height'] ?? 0),
                            'depth' => (float) ($this->getMappedValue('Product Depth', $data) ?? $data['Product Depth'] ?? 0),
                        ],
                        'track_inventory' => $this->toBool($this->getMappedValue('Track Inventory', $data) ?? $data['Track Inventory'] ?? 'No'),
                        'current_stock' => (int) ($this->getMappedValue('Current Stock Level', $data) ?? $data['Current Stock Level'] ?? 0),
                        'low_stock' => (int) ($this->getMappedValue('Low Stock Level', $data) ?? $data['Low Stock Level'] ?? 0),
                        'visible_on_storefront' => $this->toBool($this->getMappedValue('Product Visible?', $data) ?? $data['Product Visible?'] ?? 'Yes'),
                        'free_shipping' => $this->toBool($this->getMappedValue('Free Shipping', $data) ?? $data['Free Shipping'] ?? 'No'),
                        'allow_purchase' => $this->toBool($this->getMappedValue('Allow Purchases?', $data) ?? $data['Allow Purchases?'] ?? 'Yes'),
                        'option_set' => $this->getMappedValue('Option Set', $data) ?? $data['Option Set'] ?? null,
                        'option_set_align' => $this->getMappedValue('Option Set Align', $data) ?? $data['Option Set Align'] ?? null,
                        'cost_price' => (float) ($this->getMappedValue('Cost Price', $data) ?? $data['Cost Price'] ?? 0),
                        'sale_price' => (float) ($this->getMappedValue('Sale Price', $data) ?? $data['Sale Price'] ?? 0),
                        'fixed_shipping_cost' => (float) ($this->getMappedValue('Fixed Shipping Cost', $data) ?? $data['Fixed Shipping Cost'] ?? 0),
                        'custom_fields' => $this->parseCustomFields($this->getMappedValue('Product Custom Fields', $data) ?? $data['Product Custom Fields'] ?? ''),
                        'is_featured' => false,
                        'show_condition' => $this->toBool($this->getMappedValue('Show Product Condition?', $data) ?? $data['Show Product Condition?'] ?? 'Yes'),
                        'sort_order' => (int) ($this->getMappedValue('Sort Order', $data) ?? $data['Sort Order'] ?? 0),
                        'search_keywords' => $this->getMappedValue('Search Keywords', $data) ?? $data['Search Keywords'] ?? '',
                        'stop_processing_rules' => $this->toBool($this->getMappedValue('Stop Processing Rules', $data) ?? $data['Stop Processing Rules'] ?? 'No'),
                        'tax_class' => $this->getMappedValue('Product Tax Class', $data) ?? $data['Product Tax Class'] ?? null,
                        'tax_provider_code' => $this->getMappedValue('Tax Provider Tax Code', $data) ?? $data['Tax Provider Tax Code'] ?? null,
                        'updated_at' => $now,
                        'created_at' => $existingProduct ? $existingProduct->created_at : $now,
                    ];

                    // -----------------------------
                    // Create or Update Product
                    // -----------------------------
                    if ($existingProduct) {
                        $existingProduct->update($productData);
                        $product = $existingProduct;
                        Log::info("🔄 Row $rowNumber: Product '{$product->name}' updated.");
                    } else {
                        $product = Product::create($productData);
                        Log::info("✅ Row $rowNumber: Product '{$product->name}' created.");
                    }

                    $product->categories()->syncWithoutDetaching([$category->id]);

                    // -----------------------------
                    // Product Content
                    // -----------------------------
                    ProductContent::updateOrCreate(
                        ['store_id' => $this->storeId, 'product_id' => $product->id],
                        [
                            'description' => $this->getMappedValue('Product Description', $data) ?? $data['Product Description'] ?? '',
                            'warranty' => $this->getMappedValue('Product Warranty', $data) ?? $data['Product Warranty'] ?? '',
                            'availability_text' => $this->getMappedValue('Product Availability', $data) ?? $data['Product Availability'] ?? '',
                            'template_layout' => 'default',
                        ]
                    );

                    // -----------------------------
                    // Product Identifiers
                    // -----------------------------
                    ProductIdentifier::updateOrCreate(
                        ['store_id' => $this->storeId, 'product_id' => $product->id],
                        [
                            'mpn' => $this->getMappedValue('GPS Manufacturer Part Number', $data) ?? $data['GPS Manufacturer Part Number'] ?? '',
                            'upc' => $this->getMappedValue('Product UPC/EAN', $data) ?? $data['Product UPC/EAN'] ?? '',
                            'gtin' => $this->getMappedValue('GPS Global Trade Item Number', $data) ?? $data['GPS Global Trade Item Number'] ?? '',
                            'bpn' => $this->getMappedValue('Bin Picking Number', $data) ?? $data['Bin Picking Number'] ?? '',
                        ]
                    );

                    // -----------------------------
                    // SEO
                    // -----------------------------
                    ProductSeo::updateOrCreate(
                        ['store_id' => $this->storeId, 'product_id' => $product->id],
                        [
                            'page_title' => $this->getMappedValue('Page Title', $data) ?? $data['Page Title'] ?? $productName,
                            'product_url' => ltrim($this->getMappedValue('Product Url', $data) ?? $data['Product Url'] ?? '', '/'),
                            'meta_description' => $this->getMappedValue('Meta Description', $data) ?? $data['Meta Description'] ?? '',
                            'meta_keywords' => $this->getMappedValue('Meta Keywords', $data) ?? $data['Meta Keywords'] ?? '',
                            'redirect_old_url' => $this->toBool($this->getMappedValue('Redirect Old URL?', $data) ?? $data['Redirect Old URL?'] ?? 'No'),
                        ]
                    );

                    // -----------------------------
                    // Product Images
                    // -----------------------------
                    foreach ($data as $key => $value) {
                        if (stripos($key, 'Product Image File') !== false && !empty(trim($value))) {
                            $imagePath = trim($value);
                            $isUrl = filter_var($imagePath, FILTER_VALIDATE_URL);
                            $finalPath = $isUrl ? $imagePath : 'product_images/' . ltrim($imagePath, '/');

                            ProductImage::updateOrCreate(
                                ['store_id' => $this->storeId, 'product_id' => $product->id, 'path' => $finalPath],
                                [
                                    'alt_text' => $productName,
                                    'is_primary' => true,
                                ]
                            );
                        }
                    }

                } catch (\Throwable $e) {
                    Log::error("❌ Row $rowNumber: Error during import: " . $e->getMessage());
                    Log::error($e->getTraceAsString());
                }
            }
        }
    }

    // -----------------------------
    // Helpers
    // -----------------------------
    private function getMappedValue($label, $data)
    {
        if (!isset($this->columnMap[$label]) || $this->columnMap[$label] === 'Ignore') {
            return null;
        }
        $mapped = $this->columnMap[$label];
        return isset($data[$mapped]) ? trim($data[$mapped]) : null;
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
            return collect($decoded)->mapWithKeys(fn($value, $key) => [trim(str_replace('"','',$key)) => trim(str_replace('"','',$value))])->toArray();
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
