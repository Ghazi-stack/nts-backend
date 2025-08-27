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

    public function __construct($storeId)
    {
        $this->storeId = $storeId;
    }

    public function array(array $rows)
    {
        $header = array_map('trim', $rows[0]);
        unset($rows[0]);

        foreach ($rows as $row) {
            try {
                $data = array_combine($header, $row);

                $brandName = $data['Brand Name'] ?? $data['Brand'] ?? 'Unknown Brand';
                $brand = Brand::firstOrCreate(
                    ['name' => $brandName, 'store_id' => $this->storeId],
                    ['slug' => Str::slug($brandName)]
                );

                // ✅ Handle full category path like Android/Phone/iPhone/16 Promax
                $categoryPath = $data['Category'] ?? 'Uncategorized';
                $category = $this->resolveCategoryPath($categoryPath);

                $product = Product::create([
                    'store_id' => $this->storeId,
                    'item_type' => $data['Item Type'] ?? null,
                    'product_type' => $data['Product Type'] ?? null,
                    'name' => $data['Product Name'] ?? 'Unnamed',
                    'slug' => Str::slug($data['Product Name'] ?? 'Unnamed') . '-' . uniqid(),
                    'sku' => $data['Product Code/SKU'] ?? null,
                    'price' => $data['Retail Price'] ?? 0,
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'condition' => $data['Product Condition'] ?? null,
                    'dimensions' => [
                        'weight' => $data['Product Weight'] ?? null,
                        'width'  => $data['Product Width'] ?? null,
                        'height' => $data['Product Height'] ?? null,
                        'depth'  => $data['Product Depth'] ?? null,
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
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                $product->categories()->syncWithoutDetaching([$category->id]);

                ProductContent::create([
                    'store_id' => $this->storeId,
                    'product_id' => $product->id,
                    'description' => $data['Product Description'] ?? '',
                    'warranty' => $data['Product Warranty'] ?? '',
                    'availability_text' => $data['Product Availability'] ?? '',
                    'template_layout' => 'default',
                ]);

                ProductIdentifier::create([
                    'store_id' => $this->storeId,
                    'product_id' => $product->id,
                    'mpn' => $data['Manufacturer Part Number'] ?? '',
                    'upc' => $data['Product UPC/EAN'] ?? '',
                    'gtin' => $data['Global Trade Item Number'] ?? '',
                    'bpn' => $data['Bin Picking Number'] ?? '',
                ]);

                ProductSeo::create([
                    'store_id' => $this->storeId,
                    'product_id' => $product->id,
                    'page_title' => $data['Page Title'] ?? $data['Product Name'] ?? '',
                    'product_url' => ltrim($data['Product Url'] ?? '', '/'),
                    'meta_description' => $data['Meta Description'] ?? '',
                    'meta_keywords' => $data['Meta Keywords'] ?? '',
                    'redirect_old_url' => $this->toBool($data['Redirect Old URL?'] ?? 'N'),
                ]);

                // ✅ Auto-detect image
                $imagePath = collect($data)->map(fn($v) => trim($v))->first(function ($value) {
                    if (!is_string($value)) return false;
                    return preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $value);
                });

                if ($imagePath) {
                    $isUrl = filter_var($imagePath, FILTER_VALIDATE_URL);
                    $finalPath = $isUrl ? $imagePath : 'product_images/' . ltrim($imagePath, '/');

                    Log::info("Image path detected for SKU {$data['Product Code/SKU']}: {$finalPath}");

                    ProductImage::create([
                        'store_id' => $this->storeId,
                        'product_id' => $product->id,
                        'path' => $finalPath,
                        'alt_text' => $data['Product Name'] ?? '',
                        'is_primary' => true,
                    ]);
                } else {
                    Log::info("No image found for SKU: {$data['Product Code/SKU']}");
                }

            } catch (\Throwable $e) {
                Log::error("Import error for row: " . json_encode($row) . ' — ' . $e->getMessage());
            }
        }
    }

    private function resolveCategoryPath($path)
    {
        // ✅ Split by `/` (forward slash)
        $segments = array_map('trim', explode('/', $path));
        $parentId = null;
        $category = null;

        foreach ($segments as $segment) {
            if (!$segment) continue;

            $slug = Str::slug($segment);

            $category = Category::firstOrCreate(
                [
                    'store_id' => $this->storeId,
                    'slug' => $slug,
                    'parent_id' => $parentId,
                ],
                [
                    'name' => $segment,
                    'is_visible' => true,
                ]
            );

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
