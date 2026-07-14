<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width">

    <style type="text/css">
        @media only screen {
            html {
                background: #fff;
                min-height: 100%;
            }
        }

        body,
        table,
        tr,
        td,
        th,
        div,
        h1,
        h2,
        p,
        img {
            margin: 0;
            padding: 0;
        }

        body {
            width: 100%;
            background: #fff;
            font-family: Montserrat, Arial, sans-serif;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        img {
            border: none;
            display: block;
            max-width: 100%;
        }

        h1 {
            font-size: 28px;
            font-weight: 600;
            color: #333;
            margin-bottom: 25px;
        }

        h2 {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        p {
            color: #555;
            line-height: 26px;
            font-size: 15px;
        }

        .container {
            width: 560px;
            margin: auto;
        }

        @media(max-width:600px) {
            .container {
                width: 100% !important;
                padding: 0 15px !important;
                box-sizing: border-box;
            }
        }
    </style>

</head>

<body>

    <table width="100%">
        <tr>
            <td align="center">

                <table class="container">

                    <tr>
                        <td style="padding:35px 0;text-align:center;">

                            <a href="{{ $storePath }}" target="_blank">

                                @if($logoUrl)

                                <img src="{{ $logoUrl }}"
                                    alt="{{ $store?->name }}"
                                    style="max-width:280px;max-height:120px;margin:auto;">

                                @else

                                <h2>{{ $store?->name }}</h2>

                                @endif

                            </a>

                        </td>
                    </tr>

                    <tr>
                        <td>

                            <table width="100%">
                                <tr>

                                    <td width="45%">
                                        <hr>

                                    </td>

                                    <td width="10%" align="center">

                                        <img src="https://cdn11.bigcommerce.com/s-4jpol1blth/img/emails/cart.png"
                                            style="width:45px;">

                                    </td>

                                    <td width="45%">
                                        <hr>
                                    </td>

                                </tr>
                            </table>

                        </td>
                    </tr>

                    <tr>
                        <td style="padding:40px 0 20px;">

                            @if($isAdmin)

                            <h1>New Newsletter Subscription</h1>

                            <p>

                                Hello Admin,

                                <br><br>

                                A new customer has subscribed to your newsletter.

                            </p>

                            @else

                            <h1>Thank You For Subscribing!</h1>

                            <p>

                                Hello,

                                <br><br>

                                Thank you for subscribing to the <strong>{{ $store?->name }}</strong> newsletter.

                                We'll keep you updated with our latest products, promotions and exclusive offers.

                            </p>

                            @endif

                        </td>
                    </tr>

                    <tr>
                        <td>

                            <h2>Subscription Details</h2>

                            <table width="100%" style="border-top:1px solid #e5e5e5;padding-top:20px;">

                                <tr>

                                    <td style="width:140px;padding-bottom:15px;">

                                        <strong>Email</strong>

                                    </td>

                                    <td style="padding-bottom:15px;">

                                        {{ $newsletter->email }}

                                    </td>

                                </tr>

                                <tr>

                                    <td>

                                        <strong>Subscribed On</strong>

                                    </td>

                                    <td>

                                        {{ $newsletter->created_at->format('M d, Y h:i A') }}

                                    </td>

                                </tr>

                            </table>

                        </td>
                    </tr>
                    <tr>
                        <td style="padding:45px 0 20px;">

                            <a href="{{ $storePath }}"
                                style="display:inline-block;
          background:#0d6efd;
          color:#ffffff;
          text-decoration:none;
          padding:14px 35px;
          border-radius:5px;
          font-size:15px;
          font-weight:600;">

                                Visit {{ $store?->name }}

                            </a>

                        </td>
                    </tr>

                    <tr>
                        <td style="padding:35px 0;">

                            <hr style="border:none;border-top:1px solid #e5e5e5;">

                        </td>
                    </tr>

                    <tr>
                        <td align="center">

                            <p style="font-size:16px;font-weight:600;color:#222;margin-bottom:10px;">

                                Thank You!

                            </p>

                            <p style="margin-bottom:8px;">

                                @if($isAdmin)

                                This notification was automatically generated because a customer subscribed to your newsletter.

                                @else

                                Thank you for joining our newsletter. We appreciate your interest in {{ $store?->name }}.

                                @endif

                            </p>

                            <p>

                                If you have any questions, feel free to contact our support team.

                            </p>

                        </td>
                    </tr>

                    <tr>
                        <td style="padding:40px 0;">

                            <hr style="border:none;border-top:1px solid #e5e5e5;">

                        </td>
                    </tr>

                    <tr>
                        <td align="center">

                            <p style="font-size:14px;color:#888;margin-bottom:8px;">

                                © {{ date('Y') }} {{ $store?->name }}. All Rights Reserved.

                            </p>

                            <p style="font-size:14px;">

                                <a href="{{ $storePath }}"
                                    style="color:#0d6efd;text-decoration:none;">

                                    {{ $storePath }}

                                </a>

                            </p>

                        </td>
                    </tr>

                </table>

            </td>
        </tr>

    </table>

</body>

</html>