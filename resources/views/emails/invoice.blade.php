<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Payment Invoice</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f9f9f9;">
  <table width="100%" cellspacing="0" cellpadding="0" bgcolor="#f9f9f9">
    <tr>
      <td align="center">
        <table width="600" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="margin-top: 20px; border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden;">
          
          <!-- Top Banner with Logo -->
          <tr bgcolor="#ff3d1f">
            <td align="center" style="padding: 20px;">
              <div style="background-color: white; padding: 10px 20px; display: inline-block; border-radius: 8px;">
                <img src="https://cdn11.bigcommerce.com/s-4jpol1blth/images/stencil/250x70/nts-newlogo_nts_1682517516__50628.original.png" alt="Newtown Spares Logo" width="180" style="display: block;" />
              </div>
            </td>
          </tr>

          <!-- Email Body -->
          <tr>
            <td style="padding: 30px;">
              <h2 style="color: #8B3EFF; text-align: center; margin-bottom: 25px;">Payment Invoice</h2>

              <table width="100%" cellpadding="0" cellspacing="0" style="font-size: 15px; color: #333333; border-collapse: collapse; margin-bottom: 30px;">
                <tr>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;"><strong>Payment ID:</strong></td>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;">{{ $payment->id }}</td>
                </tr>
                <tr>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;"><strong>Amount:</strong></td>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;">${{ number_format($payment->amount, 2) }}</td>
                </tr>
                <tr>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;"><strong>Status:</strong></td>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;">{{ ucfirst($payment->payment_status) }}</td>
                </tr>
                <tr>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;"><strong>Date:</strong></td>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;">{{ $payment->created_at->format('d M Y') }}</td>
                </tr>
                @if ($payment->method)
                <tr>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;"><strong>Payment Method:</strong></td>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;">{{ ucfirst($payment->method) }}</td>
                </tr>
                @endif
              </table>

              <p style="font-size: 14px; color: #555555; text-align: center;">
                This is a system-generated invoice. No signature is required.<br />
                Thank you for your business!
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr bgcolor="#f0f0f0">
            <td align="center" style="font-size: 13px; color: #777777; padding: 20px;">
              &copy; {{ date('Y') }} Newtown Spares. All rights reserved.<br />
              <a href="https://newtownspares.com/unsubscribe" style="color: #777777;">Unsubscribe</a>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
