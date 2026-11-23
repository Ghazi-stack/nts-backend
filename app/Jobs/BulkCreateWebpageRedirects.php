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
    protected $domain;
    protected $redirectType;

    public function __construct($storeId, $progressKey, $setting, $tenantDatabase = null, $domain = null, $redirectType = 'dynamic')
    {
        $this->storeId = $storeId;
        $this->progressKey = $progressKey;
        $this->setting = (object) $setting;
        $this->tenantDatabase = $tenantDatabase;
        $this->domain = $domain;
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

        WebPage::on('tenant')
            ->where('store_id', $this->storeId)
            ->orderBy('id')
            ->chunkById(100, function ($pages) use (&$created, &$processed, $total, $progressKey) {
                foreach ($pages as $page) {
                    // Cancel check
                    if (Cache::get($progressKey . '_cancel')) {
                        Cache::put($progressKey, [
                            'current' => $processed,
                            'total'   => $total,
                            'created' => $created,
                            'status'  => 'cancelled',
                        ], 7200);
                        return false;
                    }

                    $oldUrl = $page->slugWithUrl ?? ('/' . Str::slug($page->page_name ?? '') . '/');
                    if (!str_starts_with($oldUrl, '/')) {
                        $oldUrl = '/' . $oldUrl;
                    }

                    $newUrl = $this->generateWebpageUrl($page);

                    if (rtrim($oldUrl, '/') === rtrim($newUrl, '/')) {
                        $processed++;
                        continue;
                    }

                    // 1. Redirect save
                    Redirect::on('tenant')->updateOrCreate(
                        ['store_id' => $this->storeId, 'old_url' => $oldUrl],
                        [
                            'product_id'    => null,
                            'entity_id'     => $page->id,
                            'entity_type'   => 'webpage',
                            'redirect_type' => $this->redirectType,
                            'domain'        => $this->domain,
                            'new_url'       => $newUrl,
                            'is_active'     => true,
                        ]
                    );

                    // 2. WebPage slugWithUrl update
                    $page->update([
                        'slugWithUrl' => $newUrl,
                    ]);

                    $created++;
                    $processed++;
                }

                Cache::put($progressKey, [
                    'current' => $processed,
                    'total'   => $total,
                    'created' => $created,
                    'status'  => 'processing',
                ], 7200);

                return true;
            });

        // Final status
        if (!Cache::get($progressKey . '_cancel')) {
            Cache::put($progressKey, [
                'current' => $processed,
                'total'   => $total,
                'created' => $created,
                'status'  => 'completed',
            ], 7200);
        }
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
                if (empty($custom)) return '/' . $pageSlug . '/';
                $url = str_replace(
                    ['%title%', '%id%'],
                    [$pageSlug, $page->id],
                    $custom
                );
                if (!str_starts_with($url, '/')) $url = '/' . $url;
                if (!str_ends_with($url, '/') && !str_contains($url, '.')) $url .= '/';
                return $url;
            default:
                return '/' . $pageSlug . '/';
        }
    }
}
