<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en" xml:lang="en">

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
        p,
        img {
            Margin: 0;
            margin: 0;
            padding: 0;
        }

        body {
            box-sizing: border-box;
            min-width: 100%;
            width: 100%;
        }

        table {
            border-collapse: collapse;
            border-spacing: 0;
            width: 100%;
        }

        td,
        th {
            border-collapse: collapse;
        }

        h1,
        p,
        a,
        span,
        img {
            color: #333;
            font-family: Montserrat, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 14px;
            font-weight: 400;
            line-height: 1.57143;
            word-wrap: break-word;
        }

        h1 {
            font-size: 28px;
            font-weight: 500;
            line-height: 1.21429;
        }

        h1:not(:last-child),
        p:not(:last-child) {
            Margin: 0 0 32px;
            margin: 0 0 32px;
        }

        a {
            color: #2199e8;
            display: inline-block;
            text-decoration: none;
        }

        strong {
            font-weight: 600;
        }

        img {
            border: none;
            display: block;
            outline: 0;
            text-align: center;
            max-width: 100%;
            max-height: 100%;
        }

        hr {
            border: none;
            border-bottom: 1px solid #d0d0d0;
        }

        .body {
            background: #fff;
            height: 100%;
        }

        .body>tr>td {
            text-align: center;
        }

        .container,
        .column {
            Margin: 0 auto;
            margin: 0 auto;
            width: 544px;
        }

        .column td,
        .column th {
            text-align: left;
            vertical-align: top;
        }

        .column--md-9 {
            width: 75%;
        }

        .column--md-3 {
            width: 25%;
        }

        .expander {
            padding: 0 !important;
            visibility: hidden;
            width: 0;
        }

        .spacer--16 td {
            font-size: 16px;
            height: 16px;
            line-height: 16px;
        }

        .spacer--24 td {
            font-size: 24px;
            height: 24px;
            line-height: 24px;
        }

        .spacer--32 td {
            font-size: 32px;
            height: 32px;
            line-height: 32px;
        }

        .spacer--40 td {
            font-size: 40px;
            height: 40px;
            line-height: 40px;
        }

        .delimiter th {
            vertical-align: middle;
        }

        .delimiter__image,
        .delimiter__image-block {
            width: 48px;
        }

        .logo td {
            padding: 24px 0;
        }

        .logo a {
            color: #333;
            display: block;
            text-align: center;
        }

        .logo img {
            color: #333;
            text-decoration: none;
            margin: 0 auto;
        }

        .logo span {
            color: #333;
            font-size: 36px;
            font-weight: 600;
        }

        h2 {
            Margin: 0 0 4px;
            margin: 0 0 4px;
            color: #333;
            font-family: Montserrat, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 21px;
            font-weight: 500;
            line-height: 1.57143;
        }

        .details {
            border-top: 1px solid #e5e5e5;
        }

        .details>tr>th {
            padding-top: 16px;
        }

        .details__content {
            width: auto;
        }

        .details__content tr:not(:last-child) th {
            padding-bottom: 8px;
        }

        .store p {
            Margin: 0;
            margin: 0;
        }

        .store a {
            color: #777;
            font-size: 12px;
        }

        .store-button a {
            border: 1px solid #999;
            border-radius: 4px;
            box-sizing: border-box;
            color: #777;
            padding: 3px 15px;
            text-align: center;
            white-space: nowrap;
            width: 100%;
        }

        @media only screen and (max-width: 599px) {
            img {
                height: auto !important;
                width: auto !important;
            }

            .container {
                width: 100% !important;
            }

            .column {
                box-sizing: border-box;
                height: auto !important;
                padding-left: 15px !important;
                padding-right: 15px !important;
            }

            .column--xs-12 {
                display: inline-block !important;
                width: 100% !important;
            }

            .details__content th {
                width: 100% !important;
                display: block !important;
            }

            .store th {
                padding-bottom: 16px !important;
            }

            .store p {
                text-align: center !important;
            }

            .store-button a {
                margin-left: auto !important;
                margin-right: auto !important;
            }
        }
    </style>
</head>

<body>
    <table class="body">
        <tr>
            <td>
                <table class="spacer spacer--16">
                    <tr>
                        <td>&nbsp;</td>
                    </tr>
                </table>

                <!-- Logo + Delimiter -->
                <table class="container">
                    <tr>
                        <td>
                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table class="logo">
                                            <tr>
                                                <th style="text-align: center;">
                                                    <a href="{{ $storePath }}" target="_blank">
                                                        @if($logoUrl)
                                                        <img src="{{ $logoUrl }}" alt="{{ $store?->name ?? 'Store' }} Logo" width="300" height="150" style="display: block; margin: 0 auto; max-width: 300px; max-height: 150px; width: auto; height: auto;">
                                                        @else
                                                        <span>{{ $store?->name ?? 'Our Store' }}</span>
                                                        @endif
                                                    </a>
                                                </th>
                                                <th class="expander"></th>
                                            </tr>
                                        </table>
                                    </th>
                                </tr>
                            </table>

                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table>
                                            <tr>
                                                <th>
                                                    <table class="delimiter">
                                                        <tr>
                                                            <th>
                                                                <hr>
                                                            </th>
                                                            <th class="delimiter__image-block">
                                                                <img src="https://cdn11.bigcommerce.com/s-4jpol1blth/img/emails/cart.png" alt="Cart" class="delimiter__image">
                                                            </th>
                                                            <th>
                                                                <hr>
                                                            </th>
                                                        </tr>
                                                    </table>
                                                </th>
                                                <th class="expander"></th>
                                            </tr>
                                        </table>
                                    </th>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <table class="spacer spacer--40">
                    <tr>
                        <td>&nbsp;</td>
                    </tr>
                </table>

                <!-- Main Content -->
                <table class="container">
                    <tr>
                        <td>

                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table>
                                            <tr>
                                                <th>

                                                    @if($isAdmin)

                                                    <h1>New Newsletter Subscription</h1>

                                                    <p>
                                                        Hello Store Admin,
                                                        <br />
                                                        A new customer has subscribed to your newsletter.
                                                        Please find the subscription details below.
                                                    </p>

                                                    @else

                                                    <h1>Thank You For Subscribing!</h1>

                                                    <p>
                                                        Hello,
                                                        <br />
                                                        Thank you for subscribing to
                                                        <strong>{{ $store?->name }}</strong>.

                                                        <br /><br />

                                                        We're excited to have you with us!
                                                        You'll now receive updates about our latest products,
                                                        exclusive offers, promotions and important announcements.

                                                        <br /><br />

                                                        Thank you for being a valued subscriber.
                                                    </p>

                                                    @endif

                                                </th>

                                                <th class="expander"></th>
                                            </tr>
                                        </table>
                                    </th>
                                </tr>
                            </table>

                            <table class="spacer spacer--32">
                                <tr>
                                    <td>&nbsp;</td>
                                </tr>
                            </table>

                            <!-- Newsletter Details -->
                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table>
                                            <tr>
                                                <th>

                                                    <h2>Subscription Details</h2>

                                                    <table class="details">
                                                        <tr>
                                                            <th>

                                                                <table class="details__content">

                                                                    <tr>
                                                                        <th style="width:120px;padding-right:30px;">
                                                                            <p>Email:</p>
                                                                        </th>

                                                                        <th>
                                                                            <a href="mailto:{{ $newsletter->email }}">
                                                                                <strong>{{ $newsletter->email }}</strong>
                                                                            </a>
                                                                        </th>
                                                                    </tr>

                                                                    <tr>
                                                                        <th style="width:120px;padding-right:30px;">
                                                                            <p>Subscribed On:</p>
                                                                        </th>

                                                                        <th>
                                                                            <p>
                                                                                <strong>
                                                                                    {{ $newsletter->created_at->format('M d, Y h:i A') }}
                                                                                </strong>
                                                                            </p>
                                                                        </th>
                                                                    </tr>

                                                                    <tr>
                                                                        <th style="width:120px;padding-right:30px;">
                                                                            <p>Store:</p>
                                                                        </th>

                                                                        <th>
                                                                            <p>
                                                                                <strong>{{ $store?->name }}</strong>
                                                                            </p>
                                                                        </th>
                                                                    </tr>

                                                                    @if($isAdmin)

                                                                    <tr>
                                                                        <th style="width:120px;padding-right:30px;">
                                                                            <p>Status:</p>
                                                                        </th>

                                                                        <th>
                                                                            <p>
                                                                                <strong>New Newsletter Subscriber</strong>
                                                                            </p>
                                                                        </th>
                                                                    </tr>

                                                                    @else

                                                                    <tr>
                                                                        <th style="width:120px;padding-right:30px;">
                                                                            <p>Status:</p>
                                                                        </th>

                                                                        <th>
                                                                            <p>
                                                                                <strong>Subscription Confirmed</strong>
                                                                            </p>
                                                                        </th>
                                                                    </tr>

                                                                    @endif

                                                                </table>

                                                            </th>
                                                        </tr>
                                                    </table>

                                                </th>

                                                <th class="expander"></th>
                                            </tr>
                                        </table>
                                    </th>
                                </tr>
                            </table>

                        </td>
                    </tr>
                </table>

                <table class="spacer spacer--40">
                    <tr>
                        <td>&nbsp;</td>
                    </tr>
                </table>

                <!-- Footer -->
                <table class="container">
                    <tr>
                        <td>
                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table>
                                            <tr>
                                                <th>
                                                    <table class="delimiter">
                                                        <tr>
                                                            <th>
                                                                <hr>
                                                            </th>
                                                            <th class="delimiter__image-block">
                                                                <img src="https://cdn11.bigcommerce.com/s-4jpol1blth/img/emails/shop.png" alt="Shop" class="delimiter__image">
                                                            </th>
                                                            <th>
                                                                <hr>
                                                            </th>
                                                        </tr>
                                                    </table>
                                                </th>
                                                <th class="expander"></th>
                                            </tr>
                                        </table>
                                    </th>
                                </tr>
                            </table>

                            <table class="spacer spacer--24">
                                <tr>
                                    <td>&nbsp;</td>
                                </tr>
                            </table>

                            <table class="row">
                                <tr>
                                    <th class="column column--md-9 column--xs-12">
                                        <table class="store">
                                            <tr>
                                                <th>
                                                    <p><strong>{{ $store?->name ?? 'Our Store' }}</strong></p>
                                                    <p><a href="{{ $storePath }}" target="_blank">{{ str_replace(['https://', 'http://'], '', $storePath) }}</a></p>
                                                </th>
                                                <th class="expander"></th>
                                            </tr>
                                        </table>
                                    </th>

                                    <th class="column column--md-3 column--xs-12">
                                        <table class="store-button">
                                            <tr>
                                                <th>
                                                    <a href="{{ $storePath }}"><strong>Go shopping</strong></a>
                                                </th>
                                                <th class="expander"></th>
                                            </tr>
                                        </table>
                                    </th>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <table class="spacer spacer--16">
                    <tr>
                        <td>&nbsp;</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>