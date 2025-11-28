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
        $this->ignoredFields = array_map('strtolower', $ignoredFields);
        // Removed calculateTotalRows() from constructor
    }

    public function chunkSize(): int
    {
        return 250;
    }

    public function onRow(Row $row)
    {
        $rowIndex = $row->getIndex();

        try {
            $raw = $row->toArray();
            $data = array_change_key_case(array_map('trim', $raw), CASE_LOWER);

            if (Cache::get($this->progressKey . "_cancel")) {
                Cache::put($this->progressKey, [
                    'current' => Cache::get($this->progressKey . "_count"),
                    'status'  => 'cancelled'
                ]);
                return;
            }

            $sku = $this->getField($data, [
                'product code/sku', 'product code / sku', 'product code sku',
                'product code', 'sku', 'product_code', 'productcode',
                'distributor sku', 'distributor_sku'
            ]);

            $productName = $this->getField($data, [
                'product name', 'name', 'product_name', 'productname'
            ]);

            if (!$sku || !$productName) {
                Cache::increment($this->progressKey . "_count");

                $current = Cache::get($this->progressKey . "_count");
                $total   = Cache::get($this->progressKey . "_total");

                $status = ($current >= $total) ? 'completed' : 'processing';

                Cache::put($this->progressKey, [
                    'current' => $current,
                    'total'   => $total,
                    'status'  => $status
                ]);
                return;
            }

            // Brand
            $brandName = $this->getField($data, ['brand name', 'brand', 'manufacturer']) ?? 'Unknown Brand';
            $brandKey = strtolower($brandName);
            if (!isset(self::$brandCache[$brandKey])) {
                $brand = Brand::firstOrCreate(
                    ['store_id' => $this->storeId, 'name' => $brandName],
                    ['slug' => Str::slug($brandName)]
                );
                self::$brandCache[$brandKey] = $brand->id;
            }
            $brandId = self::$brandCache[$brandKey];

            // Category
            $categoryRaw = $this->getField($data, ['category', 'gps category', 'gps_category'], 'Uncategorized');
            $categoryId = $this->resolveCategory($categoryRaw);

            // Prices and dimensions
            $price        = (float)($this->getField($data, ['price', 'unit price', 'product price']) ?? 0);
            $salePrice    = (float)($this->getField($data, ['sale price', 'special price', 'discount price']) ?? 0);
            $costPrice    = (float)($this->getField($data, ['cost price', 'cost']) ?? 0);
            $fixedShipping= (float)($this->getField($data, ['fixed shipping cost', 'fixed shipping']) ?? 0);

            $weight = (float)($this->getField($data, ['weight', 'product weight', 'product_weight']) ?? 0);
            $width  = (float)($this->getField($data, ['width', 'product width', 'product_width']) ?? 0);
            $height = (float)($this->getField($data, ['height', 'product height', 'product_height']) ?? 0);
            $depth  = (float)($this->getField($data, ['depth', 'product depth', 'product_depth']) ?? 0);

            // Create / update product
            $product = Product::updateOrCreate(
                ['store_id' => $this->storeId, 'sku' => $sku],
                [
                    'name' => $productName,
                    'slug' => Str::slug($productName) . '-' . Str::random(6),
                    'brand_id' => $brandId,
                    'category_id' => $categoryId,
                    'price' => $price,
                    'sale_price' => $salePrice,
                    'cost_price' => $costPrice,
                    'fixed_shipping_cost' => $fixedShipping,
                    'dimensions' => json_encode(['weight'=>$weight,'width'=>$width,'height'=>$height,'depth'=>$depth]),
                    'allow_purchase' => $this->toBool($this->getField($data,['allow purchases?','allow purchase','allow_purchase']) ?? 'Yes'),
                    'track_inventory'=> $this->toBool($this->getField($data,['track inventory','track_inventory']) ?? 'No'),
                    'current_stock'=> (int)($this->getField($data,['current stock level','stock','quantity']) ?? 0),
                    'low_stock'=> (int)($this->getField($data,['low stock level','low stock']) ?? 0),
                    'item_type' => $this->getField($data,['item type','item_type']) ?? null,
                    'product_type' => $this->getField($data,['product type','product_type']) ?? null,
                    'option_set' => $this->getField($data,['option set','option_set']) ?? null,
                    'option_set_align' => $this->getField($data,['option set align','option_set_align']) ?? null,
                    'visible_on_storefront' => $this->toBool($this->getField($data,['product visible?','visible']) ?? 'Yes'),
                    'free_shipping' => $this->toBool($this->getField($data,['free shipping','free_shipping']) ?? 'No'),
                    'search_keywords' => $this->getField($data,['search keywords','keywords']) ?? null,
                ]
            );

            $product->categories()->syncWithoutDetaching([$categoryId]);

            // Extras
            ProductExtra::updateOrCreate(
                ['product_id'=>$product->id,'store_id'=>$this->storeId],
                [
                    'retail_price' => (float)($this->getField($data,['retail price','retail_price']) ?? 0),
                    'distributor_name'=> $this->getField($data,['distributor name','distributor_name']) ?? '',
                    'distributor_sku'=> $this->getField($data,['distributor sku','distributor_sku']) ?? '',
                    'event_date_required' => $this->toBool($this->getField($data,['event date required?']) ?? 'No'),
                    'gps_gender'         => $this->getField($data,['gps gender','gps_gender']) ?? '',
                    'gps_age_group'      => $this->getField($data,['gps age group','gps_age_group']) ?? '',
                    'gps_enabled'        => $this->toBool($this->getField($data,['gps enabled','gps_enabled']) ?? 'No'),
                    'myob_asset_acct'    => $this->getField($data,['myob asset acct','myob_asset_acct']) ?? '',
                    'myob_income_acct'   => $this->getField($data,['myob income acct','myob_income_acct']) ?? '',
                    'myob_expense_acct'  => $this->getField($data,['myob expense acct','myob_expense_acct']) ?? '',
                ]
            );

            // Content
            ProductContent::updateOrCreate(
                ['product_id'=>$product->id,'store_id'=>$this->storeId],
                [
                    'description' => $this->getField($data,['product description','description']) ?? '',
                    'warranty'    => $this->getField($data,['product warranty','warranty']) ?? '',
                    'availability_text' => $this->getField($data,['product availability','availability']) ?? '',
                    'template_layout' => 'default',
                ]
            );

            // Identifier
            ProductIdentifier::updateOrCreate(
                ['product_id'=>$product->id,'store_id'=>$this->storeId],
                [
                    'mpn' => $this->getField($data,['gps manufacturer part number','mpn']) ?? '',
                    'upc' => $this->getField($data,['product upc/ean','upc']) ?? '',
                    'gtin'=> $this->getField($data,['gps global trade item number','gtin']) ?? '',
                    'bpn' => $this->getField($data,['bin picking number','bpn']) ?? '',
                ]
            );

            // SEO
            ProductSeo::updateOrCreate(
                ['product_id'=>$product->id,'store_id'=>$this->storeId],
                [
                    'page_title'=> $this->getField($data,['page title','meta title']) ?? $productName,
                    'meta_description'=> $this->getField($data,['meta description','meta_description']) ?? '',
                    'meta_keywords'=> $this->getField($data,['meta keywords','meta_keywords']) ?? '',
                ]
            );

            // Images
            // $primarySet = false;
            // foreach ($data as $key => $val) {
            //     if ($val && (str_contains($key,'product image file') || str_contains($key,'image'))) {
            //         ProductImage::updateOrCreate(
            //             ['product_id'=>$product->id,'path'=>trim($val)],
            //             [
            //                 'store_id'   => $this->storeId,
            //                 'alt_text'=>$productName,
            //                 'description'=>$this->getField($data,[str_replace('file','description',$key)]) ?? null,
            //                 'is_primary'=> $primarySet ? 0 : 1,
            //             ]
            //         );
            //         $primarySet = true;
            //     }
            // }

            // Images
        $primarySet = false;
        foreach ($data as $key => $val) {
            $keyNormalized = strtolower(trim($key));

            if ($val && ($keyNormalized === 'product image file - 1')) {

                $imageValue = trim($val);
                $savedPath = null;

                // (A) If image is a valid URL → download
                if (filter_var($imageValue, FILTER_VALIDATE_URL)) {

                    try {
                        $imageContent = file_get_contents($imageValue);

                        $extension = pathinfo(parse_url($imageValue, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                        $fileName = time() . '_' . uniqid() . '.' . $extension;

                        $pathFull = public_path('product_images/' . $fileName);
                        file_put_contents($pathFull, $imageContent);

                        $savedPath = '/product_images/' . $fileName;

                    } catch (\Throwable $e) {
                        continue;
                    }
                }

                // (B) If only filename provided → check local folders
                else {

                    $localPath1 = public_path('product_images/' . $imageValue);

                    if (file_exists($localPath1)) {

                        // Copy into new filename to avoid duplicate overwrite
                        $extension = pathinfo($imageValue, PATHINFO_EXTENSION) ?: 'jpg';
                        $fileName = time() . '_' . uniqid() . '.' . $extension;

                        copy($localPath1, public_path('product_images/' . $fileName));

                        $savedPath = '/product_images/' . $fileName;
                    } else {
                        continue; // Image not found locally
                    }
                }

            // Save DB entry
            ProductImage::updateOrCreate(
                ['product_id' => $product->id, 'path' => $savedPath],
                [
                    'store_id'    => $this->storeId,
                    'alt_text'    => $productName,
                    'description' => $this->getField($data,[str_replace('file','description',$key)]) ?? null,
                    'is_primary'  => $primarySet ? 0 : 1,
                ]
            );

            $primarySet = true;
        }
    }

            Cache::increment($this->progressKey . "_count");

            $current = Cache::get($this->progressKey . "_count");
            $total   = Cache::get($this->progressKey . "_total");

            $status = ($current >= $total) ? 'completed' : 'processing';

            Cache::put($this->progressKey, [
                'current' => $current,
                'total'   => $total,
                'status'  => $status
            ]);

        } catch (\Throwable $e) {
            Cache::increment($this->progressKey . "_count");

            $current = Cache::get($this->progressKey . "_count");
            $total   = Cache::get($this->progressKey . "_total");

            $status = ($current >= $total) ? 'completed' : 'processing';

            Cache::put($this->progressKey, [
                'current' => $current,
                'total'   => $total,
                'status'  => $status,
                'error'   => 'Error on row ' . $rowIndex . ': ' . $e->getMessage()
            ]);
        }
    }
    private function getField(array $data, array $possibleKeys, $default = null)
    {
        foreach ($possibleKeys as $k) {
            $kNorm = $this->normalizeKey($k);

            if (in_array($kNorm, $this->ignoredFields)) {
                return null;
            }

            if (array_key_exists($kNorm, $data) && $data[$kNorm] !== null && $data[$kNorm] !== '') {
                return $data[$kNorm];
            }

            $kRaw = str_replace([' ', '/', '-'], '_', strtolower(trim($k)));
            if (array_key_exists($kRaw, $data) && $data[$kRaw] !== null && $data[$kRaw] !== '') {
                return $data[$kRaw];
            }
        }
        return $default;
    }

    private function normalizeKey(string $key): string
    {
        $k = trim($key);
        $k = preg_replace('/[\x00-\x1F\x7F\xA0\x{200B}-\x{200D}\x{FEFF}]/u','',$k);
        $k = str_replace(['\\','/'], '/', $k);
        $k = str_replace('_',' ',$k);
        $k = preg_replace('/\s+/',' ',$k);
        return mb_strtolower($k);
    }

    private function resolveCategory($path)
    {
        $segments = array_map('trim', explode('/', $path));
        $parent = null;

        foreach ($segments as $segment) {
            $slug = Str::slug($segment);
            $key = ($parent ?: 0) . '_' . $slug;

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
}
