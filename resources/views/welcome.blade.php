<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $store?->name ?? 'Store' }}</title>
</head>

<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f9f9f9;">
    <table width="100%" cellspacing="0" cellpadding="0" bgcolor="#f9f9f9">
        <tr>
            <td align="center">
                <table width="600" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="margin-top: 20px; border: 1px solid #e0e0e0;">

                    <!-- Top Banner with Visible Logo -->
                    <tr bgcolor="#ff3d1f">
                        <td align="center" style="padding: 20px;">
                            <div style="background-color: white; padding: 10px 20px; display: inline-block; border-radius: 8px;">
                                @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $store?->name ?? 'Store' }} Logo" width="180" style="display: block;" />
                                @else
                                <span style="font-size: 24px; font-weight: bold; color: #333;">{{ $store?->name ?? 'Our Store' }}</span>
                                @endif
                            </div>
                        </td>
                    </tr>

                    <!-- Email Body -->
                    <tr>
                        <td style="padding: 30px;">
                            <h2 style="color: #333333;">Hello [Customer Name],</h2>
                            <p style="color: #555555; font-size: 16px;">
                                Thank you for choosing <strong>{{ $store?->name ?? 'Our Store' }}</strong> — your trusted partner in premium tech hardware. We're excited to serve your digital needs with reliable server solutions and more.
                            </p>

                            <h3 style="color: #333333;">Here's what's next:</h3>
                            <ul style="color: #555555; font-size: 16px;">
                                <li>Your order has been received and is being processed.</li>
                                <li>We'll notify you once it ships.</li>
                                <li>Track your order any time through your account.</li>
                            </ul>

                            <p style="color: #555555; font-size: 16px;">
                                If you have any questions, feel free to call us at <strong>(209) 651-6864</strong> or reply to this email.
                            </p>

                            <p style="color: #555555; font-size: 16px;">
                                Stay connected and keep upgrading with <strong>{{ $store?->name ?? 'Our Store' }}</strong>.
                            </p>

                            <div style="text-align: center; margin-top: 30px;">
                                <a href="{{ $store?->base_url ?? '#' }}" style="background-color: #ff3d1f; color: #ffffff; padding: 12px 24px; text-decoration: none; font-weight: bold; border-radius: 4px;">Visit Store</a>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr bgcolor="#f0f0f0">
                        <td align="center" style="font-size: 13px; color: #777777; padding: 20px;">
                            &copy; {{ now()->year }} {{ $store?->name ?? 'Our Store' }}. All rights reserved.<br />
                            <a href="{{ $store?->base_url ?? '#' }}" style="color: #777777;">{{ $store?->name ?? 'Our Store' }}</a>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>

</html>