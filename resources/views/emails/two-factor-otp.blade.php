<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Verification Code</title>
</head>

<body style="margin:0;padding:0;background-color:#f0f2f5;font-family:'Segoe UI',Arial,sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f2f5;padding:40px 0;">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0" style="max-width:520px;width:100%;">

                    {{-- TOP LOGO --}}
                    <tr>
                        <td align="center" style="padding-bottom:24px;">
                            <table cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:16px;padding:14px 26px;">
                                        <span style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:-0.3px;">
                                            🔐 {{ config('app.name') }}
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- MAIN CARD --}}
                    <tr>
                        <td style="background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

                            {{-- Header Banner --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 50%,#a78bfa 100%);padding:36px 40px 32px;text-align:center;">
                                        <p style="margin:0 0 12px;font-size:40px;line-height:1;">🛡️</p>
                                        <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:700;letter-spacing:-0.3px;">
                                            Verify Your Login
                                        </h1>
                                        <p style="margin:8px 0 0;color:rgba(255,255,255,0.8);font-size:14px;">
                                            Two-Factor Authentication
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            {{-- Body --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:36px 40px 20px;">

                                        <p style="margin:0 0 6px;color:#374151;font-size:16px;">
                                            Hello <strong style="color:#111827;">{{ $userName }}</strong>,
                                        </p>
                                        <p style="margin:0 0 28px;color:#6b7280;font-size:15px;line-height:1.6;">
                                            We received a login request for your account. Use the verification code below to complete your sign-in.
                                        </p>

                                        {{-- OTP Box with individual digit boxes --}}
                                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                                            <tr>
                                                <td style="background:linear-gradient(135deg,#f5f3ff,#ede9fe);border:2px solid #c4b5fd;border-radius:16px;padding:28px 20px;text-align:center;">
                                                    <p style="margin:0 0 16px;color:#7c3aed;font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">
                                                        Your Verification Code
                                                    </p>
                                                    @php $digits = str_split($otp); @endphp
                                                    @foreach($digits as $digit)
                                                    <span style="display:inline-block;background:#ffffff;border:1.5px solid #ddd6fe;border-radius:10px;width:44px;height:56px;line-height:56px;text-align:center;font-size:28px;font-weight:800;color:#4f46e5;margin:0 3px;box-shadow:0 2px 8px rgba(99,102,241,0.15);">{{ $digit }}</span>
                                                    @endforeach
                                                    <p style="margin:16px 0 0;color:#7c3aed;font-size:13px;">
                                                        ⏱ Expires in <strong>10 minutes</strong>
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Do / Don't boxes --}}
                                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                                            <tr>
                                                <td width="48%" valign="top" style="background:#f0fdf4;border-left:3px solid #22c55e;border-radius:0 8px 8px 0;padding:12px 14px;">
                                                    <p style="margin:0;font-size:12px;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.05em;">✓ Do This</p>
                                                    <p style="margin:4px 0 0;font-size:13px;color:#166534;line-height:1.4;">Enter this code in the app within 10 minutes</p>
                                                </td>
                                                <td width="4%"></td>
                                                <td width="48%" valign="top" style="background:#fef2f2;border-left:3px solid #ef4444;border-radius:0 8px 8px 0;padding:12px 14px;">
                                                    <p style="margin:0;font-size:12px;font-weight:700;color:#b91c1c;text-transform:uppercase;letter-spacing:.05em;">✕ Never Do</p>
                                                    <p style="margin:4px 0 0;font-size:13px;color:#991b1b;line-height:1.4;">Share this code with anyone, including support</p>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Warning --}}
                                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
                                            <tr>
                                                <td style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px 16px;">
                                                    <p style="margin:0;font-size:13px;color:#92400e;line-height:1.5;">
                                                        ⚠️ <strong>Not you?</strong> If you didn't try to log in, simply ignore this email. Your account is safe.
                                                    </p>
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
                                        <hr style="border:none;border-top:1px solid #f3f4f6;margin:0;">
                                    </td>
                                </tr>
                            </table>

                            {{-- Footer inside card --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:20px 40px 28px;text-align:center;">
                                        <p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.6;">
                                            This is an automated email from <strong style="color:#6b7280;">{{ config('app.name') }}</strong>.<br>
                                            Please do not reply to this email.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>

                    {{-- Bottom footer --}}
                    <tr>
                        <td align="center" style="padding:24px 0 8px;">
                            <p style="margin:0;font-size:12px;color:#9ca3af;">
                                &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>

</html>