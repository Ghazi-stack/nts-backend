<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice - {{ $order->order_number }}</title>
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

        .invoice-bar {
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

        .totals-section {
            width: 100%;
            margin-top: 5px;
            border-top: 1px solid #ccc;
        }

        .totals-section td {
            padding: 4px 8px;
            font-size: 11px;
        }

        .totals-section .label {
            text-align: right;
            width: 80%;
        }

        .totals-section .value {
            text-align: right;
            width: 20%;
        }

        .totals-section .grand-total td {
            font-weight: bold;
            font-size: 12px;
            border-top: 1px solid #000;
            padding-top: 6px;
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

    {{-- Invoice Title Bar --}}
    <div class="invoice-bar">
        {{ $store?->name ?? 'Store' }} invoice for order #{{ $order->order_number }}
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

        $rawPayment = $shipping->payment_method ?? 'N/A';
        $paymentLabels = [
            'credit_card' => 'Credit Card',
            'stripe_card' => 'Credit Card (Stripe)',
            'paypal_express' => 'PayPal Express',
            'cash_on_delivery' => 'Cash on Delivery',
            'bank_transfer' => 'Bank Transfer',
            'cod' => 'Cash on Delivery',
            'stripe' => 'Stripe',
        ];
        $paymentMethod = $paymentLabels[$rawPayment] ?? ucwords(str_replace('_', ' ', $rawPayment));
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
                        {{ $billing['country'] ?? '' }}<br><br>
                        @if(!empty($billing['phone']))Phone: {{ $billing['phone'] }}<br>@endif
                        @if(!empty($billing['email']))Email: {{ $billing['email'] }}@endif
                    @else
                        <strong>{{ $shipping->first_name ?? '' }} {{ $shipping->last_name ?? '' }}</strong><br>
                        @if($shipping->company_name){{ $shipping->company_name }}<br>@endif
                        {{ $shipping->address_line_1 ?? '' }}<br>
                        @if($shipping->address_line_2){{ $shipping->address_line_2 }}<br>@endif
                        {{ $shipping->city ?? '' }}{{ $shipping->state ? ', ' . $shipping->state : '' }} {{ $shipping->zip ?? '' }}<br>
                        {{ $shipping->country ?? '' }}<br><br>
                        @if($shipping->phone)Phone: {{ $shipping->phone }}<br>@endif
                        @if($shipping->email)Email: {{ $shipping->email }}@endif
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
                    {{ $shipping->country ?? '' }}<br><br>
                    @if($shipping->phone)Phone: {{ $shipping->phone }}<br>@endif
                    @if($shipping->email)Email: {{ $shipping->email }}@endif
                </div>
            </td>
        </tr>
    </table>

    {{-- Order Info --}}
    <table class="order-info">
        <tr>
            <td><strong>Order:</strong></td>
            <td>#{{ $order->order_number }}</td>
            <td><strong>Order Date:</strong></td>
            <td>{{ optional($order->created_at)->format('M d, Y') }}</td>
        </tr>
        <tr>
            <td><strong>Payment Method:</strong></td>
            <td>{{ $paymentMethod }} (${{ number_format($order->total_amount, 2) }})</td>
            <td><strong>Shipping Method:</strong></td>
            <td>{{ $shipping->shipping_method ?? 'N/A' }}</td>
        </tr>
    </table>

    {{-- Order Items --}}
    <div class="items-title">Order Items</div>

    <table class="items-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 35px;">Qty</th>
                <th style="width: 90px;">Code/SKU</th>
                <th>Product Name</th>
                <th class="text-right" style="width: 95px;">Price</th>
                <th class="text-right" style="width: 95px;">Total</th>
            </tr>
        </thead>
        <tbody>
            @php $subtotal = 0; @endphp
            @foreach ($order->items as $item)
                @php
                    $lineTotal = $item->price * $item->quantity;
                    $subtotal += $lineTotal;
                @endphp
                <tr>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td>{{ $item->product->sku ?? 'N/A' }}</td>
                    <td>{{ $item->product->name ?? 'N/A' }}</td>
                    <td class="text-right">${{ number_format($item->price, 2) }} USD</td>
                    <td class="text-right">${{ number_format($lineTotal, 2) }} USD</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totals --}}
    <table class="totals-section">
        <tr>
            <td class="label">Subtotal:</td>
            <td class="value">${{ number_format($subtotal, 2) }} USD</td>
        </tr>
        <tr>
            <td class="label">Shipping:</td>
            <td class="value">${{ number_format($order->shipping_cost ?? 0, 2) }} USD</td>
        </tr>
        @if($order->discountAmount > 0)
        <tr>
            <td class="label">Discount:</td>
            <td class="value">-${{ number_format($order->discountAmount, 2) }} USD</td>
        </tr>
        @endif
        <tr>
            <td class="label">Tax:</td>
            <td class="value">$0.00 USD</td>
        </tr>
        <tr class="grand-total">
            <td class="label">Grand total:</td>
            <td class="value">${{ number_format($order->total_amount ?? 0, 2) }} USD</td>
        </tr>
    </table>

</body>
</html>