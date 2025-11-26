<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Invoice</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            font-size: 13px;
            color: #333;
            margin: 40px;
            background-color: #fff;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .title {
            font-size: 24px;
            font-weight: bold;
            margin-top: 10px;
        }

        .info-table {
            width: 100%;
            border: 1px solid #ddd;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        .info-table td {
            padding: 8px 10px;
            border: 1px solid #ddd;
        }

        .product-table {
            width: 100%;
            border-collapse: collapse;
        }

        .product-table th, .product-table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .product-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .product-info img {
            height: 20px;
            width: 20px;
            /* object-fit: contain; */
            border: 1px solid #ccc;
            padding: 2px;
            background-color: #fff;
        }

        .text-right {
            text-align: right;
        }

        .total {
            text-align: right;
            font-weight: bold;
            font-size: 16px;
            margin-top: 20px;
        }

        .footer {
            font-size: 11px;
            color: #999;
            text-align: center;
            margin-top: 40px;
        }
    </style>
</head>
<body>

<div class="header">
    <img src="{{ $logoBase64 }}" alt="Logo" height="60">
    <div class="title">Payment Invoice</div>
</div>

<table class="info-table">
    <tr>
        <td><strong>Order Number:</strong> {{ $order->order_number }}</td>
        <td><strong>Date:</strong> {{ optional($order->created_at)->format('d M Y') }}</td>
    </tr>
    <tr>
        <td><strong>Status:</strong> {{ ucfirst($order->status ?? 'Pending') }}</td>
        @php
            $rawPayment = $order->billingInformation->payment_method ?? 'N/A';

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

<td><strong>Payment Method:</strong> {{ $paymentMethod }}</td>
    </tr>
    <tr>
        <td><strong>Customer:</strong> {{ $order->billingInformation->first_name }} {{ $order->billingInformation->last_name }}</td>
        <td><strong>Phone:</strong> {{ $order->billingInformation->phone }}</td>
    </tr>
    <tr>
        <td><strong>Email:</strong> {{ $order->billingInformation->email }}</td>
        <td><strong>Billing Address:</strong>
            {{ $order->billingInformation->address_line_1 }},
            {{ $order->billingInformation->city }},
            {{ $order->billingInformation->state }},
            {{ $order->billingInformation->country }}
        </td>
    </tr>
</table>

<h3>Purchased Products</h3>

<table class="product-table">
    <thead>
        <tr>
            <th>Serial No</th>
            <th>Product</th>
            <th>Qty</th>
            <th>Unit Price</th>
            <th class="text-right">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($order->items as $index => $item)
            @php
                $imageData = null;
                $mimeType = 'image/jpeg';

                $image = $item->product->images->first()->path ?? null;

                if ($image) {
                    if (filter_var($image, FILTER_VALIDATE_URL)) {
                        try {
                            $imgContent = file_get_contents($image);
                            if ($imgContent !== false) {
                                $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($imgContent) ?: 'image/jpeg';
                                $imageData = base64_encode($imgContent);
                            }
                        } catch (\Exception $e) {
                            // fail silently
                        }
                    } else {
                        $imagePath = public_path($image);
                        if (file_exists($imagePath)) {
                            $imgContent = file_get_contents($imagePath);
                            $mimeType = mime_content_type($imagePath);
                            $imageData = base64_encode($imgContent);
                        }
                    }
                }

                // fallback
                if (!$imageData && file_exists(public_path('no-image.png'))) {
                    $imgContent = file_get_contents(public_path('no-image.png'));
                    $mimeType = 'image/png';
                    $imageData = base64_encode($imgContent);
                }
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>
                    <div class="product-info">
                        @if ($imageData)
                            <img src="data:{{ $mimeType }};base64,{{ $imageData }}" alt="Product Image">
                        @endif
                        <span>{{ $item->product->name ?? 'N/A' }}</span>
                    </div>
                </td>
                <td>{{ $item->quantity }}</td>
                <td>${{ number_format($item->price, 2) }}</td>
                <td class="text-right">${{ number_format($item->price * $item->quantity, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="total">
    Total Paid: ${{ number_format($order->total_amount ?? 0, 2) }}
</div>

<div class="footer">
    This is a system-generated invoice. No signature required.
</div>

</body>
</html>
