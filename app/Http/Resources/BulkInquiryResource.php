<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Product;
use App\Models\ProductSeo;
use App\Models\Store;

class BulkInquiryResource extends JsonResource
{
    public function toArray($request)
    {
        $productLink = null;

        if ($this->sku) {

            $product = Product::where('sku', $this->sku)
                ->where('store_id', $this->store_id)
                ->first();

            if ($product) {

                $seo = ProductSeo::where('product_id', $product->id)
                    ->where('store_id', $this->store_id)
                    ->first();

                $store = Store::find($this->store_id);

                $baseUrl = ($store && $store->base_url)
                    ? rtrim($store->base_url, '/')
                    : null;

                if ($baseUrl) {
                    if ($seo && $seo->product_url) {
                        $productLink = $baseUrl . '/' . ltrim($seo->product_url, '/');
                    } else {
                        $productLink = $baseUrl . '/' . ltrim($product->slug, '/');
                    }
                }
            }
        }

        return [
            'id' => $this->id,
            'storeName' => $this->store ? $this->store->name : null,
            'email' => $this->email,
            'fullName' => $this->full_name,
            'phone' => $this->phone,
            'comments' => $this->comments,
            'quantity' => $this->quantity,
            'sku' => $this->sku,
            'productLink' => $productLink,
        ];
    }
}
