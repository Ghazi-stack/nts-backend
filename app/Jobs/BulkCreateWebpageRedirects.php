<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\WebPage;
use App\Models\Redirect;
use Illuminate\Support\Str;

class BulkCreateWebpageRedirects implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 1;

    protected $storeId;
    protected $progressKey;
    protected $setting;
    protected $tenantDatabase;

    public function __construct($storeId, $progressKey, $setting, $tenantDatabase = null)
    {
        $this->storeId = $storeId;
        $this->progressKey = $progressKey;
        $this->setting = (object) $setting;
        $this->tenantDatabase = $tenantDatabase;
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

        WebPage::on('tenant')
            ->where('store_id', $this->storeId)
            ->orderBy('id')
            ->chunkById(100, function ($pages) use (&$created, &$processed, $total, $progressKey) {

                foreach ($pages as $page) {
                    $oldUrl = $page->page_url ?? ('/' . Str::slug($page->page_name) . '/');
                    if (!str_starts_with($oldUrl, '/')) {
                        $oldUrl = '/' . $oldUrl;
                    }

                    $newUrl = $this->generateWebpageUrl($page);

                    if (rtrim($oldUrl, '/') === rtrim($newUrl, '/')) {
                        $processed++;
                        continue;
                    }

                    Redirect::on('tenant')->updateOrCreate(
                        ['store_id' => $this->storeId, 'old_url' => $oldUrl],
                        [
                            'product_id'  => null,
                            'entity_type' => 'webpage',
                            'new_url'     => $newUrl,
                            'is_active'   => true,
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

    private function generateWebpageUrl($page): string
    {
        $format = $this->setting->format_type;
        $custom = $this->setting->custom_format;
        $pageSlug = Str::slug($page->page_name ?? '');

        switch ($format) {
            case 'seo_optimized_short':
                return '/' . $pageSlug . '/';

            case 'seo_optimized_long':
                return '/pages/' . $pageSlug . '.html';

            case 'custom':
                if (empty($custom)) {
                    return '/' . $pageSlug . '/';
                }

                $url = str_replace(
                    ['%title%', '%id%'],
                    [$pageSlug, $page->id],
                    $custom
                );

                if (!str_starts_with($url, '/')) {
                    $url = '/' . $url;
                }
                if (!str_ends_with($url, '/') && !str_contains($url, '.')) {
                    $url .= '/';
                }
                return $url;

            default:
                return '/' . $pageSlug . '/';
        }
    }
}
