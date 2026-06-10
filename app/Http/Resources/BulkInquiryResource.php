<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Product;
use App\Models\ProductSeo;

class BulkInquiryResource extends JsonResource
{
    public function toArray($request)
    {
        // Frontend URL build karo product_seo se
        $productLink = null;
        if ($this->sku) {
            $product = Product::where('sku', $this->sku)
                ->where('store_id', $this->store_id)
                ->first();

            if ($product) {
                $seo = ProductSeo::where('product_id', $product->id)
                    ->where('store_id', $this->store_id)
                    ->first();

                $storeUrls = [
                    4  => 'https://new-town-spares.vercel.app',
                    10 => 'https://server-blink.vercel.app',
                ];

                $baseUrl = $storeUrls[$this->store_id] ?? 'https://server-blink.vercel.app';

                if ($seo && $seo->product_url) {
                    $productLink = $baseUrl . '/' . ltrim($seo->product_url, '/');
                } else {
                    $productLink = $baseUrl . '/' . $product->slug;
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
