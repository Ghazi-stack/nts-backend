<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'page_title'       => $this->page_title,
            'meta_keywords'    => $this->meta_keywords,
            'meta_description' => $this->meta_description,
            'search_keywords'  => $this->search_keywords,
            'brand_url'        => $this->brand_url,
            'template_layout'  => $this->template_layout,
            'logo'             => $this->logo 
                ? url($this->logo) 
                : null,
            'created_at'       => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at'       => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
