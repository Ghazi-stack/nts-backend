<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSeoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'page_title' => $this->page_title,
            'product_url' => $this->product_url,
            'meta_description' => $this->meta_description,
            'meta_keywords' => $this->meta_keywords,
            'redirect_old_url' => (bool) $this->redirect_old_url,
        ];
    }

}
