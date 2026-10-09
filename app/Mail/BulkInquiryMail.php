<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\BulkInquiry;
use App\Models\Store;
use App\Models\Logo;
use App\Models\Product;
use App\Models\ProductSeo;
use App\Mail\StoreMailable;

class BulkInquiryMail extends StoreMailable
{
    use Queueable, SerializesModels;

    public $bulkInquiry;
    public $logoUrl;
    public $storePath;
    public $store;
    public $productLink;

    public function __construct(BulkInquiry $bulkInquiry)
    {
        $this->bulkInquiry = $bulkInquiry;

        $storeId = $bulkInquiry->store_id ?? 10;

        // Store record get karo
        $this->store = Store::find($storeId);

        // Logo
        $logo = Logo::where('store_id', $storeId)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        // Dynamic URL from stores table
        $this->storePath = $this->store?->base_url ?? '#';

        // Generate product link
        $this->productLink = null;

        if ($bulkInquiry->sku) {

            $product = Product::where('sku', $bulkInquiry->sku)
                ->where('store_id', $storeId)
                ->first();

            if ($product) {

                $seo = ProductSeo::where('product_id', $product->id)
                    ->where('store_id', $storeId)
                    ->first();

                if ($seo && $seo->product_url) {
                    $this->productLink = rtrim($this->storePath, '/') . '/' . ltrim($seo->product_url, '/');
                } else {
                    $this->productLink = rtrim($this->storePath, '/') . '/' . ltrim($product->slug, '/');
                }
            }
        }
    }

    public function build()
    {
        return $this->setStoreSender($this->store)
            ->subject('New Bulk Inquiry from ' . ($this->bulkInquiry->full_name ?? 'Customer'))
            ->view('emails.bulk-inquiry')
            ->with([
                'inquiry'     => $this->bulkInquiry,
                'logoUrl'     => $this->logoUrl,
                'storePath'   => $this->storePath,
                'store'       => $this->store,
                'productLink' => $this->productLink,
            ]);
    }
}
