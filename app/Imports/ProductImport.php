<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductSeo;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\ToArray;

class ProductImport implements ToArray
{
    public function array(array $rows)
    {
        $header = array_map('trim', $rows[0]);
        unset($rows[0]);

        foreach ($rows as $row) {
            try {
                $data = array_combine($header, $row);

                $brand = Brand::firstOrCreate(
                    ['name' => $data['Brand Name']],
                    ['slug' => Str::slug($data['Brand Name'])]
                );

                $category = Category::firstOrCreate(
                    ['name' => $data['Category'] ?? 'Uncategorized'],
                    ['slug' => Str::slug($data['Category'] ?? 'Uncategorized')]
                );

                $product = Product::create([
                    'item_type' => $data['Item Type'] ?? null,
                    // 'id' => $data['Product ID'] ?? null,
                    'product_type' => $data['Product Type'] ?? null,
                    'name' => $data['Product Name'],
                    'slug' => Str::slug($data['Product Name']) . '-' . uniqid(),
                    'sku' => $data['Product Code/SKU'],
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
                    'product_type' => $data['Product Type'] ?? null,
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

                ProductContent::create([
                    'product_id' => $product->id,
                    'description' => $data['Product Description'] ?? '',
                    'warranty' => $data['Product Warranty'] ?? '',
                    'availability_text' => $data['Product Availability'] ?? '',
                    'template_layout' => 'default',
                ]);

                ProductIdentifier::create([
                    'product_id' => $product->id,
                    'mpn' => $data['Manufacturer Part Number'] ?? '',
                    'upc' => $data['Product UPC/EAN'] ?? '',
                    'gtin' => $data['Global Trade Item Number'] ?? '',
                    'bpn' => $data['Bin Picking Number'] ?? '',
                ]);

                ProductSeo::create([
                    'product_id' => $product->id,
                    'page_title' => $data['Page Title'] ?? $data['Product Name'],
                    'product_url' => ltrim($data['Product URL'] ?? '', '/'),
                    'meta_description' => $data['Meta Description'] ?? '',
                    'meta_keywords' => $data['Meta Keywords'] ?? '',
                    'redirect_old_url' => $this->toBool($data['Redirect Old URL?'] ?? 'N'),
                ]);

                // Primary image
                if (!empty($data['Product Image File - 1'])) {
                    ProductImage::create([
                        'product_id' => $product->id,
                        'path' => $data['Product Image File - 1'],
                        'alt_text' => $data['Product Image Description - 1'] ?? '',
                        'is_primary' => true,
                    ]);
                }

                // Additional images can be added similarly...

            } catch (\Throwable $e) {
                \Log::error("Import error: " . $e->getMessage());
            }
        }
    }

    private function parseCustomFields($fieldString)
    {
       if (!$fieldString) return [];

        // Try decoding JSON safely (escaped JSON case)
        $decoded = json_decode($fieldString, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return collect($decoded)->mapWithKeys(function ($value, $key) {
                return [
                    trim(str_replace('"', '', $key)) => trim(str_replace('"', '', $value))
                ];
            })->toArray();
        }

        // Fallback: handle "key=value;key2=value2" format
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
