<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\ProductSeo;
use App\Models\Redirect;
use App\Models\Category;
use Illuminate\Support\Str;

class BulkCreateProductRedirects implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 1;

    protected $storeId;
    protected $progressKey;
    protected $setting;
    protected $tenantDatabase;
    protected $domain;
    protected $redirectType;

    public function __construct($storeId, $progressKey, $setting, $tenantDatabase = null, $domain = null, $redirectType = 'dynamic')
    {
        $this->storeId = $storeId;
        $this->progressKey = $progressKey;
        $this->setting = (object) $setting;
        $this->tenantDatabase = $tenantDatabase;
        $this->domain = $domain ?? 'https://hardware-parts.mybigcommerce.com';
        $this->redirectType = $redirectType;
    }

    public function handle()
    {
        if ($this->tenantDatabase) {
            config(['database.connections.tenant.database' => $this->tenantDatabase]);
            DB::purge('tenant');
            DB::reconnect('tenant');
        }

        $created = 0;
        $processed = 0;
        $progressKey = $this->progressKey;
        $total = Cache::get($progressKey)['total'] ?? 0;

        Cache::put($progressKey, [
            'current' => 0,
            'total'   => $total,
            'created' => 0,
            'status'  => 'processing',
        ], 7200);

        Product::on('tenant')
            ->where('store_id', $this->storeId)
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$created, &$processed, $total, $progressKey) {
                foreach ($products as $product) {
                    $seo = ProductSeo::on('tenant')
                        ->where('product_id', $product->id)
                        ->where('store_id', $this->storeId)
                        ->first();

                    if (!$seo || empty($seo->product_url)) {
                        $processed++;
                        continue;
                    }

                    $oldUrl = $seo->product_url;
                    $newUrl = $this->generateProductUrl($product);

                    if (rtrim($oldUrl, '/') === rtrim($newUrl, '/')) {
                        $processed++;
                        continue;
                    }

                    Redirect::on('tenant')->updateOrCreate(
                        ['store_id' => $this->storeId, 'old_url' => $oldUrl],
                        [
                            'product_id'    => $product->id,
                            'entity_id'     => $product->id,
                            'entity_type'   => 'product',
                            'redirect_type' => $this->redirectType,
                            'domain'        => $this->domain,
                            'new_url'       => $newUrl,
                            'is_active'     => true,
                        ]
                    );

                    $created++;
                    $processed++;
                }

                Cache::put($progressKey, [
                    'current' => $processed,
                    'total'   => $total,
                    'created' => $created,
                    'status'  => 'processing',
                ], 7200);
            });

        Cache::put($progressKey, [
            'current' => $processed,
            'total'   => $total,
            'created' => $created,
            'status'  => 'completed',
        ], 7200);
    }

    private function generateProductUrl($product): string
    {
        $format = $this->setting->format_type;
        $custom = $this->setting->custom_format;
        $titleSlug = Str::slug($product->name ?? '');
        $sku = $product->sku ?? '';

        $categorySlug = 'uncategorized';
        if ($product->category_id) {
            $category = Category::on('tenant')->find($product->category_id);
            if ($category) {
                $categorySlug = $category->slug ?? Str::slug($category->name);
            }
        }

        switch ($format) {
            case 'seo_optimized_short':
                return '/' . $titleSlug . '/';
            case 'seo_optimized_long':
                return '/products/' . $titleSlug . '.html';
            case 'seo_optimized_category':
                return '/' . $categorySlug . '/' . $titleSlug . '/';
            case 'custom':
                if (empty($custom)) return '/' . $titleSlug . '/';
                $url = str_replace(
                    ['%title%', '%sku%', '%id%', '%categoryname%', '%parent%'],
                    [$titleSlug, $sku, $product->id, $categorySlug, $categorySlug],
                    $custom
                );
                if (!str_starts_with($url, '/')) $url = '/' . $url;
                if (!str_ends_with($url, '/') && !str_contains($url, '.')) $url .= '/';
                return $url;
            default:
                return '/' . $titleSlug . '/';
        }
    }
}
