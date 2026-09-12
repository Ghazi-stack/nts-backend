<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\BillingInformationResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\ShippingDestinationResource;
use App\Http\Resources\ProductImageResource;
use App\Http\Resources\BrandResource;

class OrderResource extends JsonResource
{
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
            'userType'           => $this->user_type,
            'emailInvoice'       => $this->email_invoice,
            'totalAmount'        => $this->total_amount,
            'discountAmount'     => $this->discountAmount,
            'couponCode'         => $this->coupon_code,
            'manualDiscount'     => $this->whenLoaded('manualDiscounts')
                ? (float) $this->manualDiscounts->sum('discount_amount')
                : 0,
            'newsletter'         => $this->newsletter,
            'ipAddress'          => $this->ip_address,
            'isSaveAddressForShipping' => (bool) $this->is_save_address_for_shipping,
            'isSaveAddressForBilling' => (bool) $this->is_save_address_for_billing,
            'billingSame'        => (bool) $this->billing_same,
            'isMessage'          => $this->messages_count > 0 ? 1 : 0,
            'messageCount'       => $this->messages_count ?? 0,
            'shipmentId'     => $this->shipment?->id ?? null,
            'trackingNumber' => $this->shipment?->tracking_number ?? $this->tracking_number,
            'carrierCode'    => $this->shipment?->tracking_carrie ?? $this->tracking_carrier_code,
            'carrierName'    => $this->shipment?->tracking_carrie ?? $this->tracking_carrier_name,
            'shippedAt'      => $this->shipment?->date_shipped
                ? (optional($this->shipment->date_shipped)->toDateTimeString()
                    ?? $this->shipment->date_shipped)
                : $this->shipped_at?->toDateTimeString(),

            'payment' => $this->whenLoaded('payment', function () {
                return [
                    'id'                 => $this->payment->id,
                    'payment_intent_id'  => $this->payment->stripe_session_id,
                    'payment_status'     => $this->payment->payment_status,
                    'amount'             => $this->payment->amount,
                    'discountAmount'     => $this->payment->discountAmount,
                    'risk_score'         => $this->payment->risk_score,
                    'risk_level'         => $this->payment->risk_level,
                ];
            }),

            'billingInformation' => $this->billingInformation
                ? new BillingInformationResource($this->billingInformation)
                : null,

            'billingAddress' => $this->resolveBillingAddress(),


            'notes' => $this->whenLoaded('notes', function () {
                return $this->notes->pluck('note');
            }),

            // ====================== OPTIMIZED PRODUCTS (NO ProductResource) ======================
            'products' => $this->whenLoaded('items', function () {
                return $this->items
                    ->filter(fn($item) => $item->product)
                    ->map(function ($item) {
                        $product = $item->product;

                        $price = $product->price ?? 0;
                        $msrp  = $product->msrp ?? 0;
                        $retailPrice = ($msrp > $price) ? round($msrp - $price, 2) : 0;

                        $dimensions = [];
                        if (is_array($product->dimensions)) {
                            $dimensions = $product->dimensions;
                        } elseif ($product->dimensions) {
                            $decoded = json_decode($product->dimensions, true);
                            if (is_array($decoded)) {
                                $dimensions = $decoded;
                            }
                        }

                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'slug' => $product->slug,
                            'sku' => $product->sku,
                            'price' => $price,
                            'retailPrice' => $retailPrice,
                            'msrp' => $msrp,
                            'bestSelling' => $product->sold_count,
                            'condition' => $product->condition,
                            'faq' => $product->faq,
                            'costPrice' => $product->cost_price,
                            'salePrice' => $product->sale_price,
                            'fixedShippingCost' => $product->fixed_shipping_cost,
                            'productType' => $product->product_type,
                            'optionSet' => $product->option_set,
                            'optionSetAlign' => $product->option_set_align,
                            'allowPurchase' => $product->allow_purchase,
                            'stopProcessingRules' => $product->stop_processing_rules,
                            'taxClass' => $product->tax_class,
                            'taxProviderCode' => $product->tax_provider_code,
                            'brandId' => (int) $product->brand_id,
                            'categoryIds' => $product->relationLoaded('categories')
                                ? $product->categories->pluck('id')->map(fn($id) => (int)$id)
                                : [],
                            'brand' => $product->relationLoaded('brand') && $product->brand
                                ? new BrandResource($product->brand)
                                : null,

                            // 'dimensions' => [
                            //     'weight' => $dimensions['weight'] ?? 0,
                            //     'width'  => $dimensions['width'] ?? 0,
                            //     'height' => $dimensions['height'] ?? 0,
                            //     'depth'  => $dimensions['depth'] ?? 0,
                            // ],

                            'quantity' => $item->quantity,          // order item quantity
                            'price' => $item->price,                // order item price (override)

                            'trackInventory' => $product->track_inventory,
                            'currentStock' => $product->current_stock,
                            'callPricing' => $product->call_pricing,
                            'lowStock' => $product->low_stock,
                            'isFeatured' => $product->is_featured,
                            'isVisible' => $product->visible_on_storefront,
                            'showCondition' => $product->show_condition,
                            'freeShipping' => $product->show_shipping,
                            'sortOrder' => $product->sort_order,
                            'searchKeywords' => $product->search_keywords,

                            // 'objectType' => $product->object_type,
                            // 'useProductName' => (bool) $product->use_product_name,
                            // 'graphDescription' => (bool) $product->graph_description,
                            // 'useThumbnail' => (bool) $product->use_thumbnail,
                            // 'dontUse' => (bool) $product->dont_use,
                            // 'manageCustoms' => (bool) $product->manage_customs,
                            // 'commodityDescription' => $product->commodity_description,
                            // 'countryOfOrigin' => $product->country_of_origin,
                            // 'hsCodes' => $product->hs_codes ?? [],

                            // 'customFields' => collect($product->custom_fields ?? [])->mapWithKeys(function ($value, $key) {
                            //     return [trim($key, "\"") => is_string($value) ? trim($value, "\"") : $value];
                            // }),

                            'storeId' => $product->store_id,
                            'createdAt' => $product->created_at,
                            'updatedAt' => $product->updated_at,

                            'image' => $product->relationLoaded('images')
                                ? ProductImageResource::collection($product->images)
                                : [],

                            'description' => optional($product->content)->description,
                            'warranty' => optional($product->content)->warranty,
                            'availabilityText' => optional($product->content)->availability_text,
                            'templateLayout' => optional($product->content)->template_layout,
                            'pageTitle' => optional($product->seo)->page_title,
                            'productUrl' => optional($product->seo)->product_url,
                            'metaDescription' => optional($product->seo)->meta_description,
                            'mpn' => optional($product->identifiers)->mpn,
                            'upc' => optional($product->identifiers)->upc,
                            'gtin' => optional($product->identifiers)->gtin,
                            'bpn' => optional($product->identifiers)->bpn,

                            // categoryHierarchy intentionally removed for performance
                            // 'categoryHierarchy' => null,

                            // 'purchasabilityStatus' => $product->purchasability_status,
                            // 'minPurchaseQuantity' => $product->min_purchase_quantity,
                            // 'maxPurchaseQuantity' => $product->max_purchase_quantity,
                            // 'relatedProductsEnabled' => $product->related_products_enabled,
                            // 'taxCode' => $product->tax_code,
                            // 'discountType' => $product->discount_type,
                            // 'bulkPricingTiers' => $product->bulk_pricing_tiers ?? [],
                            // schema intentionally removed (very heavy)
                        ];
                    })->values();
            }),

            'customer' => $this->whenLoaded('customer', function () {
                return $this->customer ? new CustomerResource($this->customer) : null;
            }),

            'shippingDestinations' => ShippingDestinationResource::collection($this->whenLoaded('shippingDestinations')),

            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }

    protected function resolveBillingAddress(): ?array
    {
        $saved = $this->billing_address;
        if (is_string($saved)) {
            $saved = json_decode($saved, true);
        }
        if (is_array($saved) && collect($saved)->filter(fn($v) => $v !== null && $v !== '')->isNotEmpty()) {
            return $this->mapBillingAddress($saved);
        }
        $info = $this->billingInformation;
        if (!$info) {
            return null;
        }
        return $this->mapBillingAddress([
            'firstName'    => $info->first_name,
            'lastName'     => $info->last_name,
            'companyName'  => $info->company_name,
            'email'        => $info->email,
            'phone'        => $info->phone,
            'addressLine1' => $info->address_line_1,
            'addressLine2' => $info->address_line_2,
            'city'         => $info->city,
            'state'        => $info->state,
            'zip'          => $info->zip,
            'country'      => $info->country,
        ]);
    }

    protected function mapBillingAddress($address): array
    {
        $first = data_get($address, 'first_name', data_get($address, 'firstName'));
        $last  = data_get($address, 'last_name', data_get($address, 'lastName'));
        $name  = data_get($address, 'name') ?: trim(($first ?? '') . ' ' . ($last ?? ''));

        return [
            'name'         => $name ?: null,
            'firstName'    => $first,
            'lastName'     => $last,
            'companyName'  => data_get($address, 'company_name', data_get($address, 'companyName')),
            'email'        => data_get($address, 'email'),
            'phone'        => data_get($address, 'phone'),
            'addressLine1' => data_get($address, 'address_line_1', data_get($address, 'addressLine1')),
            'addressLine2' => data_get($address, 'address_line_2', data_get($address, 'addressLine2')),
            'city'         => data_get($address, 'city'),
            'state'        => data_get($address, 'state'),
            'zip'          => data_get($address, 'zip'),
            'country'      => data_get($address, 'country'),
        ];
    }
}
