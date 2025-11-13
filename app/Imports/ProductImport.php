<?php

namespace App\Imports;

use Carbon\Carbon;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Category;
use App\Models\ProductSeo;
use Maatwebsite\Excel\Row;
use Illuminate\Support\Str;
use App\Models\ProductExtra;
use App\Models\ProductImage;
use App\Models\ProductContent;
use App\Models\ProductIdentifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class ProductImport implements OnEachRow, WithChunkReading
{
    protected $storeId;
    protected $columnMap;
    protected $dynamicHeaders = [];
    protected $existingBrands;
    protected $existingCategories;
    protected $progressKey;
    protected $totalRows;
    protected $currentRow = 0;

    public function __construct($storeId, $columnMap = [], $filePath = null, $progressKey = null)
    {
        $this->progressKey = $progressKey;
        $this->storeId = $storeId;
        $this->columnMap = $columnMap;

        $this->preloadBrands();
        $this->preloadCategories();

        if ($filePath && file_exists($filePath)) {
            $this->totalRows = $this->getExcelRowCount($filePath);
        } else {
            $this->totalRows = 1000;
        }
    }

    public function onRow(Row $row)
    {
        $this->currentRow++;

        if ($this->progressKey && $this->currentRow % $this->chunkSize() === 0) {
            Cache::put($this->progressKey, [
                'current' => $this->currentRow,
                'total' => $this->totalRows,
                'status' => 'processing'
            ], 3600);
        }

        $rowIndex = $row->getIndex();
        $data = $row->toArray();

        if ($rowIndex === 1) {
            $this->dynamicHeaders = array_map('trim', $data);
            return;
        }

        // Map row data
        $mappedData = [];
        foreach ($this->dynamicHeaders as $i => $header) {
            $mappedData[$header] = $data[$i] ?? null;
        }

        $now = Carbon::now();

        try {
            $sku = $this->getMappedValue('Product Code/SKU', $mappedData) ?? $mappedData['Product Code/SKU'] ?? null;
            $productName = $this->getMappedValue('Product Name', $mappedData) ?? $mappedData['Product Name'] ?? null;

            if (!$sku || !$productName) {
                Log::warning("❌ Row $rowIndex skipped: Missing SKU or Product Name.");
                return;
            }

            // Brand
            $brandName = $this->getMappedValue('Brand Name', $mappedData) ?? 'Unknown Brand';
            $brandKey = strtolower($brandName);
            $brand = $this->existingBrands->get($brandKey);
            if (!$brand) {
                $brand = Brand::create([
                    'store_id' => $this->storeId,
                    'name' => $brandName,
                    'slug' => Str::slug($brandName),
                ]);
                $this->existingBrands->put($brandKey, $brand);
            }

            // Category
            $categoryPath = $this->getMappedValue('Category', $mappedData) ?? $mappedData['Category'] ?? 'Uncategorized';
            $category = $this->findOrCreateCategoryPath($categoryPath);

            // Main product
            $product = Product::firstOrNew([
                'store_id' => $this->storeId,
                'sku' => $sku,
            ]);
            $product->fill([
                'name' => $productName,
                'slug' => $product->slug ?? Str::slug($productName) . '-' . Str::uuid(),
                'item_type' => $this->getMappedValue('Item Type', $mappedData),
                'product_type' => $this->getMappedValue('Product Type', $mappedData),
                'price' => (float) ($this->getMappedValue('Price', $mappedData) ?? 0),
                'brand_id' => $brand->id,
                'category_id' => $category->id,
                'condition' => $this->getMappedValue('Product Condition', $mappedData) ?? 'New',
               'dimensions' => json_encode([
                        'weight' => isset($mappedData['Product Weight']) && is_numeric($mappedData['Product Weight'])
                            ? (float) $mappedData['Product Weight']
                            : 0,
                        'width' => isset($mappedData['Product Width']) && is_numeric($mappedData['Product Width'])
                            ? (float) $mappedData['Product Width']
                            : 0,
                        'height' => isset($mappedData['Product Height']) && is_numeric($mappedData['Product Height'])
                            ? (float) $mappedData['Product Height']
                            : 0,
                        'depth' => isset($mappedData['Product Depth']) && is_numeric($mappedData['Product Depth'])
                            ? (float) $mappedData['Product Depth']
                            : 0,
                    ]),
                'track_inventory' => $this->toBool($this->getMappedValue('Track Inventory', $mappedData) ?? 'No'),
                'current_stock' => (int) ($mappedData['Current Stock Level'] ?? 0),
                'low_stock' => (int) ($mappedData['Low Stock Level'] ?? 0),
                'visible_on_storefront' => $this->toBool($this->getMappedValue('Product Visible?', $mappedData) ?? 'Yes'),
                'allow_purchase' => $this->toBool($this->getMappedValue('Allow Purchases?', $mappedData) ?? 'Yes'),
                'free_shipping' => $this->toBool($this->getMappedValue('Free Shipping', $mappedData) ?? 'No'),
                'cost_price' => (float) ($this->getMappedValue('Cost Price', $mappedData) ?? 0),
                'sale_price' => (float) ($this->getMappedValue('Sale Price', $mappedData) ?? 0),
                'fixed_shipping_cost' => (float) ($this->getMappedValue('Fixed Shipping Cost', $mappedData) ?? 0),
                'custom_fields' => json_encode($this->parseCustomFields($this->getMappedValue('Product Custom Fields', $mappedData))),
                'show_condition' => $this->toBool($this->getMappedValue('Show Product Condition?', $mappedData) ?? 'Yes'),
                'sort_order' => (int) ($this->getMappedValue('Sort Order', $mappedData) ?? 0),
                'search_keywords' => $this->getMappedValue('Search Keywords', $mappedData) ?? '',
                'stop_processing_rules' => $this->toBool($this->getMappedValue('Stop Processing Rules', $mappedData) ?? 'No'),
                'tax_class' => $this->getMappedValue('Product Tax Class', $mappedData),
                'tax_provider_code' => $this->getMappedValue('Tax Provider Tax Code', $mappedData),
                'updated_at' => $now,
                'created_at' => $product->created_at ?? $now,
            ]);
            $product->save();

            // Extra fields
            ProductExtra::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $this->storeId],
                [
                    'retail_price' => (float) ($this->getMappedValue('Retail Price', $mappedData) ?? 0),
                    'distributor_name' => $this->getMappedValue('Distributor Name', $mappedData),
                    'distributor_sku' => $this->getMappedValue('Distributor SKU', $mappedData),
                    'event_date_required' => $this->toBool($this->getMappedValue('Event Date Required?', $mappedData)),
                    'gps_gender' => $this->getMappedValue('GPS Gender', $mappedData),
                    'gps_age_group' => $this->getMappedValue('GPS Age Group', $mappedData),
                    'gps_enabled' => $this->toBool($this->getMappedValue('GPS Enabled', $mappedData)),
                    'myob_asset_acct' => $this->getMappedValue('MYOB Asset Acct', $mappedData),
                    'myob_income_acct' => $this->getMappedValue('MYOB Income Acct', $mappedData),
                    'myob_expense_acct' => $this->getMappedValue('MYOB Expense Acct', $mappedData),
                ]
            );

            // Categories relation
            $product->categories()->syncWithoutDetaching([$category->id]);

            // ProductContent
            ProductContent::updateOrCreate(
                ['store_id' => $this->storeId, 'product_id' => $product->id],
                [
                    'description' => $this->getMappedValue('Product Description', $mappedData) ?? '',
                    'warranty' => $this->getMappedValue('Product Warranty', $mappedData) ?? '',
                    'availability_text' => $this->getMappedValue('Product Availability', $mappedData) ?? '',
                    'template_layout' => 'default',
                ]
            );

            // Product Identifier
            ProductIdentifier::updateOrCreate(
                ['store_id' => $this->storeId, 'product_id' => $product->id],
                [
                    'mpn' => $this->getMappedValue('GPS Manufacturer Part Number', $mappedData) ?? '',
                    'upc' => $this->getMappedValue('Product UPC/EAN', $mappedData) ?? '',
                    'gtin' => $this->getMappedValue('GPS Global Trade Item Number', $mappedData) ?? '',
                    'bpn' => $this->getMappedValue('Bin Picking Number', $mappedData) ?? '',
                ]
            );

            // SEO
            ProductSeo::updateOrCreate(
                ['store_id' => $this->storeId, 'product_id' => $product->id],
                [
                    'page_title' => $this->getMappedValue('Page Title', $mappedData) ?? $productName,
                    'meta_description' => $this->getMappedValue('Meta Description', $mappedData) ?? '',
                    'meta_keywords' => $this->getMappedValue('Meta Keywords', $mappedData) ?? '',
                ]
            );

          // Images
        $primarySet = false;

        foreach ($mappedData as $key => $value) {
            if (stripos($key, 'Product Image File') !== false && !empty(trim($value))) {
                $imagePath = trim($value);
            
                // Get corresponding description column
                $descKey = str_replace('File', 'Description', $key);
                $description = $mappedData[$descKey] ?? null;
            
                ProductImage::updateOrCreate(
                    [
                        'store_id' => $this->storeId,
                        'product_id' => $product->id,
                        'path' => $imagePath
                    ],
                    [
                        'alt_text' => $productName,
                        'is_primary' => !$primarySet ? 1 : 0,
                        'description' => $description
                    ]
                );
            
                if (!$primarySet) {
                    $primarySet = true; // mark first image as primary
                }
            }
        }

        } catch (\Throwable $e) {
            Log::error("❌ Row $rowIndex: " . $e->getMessage());
        }
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function __destruct()
    {
        if ($this->progressKey) {
            Cache::put($this->progressKey, [
                'current' => $this->totalRows,
                'total' => $this->totalRows,
                'status' => 'finished'
            ]);
        }
    }

    // -----------------------------
    // Helpers
    // -----------------------------
    private function getMappedValue($label, $data)
    {
        if (!isset($this->columnMap[$label]) || $this->columnMap[$label] === 'Ignore' || $label === 'Product ID') {
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

            $category = $this->existingCategories->first(fn($cat) => $cat->slug === $slug && $cat->parent_id == $parentId);

            if (!$category) {
                $category = Category::create([
                    'store_id' => $this->storeId,
                    'name' => $segment,
                    'slug' => $slug,
                    'parent_id' => $parentId,
                ]);
                $this->existingCategories->push($category);
            }

            $parentId = $category->id;
        }

        return $category;
    }

    private function parseCustomFields($fieldString)
    {
        if (!$fieldString) return [];

        $fields = [];
        foreach (explode(';', $fieldString) as $pair) {
            if (strpos($pair, '=') !== false) {
                [$key, $value] = explode('=', trim($pair));
                $fields[trim($key)] = trim($value);
            }
        }
        return $fields;
    }

    private function toBool($val)
    {
        return in_array(strtolower(trim((string)$val)), ['yes','y','true','1']);
    }

    public function getExcelRowCount($filePath)
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            return max(1, $highestRow - 1);
        } catch (\Throwable $e) {
            Log::warning("Unable to count Excel rows: " . $e->getMessage());
            return 1000;
        }
    }
}
