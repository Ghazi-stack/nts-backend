<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width">
    <style type="text/css">
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            background: #fff;
        }

        .container {
            max-width: 544px;
            margin: 0 auto;
            padding: 20px;
        }

        .logo {
            text-align: center;
            padding: 24px 0;
        }

        .logo img {
            max-width: 300px;
            max-height: 150px;
        }

        .logo span {
            font-size: 36px;
            font-weight: 600;
            color: #333;
        }

        hr {
            border: none;
            border-bottom: 1px solid #d0d0d0;
        }

        h1 {
            font-size: 28px;
            font-weight: 500;
            color: #333;
            margin: 0 0 32px;
        }

        p {
            font-size: 14px;
            color: #333;
            line-height: 1.57;
            margin: 0 0 16px;
        }

        .btn {
            display: inline-block;
            background: #2199e8;
            color: #fff !important;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 600;
            font-size: 16px;
        }

        .footer {
            text-align: center;
            padding: 20px 0;
            font-size: 12px;
            color: #777;
        }

        .footer a {
            color: #777;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="logo">
            <a href="{{ $storePath }}" target="_blank">
                @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $store?->name ?? 'Store' }} Logo">
                @else
                <span>{{ $store?->name ?? 'Our Store' }}</span>
                @endif
            </a>
        </div>

        <hr>

        <br>

        <h1>Create Your Account</h1>

        <p>Hello {{ $userName }},</p>

        <p>Thank you for your order! To track your orders, manage your account, and enjoy a faster checkout experience, create your account by clicking the button below.</p>

        <p style="text-align: center; margin: 32px 0;">
            <a href="{{ $registerLink }}" class="btn">Create Account</a>
        </p>

        <p>Use this email to register: <strong>{{ $userEmail }}</strong></p>

        <p>If you have any questions, feel free to contact us.</p>

        <hr>

        <div class="footer">
            <p><strong>{{ $store?->name ?? 'Our Store' }}</strong></p>
            <p><a href="{{ $storePath }}">{{ str_replace(['https://', 'http://'], '', $storePath) }}</a></p>
        </div>
    </div>
</body>

</html>