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
    protected $columnMapping;
    protected $existingCategories;
    protected $existingBrands;

    public function __construct($storeId, array $columnMapping)
    {
        $this->storeId = $storeId;
        $this->columnMapping = $columnMapping;
    }

    public function array(array $rows)
    {
        if (empty($rows) || !isset($rows[0])) {
            Log::warning("❌ Import aborted: No rows found.");
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
                $data = array_combine($header, $row);
                if (!$data) {
                    Log::warning("❌ Row $rowNumber skipped: Invalid row structure.");
                    continue;
                }

                $sku = $this->getMappedValue($data, 'Product Code/SKU');
                $productName = $this->getMappedValue($data, 'Product Name');

                if (empty($sku) || empty($productName)) {
                    Log::warning("❌ Row $rowNumber skipped: Missing SKU or Product Name.");
                    continue;
                }

                // ✅ Brand & Distributor Logic
                $distributorName = $this->getMappedValue($data, 'Distributor Name');
                $rawBrandName = $this->getMappedValue($data, 'Brand Name');

                $brandName = '';
                if (!empty($distributorName) && (strcasecmp($distributorName, $rawBrandName) === 0 || empty($rawBrandName))) {
                    $brandName = $distributorName;
                } elseif (!empty($rawBrandName)) {
                    $brandName = $rawBrandName;
                }

                if (empty($brandName)) {
                    Log::warning("❌ Row $rowNumber skipped: Brand missing.");
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
                $categoryPath = $this->getMappedValue($data, 'GPS Category');
                $category = $this->findOrCreateCategoryPath($categoryPath);
                if (!$category) {
                    Log::warning("❌ Row $rowNumber skipped: Category '$categoryPath' not resolved.");
                    continue;
                }

                // ✅ Existing product check (exact match)
                $product = $this->findExistingProduct($sku);

                $customFields = $this->parseCustomFields($this->getMappedValue($data, 'Product Custom Fields'));

                if (!empty($distributorName) && strcasecmp($distributorName, $brandName) !== 0) {
                    $customFields['distributor_name'] = $distributorName;
                }

                $baseFields = [
                    'item_type' => $this->getMappedValue($data, 'Item Type'),
                    'product_type' => $this->getMappedValue($data, 'Product Type'),
                    'name' => $productName,
                    'price' => $this->getMappedValue($data, 'Retail Price') ?? $this->getMappedValue($data, 'Price') ?? 0,
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'condition' => $this->getMappedValue($data, 'Product Condition'),
                    'dimensions' => [
                        'weight' => $this->getMappedValue($data, 'Product Weight'),
                        'width' => $this->getMappedValue($data, 'Product Width'),
                        'height' => $this->getMappedValue($data, 'Product Height'),
                        'depth' => $this->getMappedValue($data, 'Product Depth'),
                    ],
                    'track_inventory' => $this->toBool($this->getMappedValue($data, 'Track Inventory')),
                    'current_stock' => $this->getMappedValue($data, 'Current Stock Level') ?? 0,
                    'low_stock' => $this->getMappedValue($data, 'Low Stock Level') ?? 0,
                    'visible_on_storefront' => $this->toBool($this->getMappedValue($data, 'Product Visible?')),
                    'free_shipping' => $this->toBool($this->getMappedValue($data, 'Free Shipping')),
                    'allow_purchase' => $this->toBool($this->getMappedValue($data, 'Allow Purchases?')),
                    'option_set' => $this->getMappedValue($data, 'Option Set'),
                    'option_set_align' => $this->getMappedValue($data, 'Option Set Align'),
                    'cost_price' => $this->getMappedValue($data, 'Cost Price'),
                    'sale_price' => $this->getMappedValue($data, 'Sale Price'),
                    'fixed_shipping_cost' => $this->getMappedValue($data, 'Fixed Shipping Cost'),
                    'custom_fields' => $customFields,
                    'show_condition' => $this->toBool($this->getMappedValue($data, 'Show Product Condition?')),
                    'sort_order' => $this->getMappedValue($data, 'Sort Order') ?? 0,
                    'search_keywords' => $this->getMappedValue($data, 'Search Keywords'),
                    'stop_processing_rules' => $this->toBool($this->getMappedValue($data, 'Stop Processing Rules')),
                    'tax_class' => $this->getMappedValue($data, 'Product Tax Class'),
                    'tax_provider_code' => $this->getMappedValue($data, 'Tax Provider Tax Code'),
                ];

                if ($product) {
                    $product->update($baseFields);
                    Log::info("🔁 Row $rowNumber: Product '{$product->name}' updated.");
                } else {
                    $product = Product::create(array_merge($baseFields, [
                        'sku' => $sku,
                        'store_id' => $this->storeId,
                        'slug' => Str::slug($productName) . '-' . Str::uuid(),
                        'is_featured' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));

                    Log::info("✅ Row $rowNumber: Product '{$product->name}' created.");
                }

                // ✅ Content
                ProductContent::updateOrCreate(
                    ['store_id' => $this->storeId, 'product_id' => $product->id],
                    [
                        'description' => $this->getMappedValue($data, 'Product Description'),
                        'warranty' => $this->getMappedValue($data, 'Product Warranty'),
                        'availability_text' => $this->getMappedValue($data, 'Product Availability'),
                        'template_layout' => 'default',
                    ]
                );

                // ✅ Identifiers
                ProductIdentifier::updateOrCreate(
                    ['store_id' => $this->storeId, 'product_id' => $product->id],
                    [
                        'mpn' => $this->getMappedValue($data, 'GPS Manufacturer Part Number'),
                        'gtin' => $this->getMappedValue($data, 'GPS Global Trade Item Number'),
                        'bpn' => $this->getMappedValue($data, 'Bin Picking Number'),
                    ]
                );

                // ✅ SEO
                ProductSeo::updateOrCreate(
                    ['store_id' => $this->storeId, 'product_id' => $product->id],
                    [
                        'page_title' => $this->getMappedValue($data, 'Page Title') ?? $productName,
                        'product_url' => ltrim($this->getMappedValue($data, 'Product Url'), '/'),
                        'meta_description' => $this->getMappedValue($data, 'Meta Description'),
                        'meta_keywords' => $this->getMappedValue($data, 'Meta Keywords'),
                        'redirect_old_url' => $this->toBool($this->getMappedValue($data, 'Redirect Old URL?')),
                    ]
                );

            } catch (\Throwable $e) {
                Log::error("❌ Row $rowNumber: Error");
                Log::error("➡️ " . $e->getMessage());
            }
        }
    }

    private function getMappedValue(array $data, string $fieldKey)
    {
        $column = $this->columnMapping[$fieldKey] ?? null;
        if (!$column || strtolower($column) === 'ignore') {
            return null;
        }

        return trim($data[$column] ?? '');
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

    private function findExistingProduct($sku)
    {
        return Product::where('store_id', $this->storeId)
            ->where('sku', $sku) // ✅ exact match now
            ->first();
    }

    private function parseCustomFields($fieldString)
    {
        if (!$fieldString) return [];

        $decoded = json_decode($fieldString, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return collect($decoded)->mapWithKeys(function ($value, $key) {
                return [trim($key) => trim($value)];
            })->toArray();
        }

        $fields = [];
        foreach (explode(';', $fieldString) as $pair) {
            if (strpos($pair, '=') !== false) {
                [$key, $value] = explode('=', $pair, 2);
                $fields[trim($key)] = trim($value);
            }
        }

        return $fields;
    }

    private function toBool($val)
    {
        return in_array(strtolower(trim((string)$val)), ['yes', 'y', 'true', '1']);
    }
}
