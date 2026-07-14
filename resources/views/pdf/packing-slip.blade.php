<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Packing Slip</title>
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

        h3 {
            margin-bottom: 10px;
            font-size: 18px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }

        .product-table {
            width: 100%;
            border-collapse: collapse;
        }

        .product-table th,
        .product-table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
            vertical-align: middle;
        }

        .product-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .product-info img {
            height: 40px;
            width: auto;
            /* border: 1px solid #ccc; */
            padding: 2px;
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

        @media print {
            body {
                margin: 0;
            }
        }
    </style>
</head>

<body>

    <div class="header">
        @if($logoBase64)
        <img src="{{ $logoBase64 }}" alt="Store Logo" height="60">
        @else
        <p style="font-size: 14px; color: #999;">Logo not found</p>
        @endif
        <div class="title">Packing Slip</div>
    </div>

    <table class="info-table">
        <tr>
            <td><strong>Order Number:</strong> {{ $order->order_number }}</td>
            <td><strong>Order Date:</strong> {{ optional($order->created_at)->format('d M Y') }}</td>
        </tr>
        <tr>
            <td><strong>Customer:</strong> {{ optional($order->billingInformation)->first_name }} {{ optional($order->billingInformation)->last_name }}</td>
            <td><strong>Phone:</strong> {{ optional($order->billingInformation)->phone ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td><strong>Email:</strong> {{ optional($order->billingInformation)->email ?? 'N/A' }}</td>
            <td><strong>Shipping Address:</strong>
                {{ $order->billingInformation->address_line_1 }},
                {{ $order->billingInformation->city }},
                {{ $order->billingInformation->state }},
                {{ $order->billingInformation->country }}
            </td>
        </tr>
        <tr>
            <td><strong>Tracking #:</strong> {{ $shipment->tracking_number ?? 'N/A' }}</td>
            <td><strong>Shipped On:</strong> {{ optional($shipment->date_shipped) ? \Carbon\Carbon::parse($shipment->date_shipped)->format('d M Y') : 'N/A' }}</td>
        </tr>
    </table>

    <h3>Ordered Products</h3>

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
                <td>{{ $item->quantity ?? 0 }}</td>
                <td>${{ number_format($item->price ?? 0, 2) }}</td>
                <td class="text-right">${{ number_format(($item->price ?? 0) * ($item->quantity ?? 0), 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total">
        Total Amount: ${{ number_format($order->total_amount ?? 0, 2) }}
    </div>

    <div class="footer">
        This is a system-generated document. No signature required.
    </div>

</body>

</html>