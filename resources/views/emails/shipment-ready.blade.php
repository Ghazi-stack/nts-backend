<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Order Shipment Notification</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f9f9f9;">
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td align="center">
        <table width="600" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="margin-top: 20px; border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden;">
          
          <!-- Header with Logo -->
          <tr bgcolor="white">
            <td align="center" style="padding: 20px;">
              <div style="background-color: white; padding: 10px 20px; display: inline-block; border-radius: 8px;">
                <img src="https://cdn11.bigcommerce.com/s-4jpol1blth/images/stencil/250x70/nts-newlogo_nts_1682517516__50628.original.png" alt="Newtown Spares Logo" width="180" />
              </div>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding: 30px;">
              <h2 style="text-align: center; margin-bottom: 25px;">Your Order is Ready to Ship</h2>

              <!-- Order Info -->
              <table width="100%" cellpadding="0" cellspacing="0" style="font-size: 15px; color: #333333; border-collapse: collapse; margin-bottom: 30px;">
                <tr>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;"><strong>Order ID:</strong></td>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;">{{ $order->order_number }}</td>
                </tr>
                <tr>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;"><strong>Customer Name:</strong></td>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;">{{ $order->name }}</td>
                </tr>
                <tr>
                  <td style="padding: 8px;"><strong>Email:</strong></td>
                  <td style="padding: 8px;">{{ $order->email }}</td>
                </tr>
              </table>

              <!-- Shipment Info -->
              <h3 style="margin: 20px 0 10px; font-size: 16px; border-bottom: 1px solid #ccc; padding-bottom: 5px;">Shipment Details</h3>

              <table width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; color: #333; border-collapse: collapse; margin-bottom: 30px;">
                <tr>
                  <td style="padding: 10px; border: 1px solid #ddd;"><strong>Tracking Number:</strong></td>
                  <td style="padding: 10px; border: 1px solid #ddd;">{{ $shipment->tracking_number }}</td>
                </tr>
                <tr>
                  <td style="padding: 10px; border: 1px solid #ddd;"><strong>Shipping To:</strong></td>
                  <td style="padding: 10px; border: 1px solid #ddd;">{{ $shipment->shipped_to }}</td>
                </tr>
                <tr>
                  <td style="padding: 10px; border: 1px solid #ddd;"><strong>Shipped Date:</strong></td>
                  <td style="padding: 10px; border: 1px solid #ddd;">{{ \Carbon\Carbon::parse($shipment->date_shipped)->format('d M Y') }}</td>
                </tr>
              </table>

              <p style="font-size: 14px; color: #555;">
                You will receive another update once your order is out for delivery.<br>
                Thank you for shopping with <strong>Newtown Spares</strong>!
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr bgcolor="#f0f0f0">
            <td align="center" style="font-size: 13px; color: #777777; padding: 20px;">
              &copy; {{ now()->year }} Newtown Spares. All rights reserved.
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
