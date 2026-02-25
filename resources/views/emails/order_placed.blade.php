<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Confirmation</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 30px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header {
            background-color: #4F46E5;
            color: white;
            padding: 24px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
        }
        .body {
            padding: 30px;
            color: #333333;
        }
        .body p {
            font-size: 15px;
            line-height: 1.6;
        }
        .order-box {
            background-color: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
        }
        .order-box table {
            width: 100%;
            border-collapse: collapse;
        }
        .order-box table td {
            padding: 8px 0;
            font-size: 15px;
        }
        .order-box table td:first-child {
            color: #666666;
            width: 50%;
        }
        .order-box table td:last-child {
            font-weight: bold;
            color: #111111;
        }
        .footer {
            background-color: #f0f0f0;
            text-align: center;
            padding: 16px;
            font-size: 13px;
            color: #888888;
        }
    </style>
</head>
<body>
    <div class="container">

        <div class="header">
            <h1>Order Confirmation</h1>
        </div>

        <div class="body">
            <p>Dear <strong>{{ $customer->first_name ?? '' }} {{ $customer->last_name ?? '' }}</strong>,</p>
            <p>Thank you! Your order has been placed successfully. Here are your order details:</p>

            <div class="order-box">
                <table>
                    <tr>
                        <td>Order Number:</td>
                        <td>{{ $order['orderNumber'] ?? 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td>Total Amount:</td>
                        <td>${{ $order['totalAmount'] ?? $order['total_amount'] ?? '0.00' }}</td>
                    </tr>
                    <tr>
                        <td>Status:</td>
                        <td>{{ $order['status'] ?? 'N/A' }}</td>
                    </tr>
                </table>
            </div>

            <p>If you have any questions about your order, feel free to contact us.</p>
            <p>Thank you for shopping with us!</p>
        </div>

        <div class="footer">
            &copy; {{ date('Y') }} Your Store. All rights reserved.
        </div>

    </div>
</body>
</html>