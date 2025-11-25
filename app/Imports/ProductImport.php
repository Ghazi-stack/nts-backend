<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductExtra;
use App\Models\ProductContent;
use App\Models\ProductIdentifier;
use App\Models\ProductSeo;
use App\Models\ProductImage;

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
    protected $filePath;
    protected $ignoredFields = [];

    protected static $brandCache = [];
    protected static $categoryCache = [];

    public function __construct($storeId, $progressKey, $filePath, $ignoredFields = [])
    {
        $this->storeId = $storeId;
        $this->progressKey = $progressKey;
        $this->filePath = $filePath;

        // Normalize ignored fields for consistent comparison
        $this->ignoredFields = array_map([$this, 'normalizeKey'], $ignoredFields);

        // Memory-safe total rows calculation
        $this->calculateTotalRows();
    }

    public function chunkSize(): int
    {
        return 250; // memory-efficient chunk size
    }

    public function onRow(Row $row)
    {
        $rowIndex = $row->getIndex();
        try {
            $raw = $row->toArray();
            $data = array_change_key_case(array_map('trim', $raw), CASE_LOWER);

            // Cancel check
            if (Cache::get($this->progressKey . "_cancel")) {
                Cache::put($this->progressKey, [
                    'current' => Cache::get($this->progressKey . "_count"),
                    'status' => 'cancelled'
                ]);
                return;
            }

            // --- Detect fields ---
            $sku = $this->getField($data, ['product code/sku','product code / sku','sku','product code']);
            $productName = $this->getField($data, ['product name','name']);

            if (!$sku || !$productName) {
                $this->incrementProgress();
                return;
            }

            // --- Brand ---
            $brandName = $this->getField($data,['brand name','brand','manufacturer']) ?? 'Unknown Brand';
            $brandKey = strtolower($brandName);
            if (!isset(self::$brandCache[$brandKey])) {
                $brand = Brand::firstOrCreate(
                    ['store_id'=>$this->storeId,'name'=>$brandName],
                    ['slug'=>Str::slug($brandName)]
                );
                self::$brandCache[$brandKey] = $brand->id;
            }
            $brandId = self::$brandCache[$brandKey];

            // --- Category ---
            $categoryRaw = $this->getField($data,['category','gps category'],'Uncategorized');
            $categoryId = $this->resolveCategory($categoryRaw);

            // --- Prices & dimensions ---
            $price = (float)($this->getField($data,['price','unit price','product price']) ?? 0);
            $salePrice = (float)($this->getField($data,['sale price','special price','discount price']) ?? 0);
            $costPrice = (float)($this->getField($data,['cost price','cost']) ?? 0);
            $fixedShipping = (float)($this->getField($data,['fixed shipping cost','fixed shipping']) ?? 0);

            $weight = (float)($this->getField($data,['weight','product weight']) ?? 0);
            $width  = (float)($this->getField($data,['width','product width']) ?? 0);
            $height = (float)($this->getField($data,['height','product height']) ?? 0);
            $depth  = (float)($this->getField($data,['depth','product depth']) ?? 0);

            // --- Product ---
            $product = Product::updateOrCreate(
                ['store_id'=>$this->storeId,'sku'=>$sku],
                [
                    'name'=>$productName,
                    'slug'=>Str::slug($productName).'-'.Str::random(6),
                    'brand_id'=>$brandId,
                    'category_id'=>$categoryId,
                    'price'=>$price,
                    'sale_price'=>$salePrice,
                    'cost_price'=>$costPrice,
                    'fixed_shipping_cost'=>$fixedShipping,
                    'dimensions'=>json_encode(['weight'=>$weight,'width'=>$width,'height'=>$height,'depth'=>$depth]),
                    'allow_purchase'=>$this->toBool($this->getField($data,['allow purchases?','allow purchase']) ?? 'Yes'),
                    'track_inventory'=>$this->toBool($this->getField($data,['track inventory']) ?? 'No'),
                    'current_stock'=>(int)($this->getField($data,['current stock level','stock','quantity']) ?? 0),
                    'low_stock'=>(int)($this->getField($data,['low stock level','low stock']) ?? 0),
                ]
            );

            $product->categories()->syncWithoutDetaching([$categoryId]);

            // --- ProductExtra ---
            ProductExtra::updateOrCreate(
                ['product_id'=>$product->id,'store_id'=>$this->storeId],
                [
                    'retail_price'=>(float)($this->getField($data,['retail price','retail_price']) ?? 0),
                    'distributor_name'=>$this->getField($data,['distributor name','distributor_name']) ?? '',
                    'distributor_sku'=>$this->getField($data,['distributor sku','distributor_sku']) ?? '',
                    'event_date_required'=>$this->toBool($this->getField($data,['event date required?']) ?? 'No'),
                ]
            );

            // --- ProductContent ---
            ProductContent::updateOrCreate(
                ['product_id'=>$product->id,'store_id'=>$this->storeId],
                [
                    'description'=>$this->getField($data,['product description','description']) ?? '',
                    'warranty'=>$this->getField($data,['product warranty','warranty']) ?? '',
                    'availability_text'=>$this->getField($data,['product availability','availability']) ?? '',
                    'template_layout'=>'default',
                ]
            );

            // --- ProductIdentifier ---
            ProductIdentifier::updateOrCreate(
                ['product_id'=>$product->id,'store_id'=>$this->storeId],
                [
                    'mpn'=>$this->getField($data,['gps manufacturer part number','mpn']) ?? '',
                    'upc'=>$this->getField($data,['product upc/ean','upc']) ?? '',
                    'gtin'=>$this->getField($data,['gps global trade item number','gtin']) ?? '',
                    'bpn'=>$this->getField($data,['bin picking number','bpn']) ?? '',
                ]
            );

            // --- ProductSeo ---
            ProductSeo::updateOrCreate(
                ['product_id'=>$product->id,'store_id'=>$this->storeId],
                [
                    'page_title'=>$this->getField($data,['page title','meta title']) ?? $productName,
                    'meta_description'=>$this->getField($data,['meta description','meta_description']) ?? '',
                    'meta_keywords'=>$this->getField($data,['meta keywords','meta_keywords']) ?? '',
                ]
            );

            // --- ProductImage ---
            $primarySet = false;
            foreach ($data as $key => $val) {
                if ($val && (str_contains($key,'product image file') || str_contains($key,'image'))) {
                    ProductImage::updateOrCreate(
                        ['product_id'=>$product->id,'path'=>trim($val)],
                        [
                            'store_id'=>$this->storeId,
                            'alt_text'=>$productName,
                            'description'=>$this->getField($data,[str_replace('file','description',$key)]) ?? null,
                            'is_primary'=>$primarySet ? 0 : 1,
                        ]
                    );
                    $primarySet = true;
                }
            }

            // --- Increment progress ---
            $this->incrementProgress();

        } catch (\Throwable $e) {
            Log::error("Import row error #{$rowIndex}", [
                'error'=>$e->getMessage(),
                'trace'=>$e->getTraceAsString()
            ]);
            $this->incrementProgress();
        }
    }

    private function incrementProgress()
    {
        $current = Cache::increment($this->progressKey . "_count");
        $total = Cache::get($this->progressKey . "_total", 0);
        $status = ($current >= $total) ? 'completed' : 'processing';
        Cache::put($this->progressKey, [
            'current'=>$current,
            'total'=>$total,
            'status'=>$status
        ]);
    }

    private function getField(array $data, array $possibleKeys, $default=null)
    {
        foreach ($possibleKeys as $k) {
            $kNorm = $this->normalizeKey($k);
            if (in_array($kNorm, $this->ignoredFields)) continue;
            if (isset($data[$kNorm]) && $data[$kNorm] !== '') return $data[$kNorm];
        }
        return $default;
    }

    private function normalizeKey(string $key): string
    {
        $k = trim($key);
        $k = preg_replace('/[\x00-\x1F\x7F\xA0\x{200B}-\x{200D}\x{FEFF}]/u','',$k);
        $k = str_replace(['\\','/','_'],' ',$k);
        $k = preg_replace('/\s+/',' ',$k);
        return mb_strtolower($k);
    }

    private function resolveCategory($path)
    {
        $segments = array_map('trim', explode('/', $path));
        $parent = null;
        foreach ($segments as $segment) {
            $slug = Str::slug($segment);
            $key = ($parent ?: 0).'_'.$slug;
            if (!isset(self::$categoryCache[$key])) {
                $category = Category::firstOrCreate(
                    ['store_id'=>$this->storeId,'slug'=>$slug,'parent_id'=>$parent],
                    ['name'=>$segment]
                );
                self::$categoryCache[$key] = $category->id;
            }
            $parent = self::$categoryCache[$key];
        }
        return $parent;
    }

    private function toBool($val)
    {
        return in_array(strtolower(trim((string)$val)), ['yes','y','1','true']);
    }

    protected function calculateTotalRows()
    {
        $fileExtension = strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));
        $totalRows = 0;

        if ($fileExtension === 'csv') {
            $totalRows = max(0, count(file($this->filePath)) - 1);
        } elseif ($fileExtension === 'xlsx') {
            $zip = new \ZipArchive;
            if ($zip->open($this->filePath) === true) {
                $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
                preg_match_all('/<row r="(\d+)"/', $xml, $matches);
                $totalRows = max(0, count($matches[1]) - 1);
                $zip->close();
            }
        }

        Cache::put($this->progressKey . "_total", $totalRows, 7200);
    }
}
