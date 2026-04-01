<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Identity</title>
</head>

<body style="margin:0;padding:0;background-color:#0f0f1a;font-family:'Segoe UI',Arial,sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0f0f1a;padding:40px 0;">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0" style="max-width:520px;width:100%;">

                    {{-- ── LOGO ── --}}
                    <tr>
                        <td align="center" style="padding-bottom:28px;">
                            <table cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:0;">
                                        <table cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="background:linear-gradient(135deg,#818cf8,#6366f1);border-radius:10px;width:38px;height:38px;text-align:center;vertical-align:middle;font-size:20px;">
                                                    🔐
                                                </td>
                                                <td style="padding-left:10px;">
                                                    <span style="color:#ffffff;font-size:18px;font-weight:700;letter-spacing:-0.3px;">{{ config('app.name') }}</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ── MAIN CARD ── --}}
                    <tr>
                        <td style="background:#1a1a2e;border-radius:24px;overflow:hidden;border:1px solid rgba(99,102,241,0.3);">

                            {{-- Rainbow top line --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:linear-gradient(90deg,#6366f1,#8b5cf6,#ec4899,#8b5cf6,#6366f1);height:3px;font-size:0;line-height:0;">&nbsp;</td>
                                </tr>
                            </table>

                            {{-- Header --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:36px 40px 24px;text-align:center;">
                                        {{-- Shield circle --}}
                                        <table cellpadding="0" cellspacing="0" align="center" style="margin-bottom:18px;">
                                            <tr>
                                                <td style="background:linear-gradient(135deg,rgba(99,102,241,0.2),rgba(139,92,246,0.2));border:1px solid rgba(139,92,246,0.4);border-radius:50%;width:72px;height:72px;text-align:center;vertical-align:middle;font-size:34px;">
                                                    🛡️
                                                </td>
                                            </tr>
                                        </table>
                                        <h1 style="margin:0 0 8px;color:#ffffff;font-size:26px;font-weight:800;letter-spacing:-0.5px;">Verify Your Identity</h1>
                                        <p style="margin:0;color:#94a3b8;font-size:14px;">A login attempt was made to your account</p>
                                    </td>
                                </tr>
                            </table>

                            {{-- Glow divider --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:0 40px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="background:linear-gradient(90deg,transparent,rgba(99,102,241,0.3),transparent);height:1px;font-size:0;line-height:0;">&nbsp;</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            {{-- Body --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:28px 40px;">

                                        <p style="margin:0 0 24px;color:#cbd5e1;font-size:15px;line-height:1.7;">
                                            Hey <strong style="color:#ffffff;">{{ $userName }}</strong> 👋 &mdash; someone (hopefully you!) is trying to sign in. Here&rsquo;s your one-time code:
                                        </p>

                                        {{-- OTP BOX --}}
                                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                                            <tr>
                                                <td style="background:linear-gradient(135deg,#0f0f1a,#1e1b4b);border:1px solid rgba(99,102,241,0.5);border-radius:18px;padding:28px 20px;text-align:center;">

                                                    <p style="margin:0 0 18px;color:#818cf8;font-size:11px;font-weight:700;letter-spacing:3px;text-transform:uppercase;">
                                                        One-Time Password
                                                    </p>

                                                    {{-- Individual digit boxes --}}
                                                    @php
                                                    $digits = str_split($otp);
                                                    @endphp

                                                    <table cellpadding="0" cellspacing="0" align="center" style="margin-bottom:18px;">
                                                        <tr>
                                                            @foreach($digits as $index => $digit)
                                                            @if($index == 3)
                                                            <td style="padding:0 6px;color:#4b5563;font-size:24px;font-weight:300;vertical-align:middle;">&middot;</td>
                                                            @endif
                                                            <td style="padding:0 4px;">
                                                                <span style="display:inline-block;background:#1e1b4b;border:1px solid #4f46e5;border-radius:12px;width:48px;height:60px;line-height:60px;text-align:center;font-size:30px;font-weight:900;color:#a5b4fc;box-shadow:0 0 16px rgba(99,102,241,0.3);">{{ $digit }}</span>
                                                            </td>
                                                            @endforeach
                                                        </tr>
                                                    </table>

                                                    {{-- Timer bar --}}
                                                    <table width="80%" cellpadding="0" cellspacing="0" align="center" style="margin-bottom:10px;">
                                                        <tr>
                                                            <td style="background:rgba(255,255,255,0.05);border-radius:100px;height:6px;overflow:hidden;">
                                                                <table width="80%" cellpadding="0" cellspacing="0">
                                                                    <tr>
                                                                        <td style="background:linear-gradient(90deg,#6366f1,#8b5cf6);height:6px;border-radius:100px;font-size:0;">&nbsp;</td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    <p style="margin:0;color:#64748b;font-size:12px;">⏱ Valid for <strong style="color:#818cf8;">10 minutes</strong> only</p>

                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Do / Dont --}}
                                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                                            <tr>
                                                <td width="48%" valign="top" style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);border-radius:12px;padding:14px;">
                                                    <p style="margin:0 0 6px;font-size:18px;">✅</p>
                                                    <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#4ade80;">Safe to enter</p>
                                                    <p style="margin:0;font-size:12px;color:#6b7280;line-height:1.4;">Only type this in the official app or website</p>
                                                </td>
                                                <td width="4%">&nbsp;</td>
                                                <td width="48%" valign="top" style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);border-radius:12px;padding:14px;">
                                                    <p style="margin:0 0 6px;font-size:18px;">🚫</p>
                                                    <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#f87171;">Never share</p>
                                                    <p style="margin:0;font-size:12px;color:#6b7280;line-height:1.4;">Not with support, friends, or anyone else</p>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Warning --}}
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);border-radius:12px;padding:14px 16px;">
                                                    <p style="margin:0;font-size:13px;color:#fbbf24;line-height:1.6;">
                                                        ⚠️ <strong>Wasn&rsquo;t you?</strong>
                                                        <span style="color:#94a3b8;">Just ignore this email &mdash; your account is still secure. No action needed.</span>
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>

                                    </td>
                                </tr>
                            </table>

                            {{-- Footer divider --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:0 40px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="background:linear-gradient(90deg,transparent,rgba(99,102,241,0.2),transparent);height:1px;font-size:0;line-height:0;">&nbsp;</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            {{-- Footer --}}
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:20px 40px 28px;text-align:center;">
                                        <p style="margin:0;font-size:12px;color:#475569;line-height:1.7;">
                                            Sent by <strong style="color:#64748b;">{{ config('app.name') }} Security</strong> &middot; Do not reply<br>
                                            &copy; {{ date('Y') }} {{ config('app.name') }} &middot; All rights reserved
                                        </p>
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