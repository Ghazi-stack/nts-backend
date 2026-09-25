<?php

namespace App\Exports;

use Illuminate\Support\Str;

class ShipmentExport
{
    public function headings(): array
    {
        return [
            'SHIPMENT ID',
            'DATE SHIPPED',
            'ORDER ID',
            'ORDER DATE',
            'TRACKING NO',
            'SHIPPING METHOD',
            'BILLING FULL NAME',
            'BILLING FIRST NAME',
            'BILLING LAST NAME',
            'BILLING COMPANY',
            'BILLING STREET 1',
            'BILLING STREET 2',
            'BILLING SUBURB',
            'BILLING STATE',
            'BILLING ZIP/POSTCODE',
            'BILLING COUNTRY',
            'BILLING PHONE',
            'SHIPPING FIRST NAME',
            'SHIPPING LAST NAME',
            'SHIPPING FULL NAME',
            'SHIPPING COMPANY',
            'SHIPPING STREET 1',
            'SHIPPING STREET 2',
            'SHIPPING SUBURB',
            'SHIPPING STATE',
            'SHIPPING ZIP',
            'SHIPPING COUNTRY',
            'SHIPPING PHONE',
            'SHIPMENT ITEMS',
        ];
    }

    public function map($shipment): array
    {
        $order   = $shipment->order;
        $billing = $order?->billingInformation;
        $billArr = $this->json($order->billing_address ?? $order->billingAddress ?? null);
        $shipArr = $this->json(
            $order->shipping_address
                ?? $order->shippingAddress
                ?? $shipment->shipped_to
                ?? null
        );

        if (empty($shipArr)) {
            $shipArr = $billArr;
        }

        $billFirst = $this->billingName($billArr, 'first') ?: $this->attr($billing, 'first_name');
        $billLast  = $this->billingName($billArr, 'last') ?: $this->attr($billing, 'last_name');
        $billFull  = trim($billFirst . ' ' . $billLast);

        $shipFirst = $this->billingName($shipArr, 'first') ?: $this->attr($billing, 'first_name');
        $shipLast  = $this->billingName($shipArr, 'last') ?: $this->attr($billing, 'last_name');
        $shipFull  = trim($shipFirst . ' ' . $shipLast);

        $method = $shipment->shipping_method
            ?: $this->attr($billing, 'shipping_method')
            ?: $this->str($order->shipping_method ?? '')
            ?: 'None';

        return [
            $shipment->id,
            $this->prettyDate($shipment->date_shipped),
            $shipment->order_id ?? $order?->id ?? '',
            $this->prettyDate($order?->created_at),
            $this->str($shipment->tracking_number ?? ''),
            $method,
            $billFull,
            $billFirst,
            $billLast,
            $this->fromArr($billArr, 'companyName', 'company_name', 'company') ?: $this->attr($billing, 'company_name') ?: $this->attr($billing, 'company'),
            $this->fromArr($billArr, 'addressLine1', 'address_line1', 'street_1') ?: $this->attr($billing, 'address_line_1'),
            $this->fromArr($billArr, 'addressLine2', 'address_line2', 'street_2') ?: $this->attr($billing, 'address_line_2'),
            $this->fromArr($billArr, 'city', 'suburb') ?: $this->attr($billing, 'city') ?: $this->attr($billing, 'suburb'),
            $this->fromArr($billArr, 'state') ?: $this->attr($billing, 'state'),
            $this->fromArr($billArr, 'zip', 'postal_code') ?: $this->attr($billing, 'zip') ?: $this->attr($billing, 'postal_code'),
            $this->fromArr($billArr, 'country') ?: $this->attr($billing, 'country'),
            $this->fromArr($billArr, 'phone') ?: $this->attr($billing, 'phone'),
            $shipFirst,
            $shipLast,
            $shipFull,
            $this->fromArr($shipArr, 'companyName', 'company_name', 'company') ?: $this->attr($billing, 'company_name'),
            $this->fromArr($shipArr, 'addressLine1', 'address_line1', 'street_1') ?: $this->attr($billing, 'address_line_1'),
            $this->fromArr($shipArr, 'addressLine2', 'address_line2', 'street_2') ?: $this->attr($billing, 'address_line_2'),
            $this->fromArr($shipArr, 'city', 'suburb') ?: $this->attr($billing, 'city'),
            $this->fromArr($shipArr, 'state') ?: $this->attr($billing, 'state'),
            $this->fromArr($shipArr, 'zip', 'postal_code') ?: $this->attr($billing, 'zip'),
            $this->fromArr($shipArr, 'country') ?: $this->attr($billing, 'country'),
            $this->fromArr($shipArr, 'phone') ?: $this->attr($billing, 'phone'),
            $this->shipmentItems($order),
        ];
    }

    private function shipmentItems($order): string
    {
        if (!$order || !$order->items || $order->items->isEmpty()) {
            return '';
        }

        $parts = [];

        foreach ($order->items as $item) {
            $product = $item->product;
            $id      = optional($product)->id ?? $item->product_id ?? 0;
            $name    = $this->str(optional($product)->name ?? $item->name ?? '');
            $sku     = $this->str(optional($product)->sku ?? $item->sku ?? '');
            $qty     = (int) ($item->quantity ?? 0);

            $parts[] = $id . '|' . $name . '|' . $sku . '|' . $qty;
        }

        return implode(';', $parts);
    }

    private function prettyDate($value): string
    {
        if (!$value) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($value)->format('M jS Y');
        } catch (\Throwable $e) {
            return $this->str($value);
        }
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
            if (is_array($decoded)) {
                return $decoded;
            }
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

        return '';
    }

    private function str($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        return trim((string) $value);
    }
}
