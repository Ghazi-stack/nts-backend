<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'user'       => $this->user ? [
                'id'   => $this->user->id,
                'name' => $this->user->name,
            ] : null,

            'product' => [
                'id'   => $this->product_id,
                'name' => $this->product?->name,
                'product_url' => $this->product?->seo?->product_url,
            ],

            'rating'     => $this->rating,
            'name'       => $this->name,
            'email'      => $this->email,
            'subject'    => $this->subject,
            'comment'    => $this->comment,
            'approved'   => $this->approved,
            'store_id'   => $this->store_id,
            'createdAt' => $this->created_at?->toDateString(),
            'updatedAt' => $this->updated_at?->toDateString(),
        ];
    }
}
