<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MyOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'total_amount' => $this->total_amount,
            'createdAt' => $this->created_at?->toDateString(),
            'updatedAt' => $this->updated_at?->toDateString(),

            // ✅ RETURN REQUEST OBJECT
            'returnRequest' => $this->returnRequest ? [
                'id'           => $this->returnRequest->id,
                'reason'       => $this->returnRequest->reason,
                'returnAction' => $this->returnRequest->returnAction,
                'comments'     => $this->returnRequest->comments,
                'status'       => $this->returnRequest->status,
                'isSubmit'     => (bool) $this->returnRequest->is_submit,
                'createdAt'    => $this->returnRequest->created_at?->toDateTimeString(),
            ] : null,

            'products' => $this->items->map(function ($item) {
                return [
                    'id'       => $item->product->id,
                    'name'     => $item->product->name,
                    'quantity' => $item->quantity,
                    'price'    => $item->price,
                    'image'    => ProductImageResource::collection($item->product->images),
                ];
            }),
        ];
    }
}
