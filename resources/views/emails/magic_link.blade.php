<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your sign-in link</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#333333;">
    <span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;mso-hide:all;">
        Your secure sign-in link for {{ $store?->name ?? 'our store' }}.
    </span>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;background:#ffffff;">
        <tr>
            <td align="center" style="padding:16px 12px 0;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="544" style="width:100%;max-width:544px;border-collapse:collapse;">
                    <tr>
                        <td align="center" style="padding:8px 0 24px;">
                            <a href="{{ $storePath }}" target="_blank" style="text-decoration:none;color:#333333;">
                                @if($logoUrl)
                                    <img src="{{ $logoUrl }}" alt="{{ $store?->name ?? 'Store' }} logo" width="300" style="display:block;border:0;outline:none;text-decoration:none;width:auto;max-width:300px;max-height:150px;height:auto;margin:0 auto;">
                                @else
                                    <span style="font-size:28px;font-weight:bold;color:#333333;">{{ $store?->name ?? 'Our Store' }}</span>
                                @endif
                            </a>
                        </td>
                    </tr>

                    <!-- Cart divider -->
                    <tr>
                        <td style="padding:0;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
                                <tr>
                                    <td valign="middle" style="border-top:1px solid #dedede;font-size:1px;line-height:1px;">&nbsp;</td>
                                    <td align="center" valign="middle" width="56" style="width:56px;padding:0;font-size:0;line-height:0;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="48" height="48" align="center" style="width:48px;height:48px;border:1px solid #dedede;border-radius:50%;border-collapse:separate;">
                                            <tr>
                                                <td align="center" valign="middle" style="padding:0;font-size:0;line-height:0;">
                                                    <img src="https://cdn11.bigcommerce.com/s-4jpol1blth/img/emails/cart.png" width="47" height="47" alt="Cart" style="display:block;width:47px;height:47px;margin:0 auto;border:0;outline:none;text-decoration:none;">
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td valign="middle" style="border-top:1px solid #dedede;font-size:1px;line-height:1px;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:36px 0 0;">
                            <h1 style="margin:0 0 26px;font-size:23px;line-height:1.25;font-weight:600;color:#333333;">
                                Please click the link below to sign in to your account with {{ $store?->name ?? 'our store' }}
                            </h1>

                            <p style="margin:0 0 26px;font-size:12px;line-height:1.7;color:#444444;">
                                You have requested a sign-in link. Please
                                <a href="{{ $loginUrl }}" target="_blank" style="color:#0785e8;text-decoration:none;">click here to sign in</a>
                                and continue.
                            </p>

                            <p style="margin:0 0 26px;font-size:12px;line-height:1.7;color:#444444;">
                                Alternatively, paste the following link in your browser:<br>
                                <a href="{{ $loginUrl }}" target="_blank" style="color:#0785e8;text-decoration:underline;overflow-wrap:anywhere;word-break:break-all;">{{ $loginUrl }}</a>
                            </p>

                            <p style="margin:0;font-size:12px;line-height:1.7;color:#555555;">
                                This sign-in link expires in 15 minutes and can only be used once. If you did not request this link, please ignore this email. Your account is still secure.
                            </p>
                        </td>
                    </tr>

                    <!-- Store divider -->
                    <tr>
                        <td style="padding:48px 0 24px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
                                <tr>
                                    <td valign="middle" style="border-top:1px solid #dedede;font-size:1px;line-height:1px;">&nbsp;</td>
                                    <td align="center" valign="middle" width="56" style="width:56px;padding:0;font-size:0;line-height:0;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="48" height="48" align="center" style="width:48px;height:48px;border:1px solid #dedede;border-radius:50%;border-collapse:separate;">
                                            <tr>
                                                <td align="center" valign="middle" style="padding:0;font-size:0;line-height:0;">
                                                    <img src="https://cdn11.bigcommerce.com/s-4jpol1blth/img/emails/shop.png" width="47" height="47" alt="Store" style="display:block;width:47px;height:47px;margin:0 auto;border:0;outline:none;text-decoration:none;">
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td valign="middle" style="border-top:1px solid #dedede;font-size:1px;line-height:1px;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 0 28px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
                                <tr>
                                    <td valign="middle" style="font-size:12px;line-height:1.6;color:#444444;">
                                        <strong style="font-size:13px;color:#333333;">{{ $store?->name ?? 'Our Store' }}</strong><br>
                                        <a href="{{ $storePath }}" target="_blank" style="font-size:10px;color:#888888;text-decoration:none;">{{ preg_replace('#^https?://#', '', $storePath) }}</a>
                                    </td>
                                    <td align="right" valign="middle" style="padding-left:12px;">
                                        <a href="{{ $storePath }}" target="_blank" style="display:inline-block;border:1px solid #bdbdbd;border-radius:4px;padding:7px 20px;color:#777777;text-decoration:none;font-size:12px;white-space:nowrap;">Go shopping</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
