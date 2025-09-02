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

    public function __construct($storeId)
    {
        $this->storeId = $storeId;
    }

    public function array(array $rows)
    {
        $header = array_map('trim', $rows[0]);
        unset($rows[0]);

        $this->preloadCategories();
        $this->preloadBrands();

        foreach ($rows as $index => $row) {
            try {
                $data = array_combine($header, $row);

                // ✅ Check required fields
                if (empty($data['Product Code/SKU']) || empty($data['Product Name'])) {
                    Log::warning("❌ Row $index skipped: Missing SKU or Product Name.");
                    continue;
                }

                // ✅ Resolve brand (must already exist)
                $brandName = trim($data['Brand Name'] ?? $data['Brand'] ?? '');
                $brand = $this->existingBrands->get($brandName);

                if (!$brand) {
                    Log::warning("❌ Row $index skipped: Brand '$brandName' not found.");
                    continue;
                }

                // ✅ Resolve category (must already exist)
                $categoryPath = $data['Category'] ?? '';
                $category = $this->findCategoryPath($categoryPath);

                if (!$category) {
                    Log::warning("❌ Row $index skipped: Category path '$categoryPath' not found.");
                    continue;
                }

                // ✅ Generate unique slug
                $slug = Str::slug($data['Product Name']) . '-' . uniqid();

                // ✅ Create product
                $product = Product::create([
                    'sku' => $data['Product Code/SKU'] . '-' . uniqid(), // Ensure uniqueness
                    'store_id' => $this->storeId,
                    'item_type' => $data['Item Type'] ?? null,
                    'product_type' => $data['Product Type'] ?? null,
                    'name' => $data['Product Name'],
                    'slug' => $slug,
                    'price' => $data['Retail Price'] ?? 0,
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'condition' => $data['Product Condition'] ?? null,
                    'dimensions' => [
                        'weight' => $data['Product Weight'] ?? null,
                        'width' => $data['Product Width'] ?? null,
                        'height' => $data['Product Height'] ?? null,
                        'depth' => $data['Product Depth'] ?? null,
                    ],
                    'track_inventory' => $this->toBool($data['Track Inventory'] ?? 'N'),
                    'current_stock' => $data['Current Stock Level'] ?? 0,
                    'low_stock' => $data['Low Stock Level'] ?? 0,
                    'visible_on_storefront' => $this->toBool($data['Product Visible?'] ?? 'Y'),
                    'free_shipping' => $this->toBool($data['Free Shipping'] ?? 'N'),
                    'allow_purchase' => $this->toBool($data['Allow Purchases?'] ?? 'Y'),
                    'option_set' => $data['Option Set'] ?? null,
                    'option_set_align' => $data['Option Set Align'] ?? null,
                    'cost_price' => $data['Cost Price'] ?? null,
                    'sale_price' => $data['Sale Price'] ?? null,
                    'fixed_shipping_cost' => $data['Fixed Shipping Cost'] ?? null,
                    'custom_fields' => $this->parseCustomFields($data['Product Custom Fields'] ?? ''),
                    'is_featured' => false,
                    'show_condition' => $this->toBool($data['Show Product Condition?'] ?? 'Y'),
                    'sort_order' => $data['Sort Order'] ?? 0,
                    'search_keywords' => $data['Search Keywords'] ?? '',
                    'stop_processing_rules' => $this->toBool($data['Stop Processing Rules'] ?? 'N'),
                    'tax_class' => $data['Product Tax Class'] ?? null,
                    'tax_provider_code' => $data['Tax Provider Tax Code'] ?? null,
                    'updated_at' => Carbon::now(),
                    'created_at' => Carbon::now(),
                ]);

                // ✅ Attach product to category
                $product->categories()->syncWithoutDetaching([$category->id]);

                // ✅ Product content
                ProductContent::create([
                    'store_id' => $this->storeId,
                    'product_id' => $product->id,
                    'description' => $data['Product Description'] ?? '',
                    'warranty' => $data['Product Warranty'] ?? '',
                    'availability_text' => $data['Product Availability'] ?? '',
                    'template_layout' => 'default',
                ]);

                // ✅ Product identifiers
                ProductIdentifier::create([
                    'store_id' => $this->storeId,
                    'product_id' => $product->id,
                    'mpn' => $data['Manufacturer Part Number'] ?? '',
                    'upc' => $data['Product UPC/EAN'] ?? '',
                    'gtin' => $data['Global Trade Item Number'] ?? '',
                    'bpn' => $data['Bin Picking Number'] ?? '',
                ]);

                // ✅ Product SEO
                ProductSeo::create([
                    'store_id' => $this->storeId,
                    'product_id' => $product->id,
                    'page_title' => $data['Page Title'] ?? $data['Product Name'] ?? '',
                    'product_url' => ltrim($data['Product Url'] ?? '', '/'),
                    'meta_description' => $data['Meta Description'] ?? '',
                    'meta_keywords' => $data['Meta Keywords'] ?? '',
                    'redirect_old_url' => $this->toBool($data['Redirect Old URL?'] ?? 'N'),
                ]);

                // ✅ Product image (auto-detect from any column)
                $imagePath = collect($data)->map(fn($v) => trim($v))->first(function ($value) {
                    if (!is_string($value)) return false;
                    return preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $value);
                });

                if ($imagePath) {
                    $isUrl = filter_var($imagePath, FILTER_VALIDATE_URL);
                    $finalPath = $isUrl ? $imagePath : 'product_images/' . ltrim($imagePath, '/');

                    ProductImage::create([
                        'store_id' => $this->storeId,
                        'product_id' => $product->id,
                        'path' => $finalPath,
                        'alt_text' => $data['Product Name'],
                        'is_primary' => true,
                    ]);
                }

                Log::info("✅ Row $index: Product '{$product->name}' created successfully.");

            } catch (\Throwable $e) {
                Log::error("❌ Row $index: Error during import");
                Log::error("➡️ Message: " . $e->getMessage());
                Log::error("➡️ Trace: " . $e->getTraceAsString());
            }
        }
    }

    private function preloadCategories()
    {
        $this->existingCategories = Category::where('store_id', $this->storeId)->get();
    }

    private function preloadBrands()
    {
        $this->existingBrands = Brand::where('store_id', $this->storeId)->get()->keyBy('name');
    }

    // 🔍 Only finds existing category paths — no creation
    private function findCategoryPath($path)
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
                return null; // Stop if any segment is missing
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
        return in_array(strtolower(trim($val)), ['yes', 'y', 'true', '1']);
    }
}
