<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Payment Invoice</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f9f9f9;">
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td align="center">
        <table width="600" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="margin-top: 20px; border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden;">
          
          <!-- Header -->
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
              <h2 style="text-align: center; margin-bottom: 25px;">Payment Invoice</h2>

              <!-- Order Info -->
              <table width="100%" cellpadding="0" cellspacing="0" style="font-size: 15px; color: #333333; border-collapse: collapse; margin-bottom: 30px;">
                <tr>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;"><strong>Order ID:</strong></td>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;">{{ $payment->id }}</td>
                </tr>
                <tr>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;"><strong>Amount:</strong></td>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;">${{ number_format($payment->total_amount, 2) }}</td>
                </tr>
                <tr>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;"><strong>Status:</strong></td>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;">{{ ucfirst($payment->status) }}</td>
                </tr>
                <tr>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;"><strong>Date:</strong></td>
                  <td style="padding: 8px; border-bottom: 1px solid #eeeeee;">{{ $payment->created_at->format('d M Y') }}</td>
                </tr>
                <tr>
                  <td style="padding: 8px;"><strong>Payment Method:</strong></td>
                  <td style="padding: 8px;">{{ ucfirst($payment->payment_method) }}</td>
                </tr>
              </table>

              <!-- Products -->
              <h3 style="margin: 20px 0 10px; font-size: 16px; border-bottom: 1px solid #ccc; padding-bottom: 5px;">Purchased Products</h3>

              <table width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; color: #333; border-collapse: collapse; margin-bottom: 30px;">
                <thead>
                  <tr style="background-color: #f9f9f9;">
                    <th align="left" style="padding: 10px; border: 1px solid #ddd;">Product</th>
                    <th align="center" style="padding: 10px; border: 1px solid #ddd;">Qty</th>
                    <th align="center" style="padding: 10px; border: 1px solid #ddd;">Unit Price</th>
                    <th align="right" style="padding: 10px; border: 1px solid #ddd;">Total</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach ($payment->items as $item)
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
                        } catch (\Exception $e) {}
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
                    <td style="padding: 10px; border: 1px solid #ddd;">
                      <div style="display: flex; align-items: center; gap: 10px;">
                        @if ($imageData)
                          <img src="data:{{ $mimeType }};base64,{{ $imageData }}" alt="Product Image" style="height: 40px; width: auto; vertical-align: middle;">
                        @endif
                        <span>{{ $item->product->name ?? 'N/A' }}</span>
                      </div>
                    </td>
                    <td align="center" style="padding: 10px; border: 1px solid #ddd;">{{ $item->quantity }}</td>
                    <td align="center" style="padding: 10px; border: 1px solid #ddd;">${{ number_format($item->price, 2) }}</td>
                    <td align="right" style="padding: 10px; border: 1px solid #ddd;">${{ number_format($item->quantity * $item->price, 2) }}</td>
                  </tr>
                  @endforeach
                </tbody>
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
              &copy; {{ now()->year }} Newtown Spares. All rights reserved.
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
