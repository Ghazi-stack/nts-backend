<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Complete Your Purchase</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #ffffff; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 0 auto; padding: 30px;">

        <!-- Logo -->
        <div style="text-align: center;">
            <img src="https://yourdomain.com/images/logo.png" alt="Newtown Spares Logo" style="max-width: 220px;">
        </div>

        <!-- Divider Icon -->
        <div style="text-align: center; margin: 30px 0;">
            <img src="https://cdn-icons-png.flaticon.com/512/1170/1170678.png" width="40" alt="cart icon" style="opacity: 0.4;">
        </div>

        <!-- Heading -->
        <h2 style="text-align: center;">Complete your purchase</h2>

        <p style="text-align: center;">
            Hi {{ $userName }},<br>
            You added the following items to your cart but didn’t finish checking out:
        </p>

        <!-- Item List -->
        @foreach ($cartItems as $item)
            <div style="display: flex; align-items: center; border-top: 1px solid #eee; padding: 20px 0;">
                <img src="{{ asset($item->product->image ?? 'product_images/default.png') }}"
                     alt="{{ $item->product->name ?? 'Product' }}"
                     style="width: 80px; margin-right: 20px; border: 1px solid #ddd;">
            
                <div style="flex: 1;">
                    <div style="font-weight: bold; font-size: 16px;">
                        {{ $item->product->name ?? 'Unnamed Product' }}
                    </div>
                    <div style="color: #666;">Price: Rs. {{ number_format($item->product->price ?? 0, 2) }}</div>
                </div>
            
                <div style="text-align: right;">Qty: {{ $item->quantity }}</div>
            </div>
        @endforeach

        <!-- Complete Order Button -->
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ url('/cart') }}" style="background: #ff5e14; color: #fff; padding: 12px 25px; text-decoration: none; font-weight: bold; border-radius: 4px;">
                Complete Order
            </a>
        </div>

        <!-- Footer -->
        <div style="text-align: center; font-size: 14px; color: #555;">
            <strong>New Town Spares Inc</strong><br>
            <a href="https://www.newtownspares.com" style="color: #555;">www.newtownspares.com</a><br><br>

            <a href="{{ url('/') }}" style="border: 1px solid #aaa; padding: 8px 15px; border-radius: 4px; font-weight: bold; color: #444; text-decoration: none;">
                Go Shopping
            </a><br><br>

            <a href="{{ url('/unsubscribe') }}" style="color: #007bff;">Unsubscribe</a>
        </div>
    </div>
</body>
</html>
