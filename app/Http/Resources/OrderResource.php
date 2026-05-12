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
            'deviceType'         => $this->device_type,
            'userType'         => $this->user_type,
            'totalAmount'        => $this->total_amount,
            'discountAmount'        => $this->discountAmount,
            'newsletter'        => $this->newsletter,

            'trackingNumber'     => $this->tracking_number,
            'carrierCode'        => $this->tracking_carrier_code,
            'carrierName'        => $this->tracking_carrier_name,
            'shippedAt'          => $this->shipped_at?->toDateTimeString(),

            'payment' => $this->whenLoaded('payment', function () {
                return [
                    'id'                 => $this->payment->id,
                    'payment_intent_id'  => $this->payment->stripe_session_id,
                    'payment_status'     => $this->payment->payment_status,
                    'amount'             => $this->payment->amount,
                    'discountAmount'     => $this->payment->discountAmount,
                ];
            }),

            'billingInformation' => $this->billingInformation
                ? new BillingInformationResource($this->billingInformation)
                : null,
            'notes' => $this->whenLoaded('notes', function () {
                return $this->notes->pluck('note');
            }),
            // ✅ Only product details here
            // 'products' => ProductResource::collection(
            //     $this->whenLoaded('items', fn() => $this->items->pluck('product'))
            // ),
            'products' => $this->whenLoaded('items', function () {
                return $this->items
                    ->filter(fn($item) => $item->product) // ✅ NULL product remove
                    ->map(function ($item) {
                        return (new ProductResource($item->product))->additional([
                            'quantity' => $item->quantity,
                            'price' => $item->price,
                        ]);
                    });
            }),

            // 'items'    => OrderItemResource::collection($this->whenLoaded('items')),
            'customer' => $this->whenLoaded('customer', function () {
                return $this->customer ? new CustomerResource($this->customer) : null;
            }),
            'shippingDestinations' => ShippingDestinationResource::collection($this->whenLoaded('shippingDestinations')),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
