<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Welcome to Newtown Spares</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f7f7f7;
            margin: 0;
            padding: 0;
        }
        .container {
            background-color: #ffffff;
            max-width: 600px;
            margin: 40px auto;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .logo img {
            max-width: 180px;
        }
        h1 {
            color: #ff3c1f;
            text-align: center;
        }
        p {
            font-size: 16px;
            color: #333333;
            line-height: 1.6;
        }
        .btn {
            display: inline-block;
            padding: 12px 20px;
            margin-top: 25px;
            background-color: #ff3c1f;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }
        .footer {
            margin-top: 40px;
            font-size: 12px;
            color: #999999;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <img src="https://www.newtownspares.com/assets/logo.png" alt="Newtown Spares">
        </div>
        <h1>Hi {{ $user->name }},</h1>
        <p>Thanks for registering with <strong>Newtown Spares</strong>!</p>
        <p>We’re excited to have you on board. From high-quality server solutions to tech components, we’re here to elevate your digital game.</p>
        <p>If you have any questions or need help with your order, feel free to reach out to our support team anytime.</p>
        <p>
            <a href="https://www.newtownspares.com" class="btn">Visit Our Store</a>
        </p>
        <div class="footer">
            © {{ date('Y') }} Newtown Spares. All rights reserved.
        </div>
    </div>
</body>
</html>
