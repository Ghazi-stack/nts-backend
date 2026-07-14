<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Packing Slip - {{ $order->order_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 11px;
            color: #333;
            padding: 30px;
            background-color: #fff;
        }

        .logo-section {
            text-align: left;
            margin-bottom: 5px;
            padding-bottom: 8px;
            border-bottom: 3px solid #000;
        }

        .logo-section img {
            max-height: 55px;
            max-width: 250px;
        }

        .logo-section .store-name {
            font-size: 28px;
            font-weight: bold;
            color: #000;
        }

        .slip-bar {
            background-color: #e8e8e8;
            padding: 6px 10px;
            margin-bottom: 12px;
            font-size: 13px;
            font-weight: bold;
            color: #000;
        }

        .store-address {
            margin-bottom: 15px;
            font-size: 11px;
            line-height: 1.5;
        }

        .store-address strong {
            font-size: 11px;
        }

        .two-column {
            width: 100%;
            margin-bottom: 15px;
        }

        .two-column td {
            width: 50%;
            vertical-align: top;
            padding: 0;
        }

        .two-column td:first-child {
            padding-right: 15px;
        }

        .two-column td:last-child {
            padding-left: 15px;
        }

        .section-title {
            font-size: 13px;
            font-weight: bold;
            text-decoration: underline;
            margin-bottom: 6px;
        }

        .address-block {
            font-size: 11px;
            line-height: 1.6;
        }

        .order-info {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }

        .order-info td {
            padding: 5px 8px;
            border: 1px solid #ccc;
            font-size: 11px;
            width: 25%;
        }

        .order-info td strong {
            font-size: 11px;
        }

        .items-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 6px;
            padding-bottom: 4px;
            border-bottom: 2px solid #000;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
        }

        .items-table th {
            border-bottom: 2px solid #000;
            padding: 6px 8px;
            text-align: left;
            font-size: 11px;
            font-weight: bold;
        }

        .items-table td {
            padding: 8px 8px;
            font-size: 11px;
            vertical-align: top;
            border-bottom: 1px solid #eee;
        }

        .items-table .text-right {
            text-align: right;
        }

        .items-table .text-center {
            text-align: center;
        }
    </style>
</head>

<body>

    {{-- Logo --}}
    <div class="logo-section">
        @if($logoBase64)
        <img src="{{ $logoBase64 }}" alt="{{ $store?->name ?? 'Store' }} Logo">
        @else
        <span class="store-name">{{ $store?->name ?? 'Store' }}</span>
        @endif
    </div>

    {{-- Packing Slip Title Bar --}}
    <div class="slip-bar">
        {{ $store?->name ?? 'Store' }} packing slip for order #{{ $order->order_number }}
    </div>

    {{-- Store Address --}}
    @if($store && ($store->address || $store->city))
    <div class="store-address">
        <strong>Address:</strong><br>
        @if($store->address){{ $store->address }}<br>@endif
        @if($store->city || $store->state || $store->zip)
        {{ $store->city ?? '' }}{{ $store->state ? ', ' . $store->state : '' }} {{ $store->zip ?? '' }}
        @endif
    </div>
    @endif

    {{-- Billing & Shipping Side by Side --}}
    @php
    $billing = $order->billing_address ?? [];
    $shipping = $order->billingInformation;
    @endphp

    <table class="two-column">
        <tr>
            <td>
                <div class="section-title">Billing Details</div>
                <div class="address-block">
                    @if(!empty($billing))
                    <strong>{{ $billing['name'] ?? '' }}</strong><br>
                    @if(!empty($billing['company'])){{ $billing['company'] }}<br>@endif
                    {{ $billing['addressLine1'] ?? '' }}<br>
                    @if(!empty($billing['addressLine2'])){{ $billing['addressLine2'] }}<br>@endif
                    {{ $billing['city'] ?? '' }}{{ !empty($billing['state']) ? ', ' . $billing['state'] : '' }} {{ $billing['zip'] ?? '' }}<br>
                    {{ $billing['country'] ?? '' }}<br>
                    @if(!empty($billing['phone']))Phone: {{ $billing['phone'] }}@endif
                    @else
                    <strong>{{ $shipping->first_name ?? '' }} {{ $shipping->last_name ?? '' }}</strong><br>
                    @if($shipping->company_name){{ $shipping->company_name }}<br>@endif
                    {{ $shipping->address_line_1 ?? '' }}<br>
                    @if($shipping->address_line_2){{ $shipping->address_line_2 }}<br>@endif
                    {{ $shipping->city ?? '' }}{{ $shipping->state ? ', ' . $shipping->state : '' }} {{ $shipping->zip ?? '' }}<br>
                    {{ $shipping->country ?? '' }}<br>
                    @if($shipping->phone)Phone: {{ $shipping->phone }}@endif
                    @endif
                </div>
            </td>
            <td>
                <div class="section-title">Shipping Details</div>
                <div class="address-block">
                    <strong>{{ $shipping->first_name ?? '' }} {{ $shipping->last_name ?? '' }}</strong><br>
                    @if($shipping->company_name){{ $shipping->company_name }}<br>@endif
                    {{ $shipping->address_line_1 ?? '' }}<br>
                    @if($shipping->address_line_2){{ $shipping->address_line_2 }}<br>@endif
                    {{ $shipping->city ?? '' }}{{ $shipping->state ? ', ' . $shipping->state : '' }} {{ $shipping->zip ?? '' }}<br>
                    {{ $shipping->country ?? '' }}<br>
                    @if($shipping->phone)Phone: {{ $shipping->phone }}@endif
                </div>
            </td>
        </tr>
    </table>

    {{-- Order Info --}}
    <table class="order-info">
        <tr>
            <td><strong>Order:</strong></td>
            <td>#{{ $order->order_number }}</td>
            <td><strong>Shipping Method:</strong></td>
            <td>{{ $shipping->shipping_method ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td><strong>Order Date:</strong></td>
            <td>{{ optional($order->created_at)->format('M d, Y') }}</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
        </tr>
    </table>

    {{-- Shipped Items --}}
    <div class="items-title">Shipped Items</div>

    <table class="items-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 35px;">Qty</th>
                <th style="width: 90px;">Code/SKU</th>
                <th>Product Name</th>
                <th class="text-right" style="width: 80px;">Bin Picking No.</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
            <tr>
                <td class="text-center">{{ $item->quantity }}</td>
                <td>{{ $item->product->sku ?? 'N/A' }}</td>
                <td>{{ $item->product->name ?? 'N/A' }}</td>
                <td class="text-right">{{ $item->product->identifiers->bpn ?? 'N/A' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

</body>

</html>