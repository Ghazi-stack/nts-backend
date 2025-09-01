<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\ProductResource;
use App\Http\Resources\OrderItemResource;
use App\Http\Resources\OrderNoteResource;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\BillingInformationResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'orderNumber'    => $this->order_number,
            'status'         => $this->status,
            'shippingCost'       => $this->shipping_cost,
            'comments'           => $this->comments,
            'staffNotes'         => $this->staff_notes,
            'isDraft'            => (bool) $this->is_draft,
            'totalAmount'        => $this->total_amount,

            'trackingNumber'     => $this->tracking_number,
            'carrierCode'        => $this->tracking_carrier_code,
            'carrierName'        => $this->tracking_carrier_name,
            'shippedAt'          => $this->shipped_at?->toDateTimeString(),

            'billingInformation' => $this->billingInformation
                ? new BillingInformationResource($this->billingInformation)
                : null,
            'notes' => $this->whenLoaded('notes', function () {
                    return $this->notes->pluck('note');
                }),
            // ✅ Only product details here
            'products' => ProductResource::collection(
                $this->whenLoaded('items', fn() => $this->items->pluck('product'))
            ),
            // 'items'    => OrderItemResource::collection($this->whenLoaded('items')),
            'customer' => new CustomerResource($this->whenLoaded('customer')),

            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
