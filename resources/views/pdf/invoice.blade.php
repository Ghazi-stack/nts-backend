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
            position: relative;
            z-index: 0;
        }

        body::before {
            content: "";
            position: fixed;
            top: 50%;
            left: 50%;
            width: 100%;
            height: 100%;
            /* background-image: url('{{ $logoBase64 }}'); */
            background-repeat: no-repeat;
            background-position: center;
            background-size: 80%;
            opacity: 0.08;
            transform: translate(-50%, -50%);
            z-index: -1;
        }

        .header {
            text-align: center;
            padding: 20px 0;
            margin-bottom: 30px;
            border-bottom: 2px solid #e0e0e0;
        }

        .header img {
            max-height: 60px;
        }

        .title {
            font-size: 26px;
            font-weight: bold;
            color: #FF5722;
            margin-top: 10px;
            letter-spacing: 1px;
        }

        .info-table {
            width: 100%;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 15px;
            background-color: #fff;
            margin-bottom: 30px;
        }

        .info-table td {
            padding: 8px 10px;
            vertical-align: top;
        }

        .info-table td strong {
            color: #444;
            display: inline-block;
            width: 130px;
        }

        h3 {
            font-size: 18px;
            margin-bottom: 10px;
            color: #444;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 5px;
        }

        .product-table {
            width: 100%;
            border-collapse: collapse;
            background-color: #fff;
            border: 1px solid #ddd;
        }

        .product-table th {
            background-color: #f9f9f9;
            color: #444;
            font-weight: 600;
            padding: 12px 10px;
            border: 1px solid #ddd;
        }

        .product-table td {
            padding: 10px;
            border: 1px solid #ddd;
        }

        .text-right {
            text-align: right;
        }

        .total {
            margin-top: 15px;
            text-align: right;
            font-weight: bold;
            font-size: 16px;
            color: #222;
        }

        .footer {
            font-size: 11px;
            color: #999;
            text-align: center;
            margin-top: 50px;
        }

        @media print {
            body {
                background-color: #fff;
                margin: 0;
            }

            body::before {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

    <div class="header">
        <img src="{{ public_path('logo/nts-logo.png') }}" alt="NTS Logo">
        <div class="title">Payment Invoice</div>
    </div>

    <table class="info-table">
        <tr>
            <td><strong>Order Number:</strong> {{ $order->order_number ?? $order->id }}</td>
            <td><strong>Date:</strong> {{ $order->created_at->format('d M Y') }}</td>
        </tr>
        <tr>
            <td><strong>Status:</strong> {{ ucfirst($order->status) }}</td>
            <td><strong>Payment Method:</strong> {{ ucfirst($order->payment_method) }}</td>
        </tr>
        <tr>
            <td><strong>Customer:</strong> {{ $order->name }}</td>
            <td><strong>Phone:</strong> {{ $order->phone }}</td>
        </tr>
        <tr>
            <td><strong>Email:</strong> {{ $order->email }}</td>
            <td><strong>Billing Address:</strong> {{ $order->address }}</td>
        </tr>
    </table>

    <h3>Purchased Products</h3>

    <table class="product-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->product->name ?? 'N/A' }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>${{ number_format($item->price, 2) }}</td>
                    <td class="text-right">${{ number_format($item->price * $item->quantity, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total">
        Total Paid: ${{ number_format($order->total_amount, 2) }}
    </div>

    <div class="footer">
        This is a system-generated invoice. No signature required.
    </div>

</body>
</html>
