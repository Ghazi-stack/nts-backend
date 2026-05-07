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
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Row;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Aws\S3\Exception\S3Exception;
use App\Jobs\ProcessProductImage;

class ProductImport implements OnEachRow, WithChunkReading, WithHeadingRow, ShouldQueue
{
    protected $storeId;
    protected $progressKey;
    protected $filePath;
    protected $ignoredFields = [];
    public $timeout = 0;
    public $tries = 1;

    protected static $brandCache = [];
    protected static $categoryCache = [];

    protected $tmpDir;

    public function __construct($storeId, $progressKey, $filePath, $ignoredFields = [])
    {
        $this->storeId       = $storeId;
        $this->progressKey   = $progressKey;
        $this->filePath      = $filePath;
        $this->ignoredFields = array_map('strtolower', $ignoredFields);

        $this->tmpDir = storage_path('app/tmp');

        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0755, true);
        }

        \DB::connection()->disableQueryLog();
    }

    public function chunkSize(): int
    {
        // Safe for t3.medium + Redis + swap
        return 50;
    }

    public function onRow(Row $row)
    {

        // Log::channel('single')->error('🔥 PRODUCT IMPORT ROW HIT');
        $traceId = 'IMG-' . uniqid();

        // Log::channel('single')->info("[$traceId] onRow STARTED", [
        //     'row_index' => $row->getIndex(),
        // ]);
        $rowIndex = $row->getIndex();

        try {
            $raw  = $row->toArray();
            $data = array_change_key_case(array_map('trim', $raw), CASE_LOWER);
            // ----------------------------
            // DEBUG: Log all raw + normalized headers
            // ----------------------------
            // $normalizedHeaders = [];

            // foreach ($raw as $key => $value) {
            //     $normalizedHeaders[] = [
            //         'raw_header'        => $key,
            //         'normalized_header' => $this->normalizeKey($key),
            //         'value'             => $value,
            //     ];
            // }

            // \Log::info("IMPORT HEADER DEBUG (Row {$rowIndex})", $normalizedHeaders);


            // Cancel check
            if (Cache::get($this->progressKey . "_cancel")) {
                Cache::put($this->progressKey, [
                    'current' => Cache::get($this->progressKey . "_count"),
                    'status'  => 'cancelled',
                ]);
                return;
            }

            // --- Required fields: SKU + Name ---------------------------------
            $sku = $this->getField($data, [
                'product code/sku',
                'product code / sku',
                'product code sku',
                'product code',
                'sku',
                'product_code',
                'productcode',
                'distributor sku',
                'distributor_sku',
            ]);

            $productName = $this->getField($data, [
                'product name',
                'name',
                'product_name',
                'productname',
            ]);

            if (!$sku || !$productName) {
                // Log::warning('Row skipped (missing sku or product name)', [
                //     'row' => $rowIndex,
                //     'sku' => $sku,
                //     'name' => $productName,
                // ]);

                if ($rowIndex % 20 === 0) {
                    $this->updateProgress();
                }
                return;
            }

            // --- Brand --------------------------------------------------------
            $brandName = $this->getField($data, ['brand name', 'brand', 'manufacturer']) ?? 'Unknown Brand';
            $brandKey  = strtolower($brandName);

            if (!isset(self::$brandCache[$brandKey])) {
                $brand = Brand::firstOrCreate(
                    [
                        'store_id' => $this->storeId,
                        'name'     => $brandName,
                    ],
                    [
                        'slug' => Str::slug($brandName),
                    ]
                );

                self::$brandCache[$brandKey] = $brand->id;
            }

            $brandId = self::$brandCache[$brandKey];

            // --- Category -----------------------------------------------------
            $categoryRaw = $this->getField($data, ['category', 'gps category', 'gps_category'], 'Uncategorized');
            $categoryId  = $this->resolveCategory($categoryRaw);

            // --- Prices & dimensions ------------------------------------------
            $price         = (float)($this->getField($data, ['price', 'unit price', 'product price']) ?? 0);
            $salePrice     = (float)($this->getField($data, ['sale price', 'special price', 'discount price']) ?? 0);
            $costPrice     = (float)($this->getField($data, ['cost price', 'cost']) ?? 0);
            $fixedShipping = (float)($this->getField($data, ['fixed shipping cost', 'fixed shipping']) ?? 0);

            $weight = (float)($this->getField($data, ['weight', 'product weight', 'product_weight']) ?? 0);
            $width  = (float)($this->getField($data, ['width', 'product width', 'product_width']) ?? 0);
            $height = (float)($this->getField($data, ['height', 'product height', 'product_height']) ?? 0);
            $depth  = (float)($this->getField($data, ['depth', 'product depth', 'product_depth']) ?? 0);
            //$retailPrice = (float)($this->getField($data, ['retail price', 'msrp']) ?? 0);
            // $msrp = max(0, $retailPrice - $price); // ensures saving is not negative
            // --- Product ------------------------------------------------------
            $product = Product::updateOrCreate(
                [
                    'store_id' => $this->storeId,
                    'sku'      => $sku,
                ],
                [
                    'name'                 => $productName,
                    'slug'                 => Str::slug($productName) . '-' . Str::random(6),
                    'brand_id'             => $brandId,
                    'category_id'          => $categoryId,
                    'price'                => $price,
                    'sale_price'           => $salePrice,
                    'cost_price'           => $costPrice,
                    'fixed_shipping_cost'  => $fixedShipping,
                    // 'msrp'                 => $retailPrice,
                    'dimensions'           => json_encode([
                        'weight' => $weight,
                        'width'  => $width,
                        'height' => $height,
                        'depth'  => $depth,
                    ]),
                    'allow_purchase'       => $this->toBool($this->getField($data, ['allow purchases?', 'allow purchase', 'allow_purchase']) ?? 'Yes'),
                    'track_inventory'      => $this->toBool($this->getField($data, ['track inventory', 'track_inventory']) ?? 'No'),
                    'msrp'                 => (int)($this->getField($data, ['retail price', 'msrp']) ?? 0),
                    'retail_price'         => (int)($this->getField($data, ['retail price', 'retail_price']) ?? 0),
                    'current_stock'        => (int)($this->getField($data, ['current stock level', 'stock', 'quantity']) ?? 0),
                    'low_stock'            => (int)($this->getField($data, ['low stock level', 'low stock']) ?? 0),
                    'item_type'            => $this->getField($data, ['item type', 'item_type']) ?? null,
                    'product_type'         => 'Physical',
                    'option_set'           => $this->getField($data, ['option set', 'option_set']) ?? null,
                    'option_set_align'     => $this->getField($data, ['option set align', 'option_set_align']) ?? null,
                    'visible_on_storefront' => $this->toBool($this->getField($data, ['product visible?', 'visible']) ?? 'Yes'),
                    'free_shipping'        => $this->toBool($this->getField($data, ['free shipping', 'free_shipping']) ?? 'No'),
                    'search_keywords'      => $this->getField($data, ['search keywords', 'keywords']) ?? null,
                ]
            );

            $product->categories()->syncWithoutDetaching([$categoryId]);

            // --- Extras -------------------------------------------------------
            ProductExtra::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $this->storeId],
                [
                    'retail_price'        => (float)($this->getField($data, ['retail price', 'retail_price']) ?? 0),
                    'distributor_name'    => $this->getField($data, ['distributor name', 'distributor_name']) ?? '',
                    'distributor_sku'     => $this->getField($data, ['distributor sku', 'distributor_sku']) ?? '',
                    'event_date_required' => $this->toBool($this->getField($data, ['event date required?']) ?? 'No'),
                    'gps_gender'          => $this->getField($data, ['gps gender', 'gps_gender']) ?? '',
                    'gps_age_group'       => $this->getField($data, ['gps age group', 'gps_age_group']) ?? '',
                    'gps_enabled'         => $this->toBool($this->getField($data, ['gps enabled', 'gps_enabled']) ?? 'No'),
                    'myob_asset_acct'     => $this->getField($data, ['myob asset acct', 'myob_asset_acct']) ?? '',
                    'myob_income_acct'    => $this->getField($data, ['myob income acct', 'myob_income_acct']) ?? '',
                    'myob_expense_acct'   => $this->getField($data, ['myob expense acct', 'myob_expense_acct']) ?? '',
                ]
            );

            // --- Content ------------------------------------------------------
            ProductContent::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $this->storeId],
                [
                    'description'       => $this->getField($data, ['product description', 'description']) ?? '',
                    'warranty'          => $this->getField($data, ['product warranty', 'warranty']) ?? '',
                    'availability_text' => $this->getField($data, ['product availability', 'availability']) ?? '',
                    'template_layout'   => 'default',
                ]
            );

            // --- Identifiers --------------------------------------------------
            ProductIdentifier::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $this->storeId],
                [
                    'mpn'  => $this->getField($data, ['gps manufacturer part number', 'mpn']) ?? '',
                    'upc'  => $this->getField($data, ['product upc/ean', 'upc']) ?? '',
                    'gtin' => $this->getField($data, ['gps global trade item number', 'gtin']) ?? '',
                    'bpn'  => $this->getField($data, ['bin picking number', 'bpn']) ?? '',
                ]
            );

         // --- SEO ----------------------------------------------------------
            $baseUrl = rtrim(config('app.url'), '/');

            // ALWAYS generate product_url from url_settings custom_format
            $productUrl = null;

            $urlSetting = \App\Models\UrlSetting::where('store_id', $this->storeId)
                ->where('entity_type', 'product')
                ->first();

            if ($urlSetting && $urlSetting->custom_format) {
                $customFormat = $urlSetting->custom_format;

                // Load category name for placeholder
                $categoryName = '';
                if ($categoryId) {
                    $category = \App\Models\Category::find($categoryId);
                    $categoryName = $category ? $category->name : '';
                }

                // Pre-generate slugged title — special characters ko pehle dash mein convert karo
                $cleanName = str_replace(['.', '/', '\\', ',', '(', ')', '&', '+', '#', '@', '!', '?', ';', ':', "'", '"'], '-', $productName);
                $sluggedTitle = Str::slug($cleanName);

                // Build a map of all possible placeholders to their actual values
                $placeholders = [
                    '%title%'         => $sluggedTitle,
                    '%brand%'         => \Illuminate\Support\Str::slug($brandName),
                    '%sku%'           => \Illuminate\Support\Str::slug($sku),
                    '%name%'          => $sluggedTitle,
                    '%product_name%'  => $sluggedTitle,
                    '%category%'      => \Illuminate\Support\Str::slug($categoryName),
                    '%id%'            => $product->id,
                    '%slug%'          => $product->slug,
                    '%price%'         => $price,
                    '%mpn%'           => \Illuminate\Support\Str::slug($this->getField($data, ['gps manufacturer part number', 'mpn']) ?? ''),
                    '%upc%'           => \Illuminate\Support\Str::slug($this->getField($data, ['product upc/ean', 'upc']) ?? ''),
                ];

                // Replace all placeholders in custom_format
                $productUrl = str_replace(
                    array_keys($placeholders),
                    array_values($placeholders),
                    $customFormat
                );

                // Remove trailing slash to match Reset button behavior
                $productUrl = rtrim($productUrl, '/');
            }

            ProductSeo::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $this->storeId],
                [
                    'page_title'       => $this->getField($data, ['page title', 'meta title']) ?? $productName,
                    'meta_description' => $this->getField($data, ['meta description', 'meta_description']) ?? '',
                    'meta_keywords'    => $this->getField($data, ['meta keywords', 'meta_keywords']) ?? '',
                    'product_url'      => $productUrl,
                ]
            );

            // --- Images (primary from "product image file - 1") ---------------
            // ----------------------------------------
            // IMAGE IMPORT (Product Image File - 1)
            // ----------------------------------------

            // Log::channel('single')->info("[$traceId] IMAGE SECTION ENTERED", [
            //     'available_keys' => array_keys($data),
            // ]);


            $imageKeyOptions = [
                'product image file - 1',
                'product image file 1',
                'product_image_file_1',
                'product image file-1',
                'product_image_file - 1',
            ];

            // Normalize all candidate keys
            $normalizedTargets = array_map(function ($h) {
                return $this->normalizeKey($h);
            }, $imageKeyOptions);

            foreach ($data as $rawKey => $val) {

                // Log::channel('single')->info("[$traceId] IMAGE LOOP ITERATION", [
                //     'raw_key' => $rawKey,
                //     'raw_value' => $val,
                // ]);

                if (!$val) continue;

                // Normalize the incoming CSV header
                $keyNorm = $this->normalizeKey($rawKey);

                // Process ONLY if it matches expected image headers
                if (!in_array($keyNorm, $normalizedTargets)) {
                    // Log::channel('single')->warning("[$traceId] IMAGE HEADER SKIPPED", [
                    //     'normalized_key' => $keyNorm,
                    //     'targets' => $normalizedTargets,
                    // ]);
                    continue;
                }

                $imageValue = trim($val);
                $savedPath  = null;
                $uploaded = false;

                /* =====================================================
       (A) URL IMAGE → DIRECT S3 UPLOAD
    ===================================================== */
                if (filter_var($imageValue, FILTER_VALIDATE_URL)) {

                    try {
                        $response = Http::timeout(20)
                            ->retry(3, 500)
                            ->withHeaders([
                                'User-Agent' => 'Mozilla/5.0',
                                'Accept' => 'image/*,*/*',
                            ])
                            ->withOptions([
                                'verify' => false,
                                'allow_redirects' => true,
                            ])
                            ->get($imageValue);

                        // logger()->info('IMAGE FETCH RESULT', [
                        //     'url' => $imageValue,
                        //     'status' => optional($response)->status(),
                        //     'ok' => optional($response)->successful(),
                        // ]);
                        if (
                            !$response->successful() ||
                            !str_starts_with(
                                $response->header('Content-Type'),
                                'image/'
                            )
                        ) {
                            // Log::warning('IMAGE SKIPPED (INVALID RESPONSE)', [
                            //     'url' => $imageValue,
                            //     'status' => $response->status(),
                            //     'content_type' => $response->header('Content-Type'),
                            // ]);
                            continue;
                        }

                        $originalName = pathinfo(
                            parse_url($imageValue, PHP_URL_PATH),
                            PATHINFO_FILENAME
                        );

                        $extension = pathinfo(
                            parse_url($imageValue, PHP_URL_PATH),
                            PATHINFO_EXTENSION
                        ) ?: 'jpg';

                        $fileName = Str::slug($originalName)
                            . '-' . time() . '-' . uniqid() . '.' . $extension;

                        $savedPath = "product_images/{$product->id}/{$fileName}";
                        $uploaded  = false;

                        // Log::info("[$traceId] IMAGE SIZE", [
                        //     'bytes' => strlen($response->body()),
                        // ]);

                        // Storage::disk('s3')->put(
                        //     $savedPath,
                        //     $response->body(),
                        //     [
                        //         'visibility' => 'public',
                        //         'ContentType' => $response->header('Content-Type') ?? 'image/jpeg',
                        //     ]
                        // );

                        // $tmpDir = storage_path('app/tmp');

                        // if (!is_dir($tmpDir)) {
                        //     mkdir($tmpDir, 0755, true);
                        // }

                        // $tmpPath = $tmpDir . '/' . uniqid('img_');

                        $tmpPath = $this->tmpDir . '/' . uniqid('img_');

                        file_put_contents($tmpPath, $response->body());

                        $stream = fopen($tmpPath, 'r');

                        $uploaded = Storage::disk('s3')->put(
                            $savedPath,
                            $stream,
                            [
                                'ContentType' => $response->header('Content-Type') ?? 'image/jpeg',
                            ]
                        );

                        fclose($stream);


                        // Log::info("[$traceId] S3 PUT RESULT", [
                        //     'path' => $savedPath,
                        //     'result' => $uploaded,
                        //     'bucket' => config('filesystems.disks.s3.bucket'),
                        //     'region' => config('filesystems.disks.s3.region'),
                        // ]);


                        @unlink($tmpPath);
                    } catch (\Throwable $e) {
                        // Log::error("[$traceId] IMAGE UPLOAD FAILED", [
                        //     'message' => $e->getMessage(),
                        // ]);
                        continue;
                    }
                }

                /* =====================================================
       (B) LOCAL FILENAME (LEGACY) → MOVE TO S3
    ===================================================== */ else {

                    // existing local image (old imports)
                    $localPath = public_path('product_images/' . $imageValue);

                    if (file_exists($localPath)) {

                        $extension = pathinfo($imageValue, PATHINFO_EXTENSION) ?: 'jpg';

                        $fileName = Str::slug(
                            pathinfo($imageValue, PATHINFO_FILENAME)
                        ) . '-' . time() . '-' . uniqid() . '.' . $extension;

                        $savedPath = "product_images/{$product->id}/{$fileName}";

                        // Log::info("[$traceId] IMAGE SIZE", [
                        //     'bytes' => strlen($response->body()),
                        // ]);

                        // Storage::disk('s3')->put(
                        //     $savedPath,
                        //     file_get_contents($localPath),
                        //     [
                        //         'visibility' => 'public',
                        //         'ContentType' => mime_content_type($localPath) ?: 'image/jpeg',
                        //     ]
                        // );

                        $tmpDir = storage_path('app/tmp');

                        if (!is_dir($tmpDir)) {
                            mkdir($tmpDir, 0755, true);
                        }

                        $tmpPath = $tmpDir . '/' . uniqid('img_');

                        file_put_contents($tmpPath, $response->body());

                        $uploaded = Storage::disk('s3')->put(
                            $savedPath,
                            fopen($tmpPath, 'r'),
                            [
                                'ContentType' => $response->header('Content-Type') ?? 'image/jpeg',
                            ]
                        );

                        // Log::info("[$traceId] S3 PUT RESULT", [
                        //     'path' => $savedPath,
                        //     'result' => $uploaded,
                        //     'bucket' => config('filesystems.disks.s3.bucket'),
                        //     'region' => config('filesystems.disks.s3.region'),
                        // ]);


                        @unlink($tmpPath);
                    }
                }

                /* =====================================================
       (C) SAVE IMAGE RECORD (UNCHANGED LOGIC)
    ===================================================== */
                if ($uploaded === true) {

                    ProductImage::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'path'       => $savedPath,
                            'url'       => $savedPath,
                        ],
                        [
                            'store_id'   => $this->storeId,
                            'alt_text'   => $productName,
                            'is_primary' => 1,
                        ]
                    );
                }
            }

            // --- Progress update for successful row ---------------------------
            $this->updateProgress();
        } catch (\Throwable $e) {
            // Do not stop the whole job if one row fails
            Log::error('Import row error', [
                'row'   => $rowIndex,
                'error' => $e->getMessage(),
            ]);

            // Still move progress forward
            if ($rowIndex % 20 === 0) {
                $this->updateProgress();
            }
        }
    }

    /**
     * Centralized progress update.
     */
    private function updateProgress(): void
    {
        Cache::increment($this->progressKey . "_count");

        $current = Cache::get($this->progressKey . "_count");
        $total   = Cache::get($this->progressKey . "_total", 0);

        $status = ($total > 0 && $current >= $total) ? 'completed' : 'processing';

        Cache::put($this->progressKey, [
            'current' => $current,
            'total'   => $total,
            'status'  => $status,
        ]);
    }

    /**
     * Try a list of possible header keys and return the first non-empty value.
     */
    private function getField(array $data, array $possibleKeys, $default = null)
    {
        foreach ($possibleKeys as $k) {
            $kNorm = $this->normalizeKey($k);

            // Skip ignored fields
            if (in_array($kNorm, $this->ignoredFields, true)) {
                return null;
            }

            if (array_key_exists($kNorm, $data) && $data[$kNorm] !== null && $data[$kNorm] !== '') {
                return $data[$kNorm];
            }

            // Fallback: snake-like variants
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
        $k = preg_replace('/[\x00-\x1F\x7F\xA0\x{200B}-\x{200D}\x{FEFF}]/u', '', $k);
        $k = str_replace(['\\', '/'], '/', $k);
        $k = str_replace('_', ' ', $k);
        $k = preg_replace('/\s+/', ' ', $k);
        return mb_strtolower($k);
    }

    private function resolveCategory($path)
    {
        $segments = array_map('trim', explode('/', $path));
        $parent   = null;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $slug = Str::slug($segment);
            $key  = ($parent ?: 0) . '_' . $slug;

            if (!isset(self::$categoryCache[$key])) {
                $category = Category::firstOrCreate(
                    [
                        'store_id'  => $this->storeId,
                        'slug'      => $slug,
                        'parent_id' => $parent,
                    ],
                    [
                        'name' => $segment,
                    ]
                );

                self::$categoryCache[$key] = $category->id;
            }

            $parent = self::$categoryCache[$key];
        }

        return $parent;
    }

    private function toBool($val)
    {
        return in_array(strtolower(trim((string) $val)), ['yes', 'y', '1', 'true'], true);
    }
}
