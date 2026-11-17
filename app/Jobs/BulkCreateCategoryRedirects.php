<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Category;
use App\Models\Redirect;
use Illuminate\Support\Str;

class BulkCreateCategoryRedirects implements ShouldQueue
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
        $this->domain = $domain;   // controller se aayega, fallback nahi
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

        Category::on('tenant')
            ->where('store_id', $this->storeId)
            ->orderBy('id')
            ->chunkById(100, function ($categories) use (&$created, &$processed, $total, $progressKey) {
                foreach ($categories as $category) {
                    $oldUrl = '/' . ($category->slug ?? Str::slug($category->name)) . '/';
                    $newUrl = $this->generateCategoryUrl($category);

                    if (rtrim($oldUrl, '/') === rtrim($newUrl, '/')) {
                        $processed++;
                        continue;
                    }

                    Redirect::on('tenant')->updateOrCreate(
                        ['store_id' => $this->storeId, 'old_url' => $oldUrl],
                        [
                            'product_id'    => null,
                            'entity_id'     => $category->id,
                            'entity_type'   => 'category',
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

    private function generateCategoryUrl($category): string
    {
        $format = $this->setting->format_type;
        $custom = $this->setting->custom_format;
        $categorySlug = $category->slug ?? Str::slug($category->name ?? '');

        $parentSlug = '';
        if ($category->parent_id) {
            $parent = Category::on('tenant')->find($category->parent_id);
            if ($parent) {
                $parentSlug = $parent->slug ?? Str::slug($parent->name ?? '');
            }
        }

        switch ($format) {
            case 'seo_optimized_short':
                return '/' . $categorySlug . '/';
            case 'seo_optimized_long':
                return '/categories/' . $categorySlug . '.html';
            case 'custom':
                if (empty($custom)) return '/' . $categorySlug . '/';
                $url = str_replace(
                    ['%categoryname%', '%parent%', '%id%'],
                    [$categorySlug, $parentSlug, $category->id],
                    $custom
                );
                if (!str_starts_with($url, '/')) $url = '/' . $url;
                if (!str_ends_with($url, '/') && !str_contains($url, '.')) $url .= '/';
                return $url;
            default:
                return '/' . $categorySlug . '/';
        }
    }
}
