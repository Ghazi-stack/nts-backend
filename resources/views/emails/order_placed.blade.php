<!DOCTYPE html>
<html>
<head>
    <title>Order Confirmation</title>
</head>
<body>
    <h2>Thank you for your order!</h2>
    <p>Dear {{ $customer->first_name }} {{ $customer->last_name }},</p>
    <p>Your order has been placed successfully.</p>
    <p><strong>Order Number:</strong> {{ $order['orderNumber'] }}</p>
    <p><strong>Total Amount:</strong> ${{ $order['totalAmount'] }}</p>
    <p><strong>Status:</strong> {{ $order['status'] }}</p>
    <br>
    <p>Thank you for shopping with us!</p>
</body>
</html>