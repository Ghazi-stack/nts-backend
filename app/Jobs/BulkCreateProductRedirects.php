<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

    public function __construct($storeId, $progressKey, $setting)
    {
        $this->storeId = $storeId;
        $this->progressKey = $progressKey;
        $this->setting = (object) $setting;
    }

    public function handle()
    {
        $created = 0;
        $processed = 0;
        $total = Cache::get($this->progressKey)['total'] ?? 0;

        Cache::put($this->progressKey, [
            'current' => 0,
            'total'   => $total,
            'created' => 0,
            'status'  => 'processing',
        ], 7200);

        Product::where('store_id', $this->storeId)
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$created, &$processed, $total) {

                foreach ($products as $product) {
                    $seo = ProductSeo::where('product_id', $product->id)
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

                    Redirect::updateOrCreate(
                        ['store_id' => $this->storeId, 'old_url' => $oldUrl],
                        [
                            'product_id'  => $product->id,
                            'entity_type' => 'product',
                            'new_url'     => $newUrl,
                            'is_active'   => true,
                        ]
                    );

                    $created++;
                    $processed++;
                }

                Cache::put($this->progressKey, [
                    'current' => $processed,
                    'total'   => $total,
                    'created' => $created,
                    'status'  => 'processing',
                ], 7200);

                unset($products);
            });

        Cache::put($this->progressKey, [
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
            $category = Category::find($product->category_id);
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
