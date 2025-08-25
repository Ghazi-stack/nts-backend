<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Invoice</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4; font-family: Arial, sans-serif;">

  <div style="max-width: 800px; margin: 40px auto; background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);">

    <!-- Header -->
    <div style="background-color: #ff3d1f; padding: 30px 20px; text-align: center;">
      <img src="{{ $logoBase64 }}" alt="Newtown Spares" style="max-width: 180px;">
    </div>

    <!-- Invoice Title -->
    <div style="padding: 30px 40px 10px 40px; text-align: center;">
      <h2 style="color: #8B3EFF; margin: 0; font-size: 24px;">Payment Invoice</h2>
    </div>

    <!-- Order Details -->
    <div style="padding: 10px 40px 30px 40px;">
      <table width="100%" style="font-size: 14px; color: #333;">
        <tr>
          <td style="padding: 8px 0;"><strong>Order Number:</strong></td>
          <td style="padding: 8px 0;">{{ $order->order_number }}</td>
        </tr>
        <tr>
          <td style="padding: 8px 0;"><strong>Total Amount:</strong></td>
          <td style="padding: 8px 0;">${{ number_format($order->total_amount, 2) }}</td>
        </tr>
        <tr>
          <td style="padding: 8px 0;"><strong>Status:</strong></td>
          <td style="padding: 8px 0;">{{ ucfirst($order->status) }}</td>
        </tr>
        <tr>
          <td style="padding: 8px 0;"><strong>Date:</strong></td>
          <td style="padding: 8px 0;">{{ \Carbon\Carbon::parse($order->created_at)->format('d M Y') }}</td>
        </tr>
        <tr>
          <td style="padding: 8px 0;"><strong>Payment Method:</strong></td>
          <td style="padding: 8px 0;">{{ ucfirst($order->payment_method) }}</td>
        </tr>
      </table>
    </div>

    <!-- Product List -->
    <div style="padding: 0 40px 30px 40px;">
      <h3 style="margin-bottom: 10px; font-size: 16px;">Products:</h3>
      <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse: collapse; font-size: 13px;">
        <thead>
          <tr style="background-color: #f5f5f5;">
            <th align="left" style="border: 1px solid #ddd;">Product</th>
            <th align="center" style="border: 1px solid #ddd;">Qty</th>
            <th align="right" style="border: 1px solid #ddd;">Unit Price</th>
            <th align="right" style="border: 1px solid #ddd;">Total</th>
          </tr>
        </thead>
        <tbody>
          @foreach($order->items as $item)
            <tr>
              <td style="border: 1px solid #ddd;">{{ $item->product->name ?? 'Deleted Product' }}</td>
              <td align="center" style="border: 1px solid #ddd;">{{ $item->quantity }}</td>
              <td align="right" style="border: 1px solid #ddd;">${{ number_format($item->price, 2) }}</td>
              <td align="right" style="border: 1px solid #ddd;">${{ number_format($item->price * $item->quantity, 2) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <!-- Footer -->
    <div style="text-align: center; font-size: 12px; color: #777; padding: 20px;">
      <p>This is a system-generated invoice. No signature is required.</p>
      <p style="margin-top: 5px;">&copy; {{ date('Y') }} Newtown Spares. All rights reserved.</p>
    </div>

  </div>

</body>
</html>
