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

        if ($this->progressKey && Cache::get($this->progressKey.'_cancel')) {
            Cache::put($this->progressKey, [
                'current' => $this->currentRow,
                'total' => $this->totalRows,
                'status' => 'cancelled',
            ], 3600);
             Log::info("Product import cancelled by user. Progress key: {$this->progressKey}, stopped at row: {$this->currentRow}");

             throw new \Exception('Import cancelled by user.');
        }

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
            $this->dynamicHeaders = array_map(function ($h) {
                return $this->normalizeHeader($h);
            }, $data);
            return;
        }

        $mappedData = [];
        foreach ($this->dynamicHeaders as $i => $header) {
            $mappedData[$header] = $data[$i] ?? null;
        }

        try {
            $sku = $mappedData['product code/sku'] ?? null;
            $productName = $mappedData['product name'] ?? null;

            if (!$sku || !$productName) {
                return;
            }

            $brandName = $mappedData['brand name'] ?? 'Unknown Brand';
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

            $categoryPath = $mappedData['category'] ?? 'Uncategorized';
            $category = $this->findOrCreateCategoryPath($categoryPath);

            $product = Product::firstOrNew([
                'store_id' => $this->storeId,
                'sku' => $sku,
            ]);

            $product->fill([
                'name' => $productName,
                'slug' => $product->slug ?: Str::slug($productName) . '-' . Str::uuid(),

                'item_type' => $mappedData['item type'] ?? null,
                'product_type' => $mappedData['product type'] ?? null,

                'price' => (float) ($mappedData['price'] ?? 0),
                'cost_price' => (float) ($mappedData['cost price'] ?? 0),
                'sale_price' => (float) ($mappedData['sale price'] ?? 0),
                'fixed_shipping_cost' => (float) ($mappedData['fixed shipping cost'] ?? 0),

                'brand_id' => $brand->id,
                'category_id' => $category->id,

                'condition' => $mappedData['product condition'] ?? 'New',

                'dimensions' => json_encode([
                    'weight' => (float) ($mappedData['product weight'] ?? 0),
                    'width'  => (float) ($mappedData['product width'] ?? 0),
                    'height' => (float) ($mappedData['product height'] ?? 0),
                    'depth'  => (float) ($mappedData['product depth'] ?? 0),
                ]),

                'track_inventory' => $this->toBool($mappedData['track inventory'] ?? 'No'),
                'current_stock' => (int) ($mappedData['current stock level'] ?? 0),
                'low_stock' => (int) ($mappedData['low stock level'] ?? 0),

                'visible_on_storefront' => $this->toBool($mappedData['product visible?'] ?? 'Yes'),
                'allow_purchase' => $this->toBool($mappedData['allow purchases?'] ?? 'Yes'),
                'free_shipping' => $this->toBool($mappedData['free shipping'] ?? 'No'),

                'custom_fields' => json_encode($this->parseCustomFields($mappedData['product custom fields'] ?? '')),

                'show_condition' => $this->toBool($mappedData['show product condition?'] ?? 'Yes'),

                'sort_order' => (int) ($mappedData['sort order'] ?? 0),

                'search_keywords' => $mappedData['search keywords'] ?? '',
                'stop_processing_rules' => $this->toBool($mappedData['stop processing rules'] ?? 'No'),

                'tax_class' => $mappedData['product tax class'] ?? null,
                'tax_provider_code' => $mappedData['tax provider tax code'] ?? null,

                'updated_at' => now(),
                'created_at' => $product->created_at ?? now(),
            ]);

            $product->save();

            ProductExtra::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $this->storeId],
                [
                    'retail_price' => (float) ($mappedData['retail price'] ?? 0),
                    'distributor_name' => $mappedData['distributor name'] ?? null,
                    'distributor_sku' => $mappedData['distributor sku'] ?? null,

                    'event_date_required' => $this->toBool($mappedData['event date required?'] ?? 'No'),

                    'gps_gender' => $mappedData['gps gender'] ?? null,
                    'gps_age_group' => $mappedData['gps age group'] ?? null,
                    'gps_enabled' => $this->toBool($mappedData['gps enabled'] ?? 'No'),

                    'myob_asset_acct' => $mappedData['myob asset acct'] ?? null,
                    'myob_income_acct' => $mappedData['myob income acct'] ?? null,
                    'myob_expense_acct' => $mappedData['myob expense acct'] ?? null,
                ]
            );

            $product->categories()->syncWithoutDetaching([$category->id]);

            ProductContent::updateOrCreate(
                ['store_id' => $this->storeId, 'product_id' => $product->id],
                [
                    'description' => $mappedData['product description'] ?? '',
                    'warranty' => $mappedData['product warranty'] ?? '',
                    'availability_text' => $mappedData['product availability'] ?? '',
                    'template_layout' => 'default',
                ]
            );

            ProductIdentifier::updateOrCreate(
                ['store_id' => $this->storeId, 'product_id' => $product->id],
                [
                    'mpn' => $mappedData['gps manufacturer part number'] ?? '',
                    'upc' => $mappedData['product upc/ean'] ?? '',
                    'gtin' => $mappedData['gps global trade item number'] ?? '',
                    'bpn' => $mappedData['bin picking number'] ?? '',
                ]
            );

            ProductSeo::updateOrCreate(
                ['store_id' => $this->storeId, 'product_id' => $product->id],
                [
                    'page_title' => $mappedData['page title'] ?? $productName,
                    'meta_description' => $mappedData['meta description'] ?? '',
                    'meta_keywords' => $mappedData['meta keywords'] ?? '',
                ]
            );

            $primarySet = false;
            foreach ($mappedData as $key => $value) {
                if (strpos($key, 'product image file') !== false && !empty(trim($value))) {
                    $imagePath = trim($value);
                    $descKey = str_replace('file', 'description', $key);
                    $description = $mappedData[$descKey] ?? null;

                    ProductImage::updateOrCreate(
                        [
                            'store_id' => $this->storeId,
                            'product_id' => $product->id,
                            'path' => $imagePath
                        ],
                        [
                            'alt_text' => $productName,
                            'is_primary' => $primarySet ? 0 : 1,
                            'description' => $description
                        ]
                    );

                    if (!$primarySet) {
                        $primarySet = true;
                    }
                }
            }

        } catch (\Throwable $e) {
            // Errors silently ignored
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

    private function getMappedValue($label, $data)
    {
        if (!isset($this->columnMap[$label]) || $this->columnMap[$label] === 'Ignore' || $label === 'Product ID') {
            return null;
        }

        $mapped = strtolower(trim($this->columnMap[$label]));
        $mapped = preg_replace('/\s+/', ' ', $mapped);

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
            return 1000;
        }
    }

    private function normalizeHeader($header)
    {
        $header = preg_replace('/\x{00A0}|\x{200B}/u', '', $header);
        $header = preg_replace('/\s+/', ' ', trim($header));
        return strtolower($header);
    }
}
