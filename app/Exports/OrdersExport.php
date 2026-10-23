<?php

namespace App\Exports;

use App\Models\Store;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class OrdersExport
{
    protected int $storeId;
    protected string $channelName;

    public function __construct(int $storeId)
    {
        $this->storeId = $storeId;

        $this->channelName = DB::connection('central')
            ->table('stores')
            ->where('legacy_store_id', $storeId)
            ->value('name') ?: '';
    }
    public function headings(): array
    {
        return [
            'Order ID',
            'Customer ID',
            'Customer Name',
            'Customer Email',
            'Customer Phone',
            'Order Date',
            'Order Status',
            'Subtotal (inc tax)',
            'Subtotal (ex tax)',
            'Tax Total',
            'Shipping Cost (inc tax)',
            'Shipping Cost (ex tax)',
            'Ship Method',
            'Handling Cost (inc tax)',
            'Handling Cost (ex tax)',
            'Store Credit Redeemed',
            'Coupon Details',
            'Order Total (inc tax)',
            'Order Total (ex tax)',
            'Payment Method',
            'Total Quantity',
            'Total Shipped',
            'Date Shipped',
            'Order Currency Code',
            'Exchange Rate',
            'Order Notes',
            'Customer Message',
            'Billing First Name',
            'Billing Last Name',
            'Billing Company',
            'Billing Street 1',
            'Billing Street 2',
            'Billing Suburb',
            'Billing State',
            'Billing Zip',
            'Billing Country',
            'Billing Phone',
            'Billing Email',
            'Shipping First Name',
            'Shipping Last Name',
            'Shipping Company',
            'Shipping Street 1',
            'Shipping Street 2',
            'Shipping Suburb',
            'Shipping State',
            'Shipping Zip',
            'Shipping Country',
            'Shipping Phone',
            'Shipping Email',
            'Product Details',
            'Refund Amount',
            'Channel ID',
            'Channel Name',
            'Fee Details',
        ];
    }

    public function map($order): array
    {
        $customer    = $order->customer;
        $billing     = $order->billingInformation;
        $payment     = $order->payment;
        $shipment    = $order->shipment;
        $billingAddr = $this->json($order->billing_address ?? $order->billingAddress ?? null);

        $firstName = $this->attr($customer, 'first_name') ?: $this->attr($billing, 'first_name');
        $lastName  = $this->attr($customer, 'last_name') ?: $this->attr($billing, 'last_name');
        $fullName  = trim($firstName . ' ' . $lastName) ?: $this->str($order->name ?? '');

        $shippingCost = $this->num($order->shipping_cost ?? 0);
        $orderTotal   = $this->num($order->total_amount ?? 0);
        $discountAmt  = $this->num(
            $order->discountAmount
                ?? $order->discount_amount
                ?? $order->manualDiscounts?->sum('discount_amount')
                ?? $order->manual_discount
                ?? 0
        );

        $itemsSubtotal = $order->items
            ? $order->items->sum(function ($item) {
                $qty   = (float) ($item->quantity ?? 0);
                $price = (float) ($item->price ?? optional($item->product)->price ?? 0);
                return $qty * $price;
            })
            : 0;

        $subtotalEx  = $this->num($itemsSubtotal);
        $subtotalInc = $this->num($itemsSubtotal);

        $totalQty = (int) ($order->items?->sum('quantity') ?? 0);
        $shipment = $order->shipment;

        $totalShipped = $shipment && $shipment->id
            ? (int) $shipment->id
            : 0;

        $dateShipped = ($shipment && $shipment->date_shipped)
            ? $this->formatDate($shipment->date_shipped)
            : '';

        $couponDetails  = $this->couponDetails($order, $discountAmt);
        $productDetails = $this->productDetails($order);

        $orderNotes = $order->notes?->pluck('note')->filter()->implode(' | ')
            ?: $this->str($order->staff_notes ?? $order->staffNotes ?? '');

        $customerMessage = $this->str($order->comments ?? $order->customer_message ?? $order->customerMessage ?? '');

        $channelName = $this->channelName;
        $channelId   = $this->storeId;

        $shipMethod = $this->attr($billing, 'shipping_method')
            ?: $this->str($order->shipping_method ?? '')
            ?: $this->attr($shipment, 'shipping_method');
        if ($shipMethod === '') {
            $shipMethod = 'None';
        }

        $paymentMethod = $this->attr($billing, 'payment_method')
            ?: $this->attr($payment, 'payment_method')
            ?: $this->str($order->payment_method ?? '');

        return [
            $order->id ?? 0,
            $order->customer_id ?? 0,
            $fullName,
            $this->attr($customer, 'email') ?: $this->str($order->email ?? '') ?: $this->attr($billing, 'email'),
            $this->attr($customer, 'phone') ?: $this->str($order->phone ?? '') ?: $this->attr($billing, 'phone'),
            optional($order->created_at)->format('d/m/Y') ?: '0',
            $this->str($order->status ?? '') ?: '0',
            $subtotalInc,
            $subtotalEx,
            '0',
            $shippingCost,
            $shippingCost,
            $shipMethod,
            '0',
            '0',
            '0',
            $couponDetails,
            $orderTotal,
            $orderTotal,
            $paymentMethod,
            $totalQty,
            $totalShipped,
            $dateShipped,
            $this->str($order->currency_code ?? $order->order_currency_code ?? 'USD') ?: 'USD',
            $this->num($order->exchange_rate ?? 1, 1),
            $orderNotes,
            $customerMessage,
            $this->billingName($billingAddr, 'first'),
            $this->billingName($billingAddr, 'last'),
            $this->fromArr($billingAddr, 'companyName', 'company_name', 'company'),
            $this->fromArr($billingAddr, 'addressLine1', 'address_line1', 'street_1'),
            $this->fromArr($billingAddr, 'addressLine2', 'address_line2', 'street_2'),
            $this->fromArr($billingAddr, 'city', 'suburb'),
            $this->fromArr($billingAddr, 'state'),
            $this->fromArr($billingAddr, 'zip'),
            $this->fromArr($billingAddr, 'country'),
            $this->fromArr($billingAddr, 'phone'),
            $this->fromArr($billingAddr, 'email'),
            $this->attr($billing, 'first_name'),
            $this->attr($billing, 'last_name'),
            $this->attr($billing, 'company_name') ?: $this->attr($billing, 'company'),
            $this->attr($billing, 'address_line_1'),
            $this->attr($billing, 'address_line_2'),
            $this->attr($billing, 'city') ?: $this->attr($billing, 'suburb'),
            $this->attr($billing, 'state'),
            $this->attr($billing, 'zip') ?: $this->attr($billing, 'postal_code'),
            $this->attr($billing, 'country'),
            $this->attr($billing, 'phone'),
            $this->attr($billing, 'email'),
            $productDetails,
            '0',
            $channelId,
            $channelName,
            $this->str($order->fee_details ?? ''),
        ];
    }

    private function productDetails($order): string
    {
        if (!$order->items || $order->items->isEmpty()) {
            return '';
        }

        return $order->items->map(function ($item) {
            $product = $item->product;
            $qty     = (int) ($item->quantity ?? 0);
            $unit    = $this->num($item->price ?? optional($product)->price ?? 0);
            $total   = $this->num($qty * (float) $unit);
            $weight  = number_format($this->productWeight($product), 4, '.', '');

            return implode(', ', [
                'Product ID: ' . (optional($product)->id ?? $item->product_id ?? 0),
                'Product Qty: ' . $qty,
                'Product SKU: ' . $this->str(optional($product)->sku ?? $item->sku ?? ''),
                'Product Name: ' . $this->str(optional($product)->name ?? $item->name ?? ''),
                'Product Weight: ' . $weight,
                'Product Variation Details: ' . $this->str($item->variation_details ?? ''),
                'Product Unit Price: ' . number_format((float) $unit, 2, '.', ''),
                'Product Total Price: ' . number_format((float) $total, 2, '.', ''),
            ]);
        })->implode('|');
    }

    private function productWeight($product): float
    {
        if (!$product) {
            return 0;
        }

        $dimensions = $product->dimensions ?? null;

        if (is_string($dimensions) && $dimensions !== '') {
            $decoded    = json_decode($dimensions, true);
            $dimensions = is_array($decoded) ? $decoded : [];
        }

        if (is_object($dimensions)) {
            $dimensions = (array) $dimensions;
        }

        if (is_array($dimensions)) {
            return (float) ($dimensions['weight'] ?? 0);
        }

        return (float) ($product->weight ?? 0);
    }

    private function couponDetails($order, $discountAmt): string
    {
        $code = $this->str($order->coupon_code ?? '');
        $name = $this->str($order->coupon_name ?? $code);

        if ($code === '' && (float) $discountAmt <= 0) {
            return '';
        }

        $parts = [];
        if ($code !== '') {
            $parts[] = 'Coupon Code: ' . $code;
            $parts[] = 'Coupon Name: ' . ($name !== '' ? $name : $code);
        }
        if ((float) $discountAmt > 0) {
            $parts[] = 'Coupon Value: ' . number_format((float) $discountAmt, 2, '.', '');
        }

        return implode(', ', $parts);
    }

    private function json($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function fromArr(array $data, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== null && $data[$key] !== '') {
                return $this->str($data[$key]);
            }
        }

        return '';
    }

    private function billingName(array $addr, string $part): string
    {
        $first = $this->fromArr($addr, 'firstName', 'first_name');
        $last  = $this->fromArr($addr, 'lastName', 'last_name');

        if ($first === '' && $last === '') {
            $full = $this->fromArr($addr, 'name');
            if ($full === '') {
                return '';
            }
            $bits  = preg_split('/\s+/', $full, 2);
            $first = $bits[0] ?? '';
            $last  = $bits[1] ?? '';
        }

        return $part === 'first' ? $first : $last;
    }

    private function attr($model, string $key): string
    {
        if (!$model) {
            return '';
        }

        foreach (array_unique([$key, Str::snake($key), Str::camel($key)]) as $k) {
            $value = $model->getAttribute($k);
            if ($value !== null && $value !== '') {
                return $this->str($value);
            }
        }

        $bag = array_merge($model->getAttributes(), $model->toArray());
        foreach (array_unique([$key, Str::snake($key), Str::camel($key)]) as $k) {
            if (isset($bag[$k]) && $bag[$k] !== null && $bag[$k] !== '') {
                return $this->str($bag[$k]);
            }
        }

        return '';
    }

    private function str($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        return trim((string) $value);
    }

    private function num($value, $fallback = 0)
    {
        if ($value === null || $value === '' || $value === false) {
            return $fallback;
        }

        return is_numeric($value) ? $value + 0 : $fallback;
    }

    private function formatDate($value): string
    {
        if (!$value) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($value)->format('d/m/Y');
        } catch (\Exception $e) {
            return $this->str($value);
        }
    }
}
