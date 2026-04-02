<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Login</title>
</head>

<body style="margin:0;padding:0;background:linear-gradient(135deg,#f0f4ff 0%,#faf5ff 50%,#f0f4ff 100%);font-family:'Segoe UI',Arial,sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:linear-gradient(135deg,#f0f4ff 0%,#faf5ff 50%,#f0f4ff 100%);padding:40px 0;">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0" style="max-width:520px;width:100%;">

                    {{-- ── LOGO ── --}}
                    <tr>
                        <td align="center" style="padding-bottom:24px;">
                            <table cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:12px;padding:11px 22px;">
                                        <table cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="font-size:18px;vertical-align:middle;padding-right:8px;">🔐</td>
                                                <td style="color:#ffffff;font-size:17px;font-weight:800;letter-spacing:-0.3px;vertical-align:middle;">{{ config('app.name') }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ── MAIN CARD ── --}}
                    <tr>
                        <td style="background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 20px 60px rgba(99,102,241,0.12),0 4px 16px rgba(0,0,0,0.06);">

                            {{-- Top gradient line --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:linear-gradient(90deg,#6366f1,#8b5cf6,#a855f7,#ec4899);height:4px;font-size:0;line-height:0;">&nbsp;</td>
                                </tr>
                            </table>

                            {{-- Header --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:36px 40px 24px;text-align:center;background:linear-gradient(180deg,#fafaff 0%,#ffffff 100%);">

                                        {{-- Shield icon --}}
                                        <table cellpadding="0" cellspacing="0" align="center" style="margin-bottom:20px;">
                                            <tr>
                                                <td style="background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:2px solid #e0e7ff;border-radius:50%;width:80px;height:80px;text-align:center;vertical-align:middle;font-size:36px;">
                                                    🛡️
                                                </td>
                                            </tr>
                                        </table>

                                        <h1 style="margin:0 0 8px;color:#111827;font-size:26px;font-weight:800;letter-spacing:-0.5px;">Verify Your Login</h1>
                                        <p style="margin:0;color:#6b7280;font-size:14px;line-height:1.5;">Two-step verification code requested</p>
                                    </td>
                                </tr>
                            </table>

                            {{-- Body --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:4px 40px 32px;">

                                        <p style="margin:0 0 6px;color:#374151;font-size:15px;">
                                            Hey <strong style="color:#111827;">{{ $userName }}</strong> 👋
                                        </p>
                                        <p style="margin:0 0 28px;color:#6b7280;font-size:14px;line-height:1.7;">
                                            A login was requested for your account. Enter the code below to complete verification. This code is valid for <strong style="color:#6366f1;">10 minutes</strong>.
                                        </p>

                                        {{-- OTP BOX --}}
                                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                                            <tr>
                                                <td style="background:linear-gradient(135deg,#f5f3ff,#eef2ff);border:1.5px solid #c4b5fd;border-radius:20px;padding:28px 24px;text-align:center;">

                                                    <p style="margin:0 0 16px;font-size:11px;font-weight:700;color:#7c3aed;letter-spacing:3px;text-transform:uppercase;">
                                                        Your One-Time Code
                                                    </p>

                                                    {{-- Digit boxes --}}
                                                    @php $digits = str_split($otp); @endphp
                                                    <table cellpadding="0" cellspacing="0" align="center" style="margin-bottom:20px;">
                                                        <tr>
                                                            @foreach($digits as $index => $digit)
                                                            @if($index == 3)
                                                            <td style="padding:0 6px;color:#c4b5fd;font-size:22px;font-weight:300;vertical-align:middle;">&mdash;</td>
                                                            @endif
                                                            <td style="padding:0 3px;">
                                                                <span style="display:inline-block;background:#ffffff;border:1.5px solid #c4b5fd;border-radius:12px;width:50px;height:62px;line-height:62px;text-align:center;font-size:30px;font-weight:900;color:#4f46e5;box-shadow:0 4px 12px rgba(99,102,241,0.15);">{{ $digit }}</span>
                                                            </td>
                                                            @endforeach
                                                        </tr>
                                                    </table>

                                                    {{-- Progress bar --}}
                                                    <table width="70%" cellpadding="0" cellspacing="0" align="center" style="margin-bottom:10px;">
                                                        <tr>
                                                            <td style="background:#e0e7ff;border-radius:100px;height:5px;overflow:hidden;">
                                                                <table width="75%" cellpadding="0" cellspacing="0">
                                                                    <tr>
                                                                        <td style="background:linear-gradient(90deg,#6366f1,#a855f7);height:5px;border-radius:100px;font-size:0;">&nbsp;</td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    <p style="margin:0;font-size:12px;color:#7c3aed;">⏱ Expires in <strong>10 minutes</strong></p>

                                                </td>
                                            </tr>
                                        </table>

                                        {{-- 3 Info pills --}}
                                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                                            <tr>
                                                <td width="31%" valign="top" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px 10px;text-align:center;">
                                                    <p style="margin:0 0 5px;font-size:20px;">✅</p>
                                                    <p style="margin:0 0 3px;font-size:11px;font-weight:700;color:#15803d;">Use once</p>
                                                    <p style="margin:0;font-size:11px;color:#6b7280;">Single use only</p>
                                                </td>
                                                <td width="3%">&nbsp;</td>
                                                <td width="31%" valign="top" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px 10px;text-align:center;">
                                                    <p style="margin:0 0 5px;font-size:20px;">⏰</p>
                                                    <p style="margin:0 0 3px;font-size:11px;font-weight:700;color:#c2410c;">10 Minutes</p>
                                                    <p style="margin:0;font-size:11px;color:#6b7280;">Then it expires</p>
                                                </td>
                                                <td width="3%">&nbsp;</td>
                                                <td width="31%" valign="top" style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:14px 10px;text-align:center;">
                                                    <p style="margin:0 0 5px;font-size:20px;">🚫</p>
                                                    <p style="margin:0 0 3px;font-size:11px;font-weight:700;color:#b91c1c;">Don't share</p>
                                                    <p style="margin:0;font-size:11px;color:#6b7280;">Keep it secret</p>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Warning box --}}
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="background:linear-gradient(135deg,#fffbeb,#fef9c3);border:1px solid #fde68a;border-radius:14px;padding:16px 18px;">
                                                    <table cellpadding="0" cellspacing="0" width="100%">
                                                        <tr>
                                                            <td width="30" valign="top" style="font-size:20px;padding-right:12px;padding-top:1px;">⚠️</td>
                                                            <td>
                                                                <p style="margin:0 0 3px;font-size:13px;font-weight:700;color:#92400e;">Didn&rsquo;t try to log in?</p>
                                                                <p style="margin:0;font-size:13px;color:#78350f;line-height:1.5;">Simply ignore this email &mdash; your account remains fully secure and no action is required.</p>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                    </td>
                                </tr>
                            </table>

                            {{-- Divider --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:0 40px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="background:linear-gradient(90deg,transparent,#e5e7eb,transparent);height:1px;font-size:0;line-height:0;">&nbsp;</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            {{-- Card Footer --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:20px 40px 28px;text-align:center;">
                                        <p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.7;">
                                            Automated security email from <strong style="color:#6b7280;">{{ config('app.name') }}</strong> &middot; Please do not reply<br>
                                            &copy; {{ date('Y') }} {{ config('app.name') }} &middot; All rights reserved
                                        </p>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>

                    {{-- Bottom note --}}
                    <tr>
                        <td align="center" style="padding:20px 0 4px;">
                            <p style="margin:0;font-size:11px;color:#9ca3af;">
                                You&rsquo;re receiving this because a login was attempted on your account.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>

</html>