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
            -moz-box-sizing: border-box;
            -webkit-box-sizing: border-box;
            box-sizing: border-box;
            min-width: 100%;
            -ms-text-size-adjust: 100%;
            -webkit-text-size-adjust: 100%;
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
            -moz-hyphens: auto;
            -webkit-hyphens: auto;
            hyphens: auto;
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

        .preheader {
            display: none;
            font-size: 1px;
            line-height: 1px;
            max-height: 0;
            max-width: 0;
            mso-hide: all;
            opacity: 0;
            overflow: hidden;
            visibility: hidden;
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

        .products {
            border-top: 1px solid #e5e5e5;
        }

        .products__item {
            border-bottom: 1px solid #e5e5e5;
        }

        .products__item th {
            padding-bottom: 16px;
            padding-top: 16px;
        }

        .products__image-container,
        .products__content {
            padding-right: 24px;
        }

        .products__image-container {
            height: 80px;
            width: 80px;
        }

        .products__image {
            border: 1px solid #ddd;
            border-radius: 4px;
            height: 78px;
            width: 78px;
        }

        .products__item .products__content,
        .products__item .products__quantity {
            vertical-align: middle;
        }

        .products__content p:not(:last-child) {
            Margin: 0 0 4px;
            margin: 0 0 4px;
        }

        .products__sku {
            font-size: 12px;
            line-height: 1.666667;
        }

        .products__quantity {
            width: 65px;
        }

        .complete-order a {
            background: #555;
            border: none;
            border-radius: 4px;
            -moz-box-sizing: border-box;
            -webkit-box-sizing: border-box;
            box-sizing: border-box;
            color: #fff;
            display: inline-block;
            font-weight: 600;
            padding: 4px 16px;
            text-align: center;
            white-space: nowrap;
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
            -moz-box-sizing: border-box;
            -webkit-box-sizing: border-box;
            box-sizing: border-box;
            color: #777;
            padding: 3px 15px;
            text-align: center;
            white-space: nowrap;
            width: 100%;
        }

        .unsubscribe p {
            text-align: center;
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
                -moz-box-sizing: border-box;
                -webkit-box-sizing: border-box;
                box-sizing: border-box;
                height: auto !important;
                padding-left: 15px !important;
                padding-right: 15px !important;
            }

            .column--xs-12 {
                display: inline-block !important;
                width: 100% !important;
            }

            .products__image {
                height: 78px !important;
                width: 78px !important;
            }

            .products__content,
            .products__quantity {
                -moz-box-sizing: border-box;
                -webkit-box-sizing: border-box;
                box-sizing: border-box;
                display: inline-block !important;
                width: 100% !important;
            }

            .products__content {
                padding-bottom: 0 !important;
                padding-right: 0 !important;
            }

            .products__quantity {
                padding-top: 12px !important;
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
    <span class="preheader"></span>

    <table class="body">
        <tr>
            <td>
                <table class="spacer spacer--16">
                    <tr>
                        <td>&nbsp;</td>
                    </tr>
                </table>

                {{-- ── Logo ── --}}
                <table class="container">
                    <tr>
                        <td>
                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table class="logo">
                                            <tr>
                                                <th>
                                                    <a href="{{ config('app.frontend_url') }}" target="_blank">
                                                        <span>{{ config('app.name') }}</span>
                                                    </a>
                                                </th>
                                                <th class="expander"></th>
                                            </tr>
                                        </table>
                                    </th>
                                </tr>
                            </table>

                            {{-- ── Divider ── --}}
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
                                                                🛒
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

                {{-- ── Main Content ── --}}
                <table class="container">
                    <tr>
                        <td>
                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table>
                                            <tr>
                                                <th>
                                                    {{-- Title - email type ke hisaab se --}}
                                                    @if($emailType === '1hr')
                                                    <h1>You left something behind!</h1>
                                                    <p>
                                                        Hi {{ $user->name }},<br />
                                                        You added items to your cart but didn't complete your order. Your cart is saved and ready for you!
                                                    </p>
                                                    @elseif($emailType === '24hr')
                                                    <h1>Your cart is waiting for you!</h1>
                                                    <p>
                                                        Hi {{ $user->name }},<br />
                                                        Your cart is still waiting! Don't let these items sell out before you complete your purchase.
                                                    </p>
                                                    @else
                                                    <h1>Last chance! Don't miss out!</h1>
                                                    <p>
                                                        Hi {{ $user->name }},<br />
                                                        This is your final reminder. Items in your cart are in high demand — complete your order before they're gone!
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

                            {{-- ── Products List ── --}}
                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table>
                                            <tr>
                                                <th>
                                                    <table class="products">
                                                        @foreach($cartItems as $item)
                                                        <tr class="products__item">
                                                            @if(!empty($item['thumbnail']))
                                                            <th class="products__image-container">
                                                                <img src="{{ $item['thumbnail'] }}" class="products__image" alt="{{ $item['name'] }}">
                                                            </th>
                                                            @endif

                                                            <th class="products__content">
                                                                <p>
                                                                    <strong>{{ $item['name'] }}</strong>
                                                                </p>
                                                                @if(!empty($item['sku']))
                                                                <p class="products__sku">{{ $item['sku'] }}</p>
                                                                @endif
                                                            </th>

                                                            <th class="products__quantity">
                                                                <p>
                                                                    Qty: {{ $item['quantity'] }}
                                                                </p>
                                                            </th>
                                                        </tr>
                                                        @endforeach
                                                    </table>
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

                            {{-- ── Complete Order Message ── --}}
                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table>
                                            <tr>
                                                <th>
                                                    <p>Ready to complete your purchase? Click the button below to go back to your cart.</p>
                                                </th>
                                                <th class="expander"></th>
                                            </tr>
                                        </table>
                                    </th>
                                </tr>
                            </table>

                            <table class="spacer spacer--16">
                                <tr>
                                    <td>&nbsp;</td>
                                </tr>
                            </table>

                            {{-- ── CTA Button ── --}}
                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table class="complete-order">
                                            <tr>
                                                <th>
                                                    <p>
                                                        <a href="{{ $checkoutLink }}" target="_blank">
                                                            Complete Your Order
                                                        </a>
                                                    </p>
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

                {{-- ── Footer ── --}}
                <table class="container">
                    <tr>
                        <td>
                            <table class="row">
                                <tr>
                                    <th class="column column--md-9 column--xs-12">
                                        <table class="store">
                                            <tr>
                                                <th>
                                                    <p><strong>{{ config('app.name') }}</strong></p>
                                                    <p>
                                                        <a href="{{ config('app.frontend_url') }}" target="_blank">
                                                            {{ parse_url(config('app.frontend_url'), PHP_URL_HOST) }}
                                                        </a>
                                                    </p>
                                                </th>
                                                <th class="expander"></th>
                                            </tr>
                                        </table>
                                    </th>

                                    <th class="column column--md-3 column--xs-12">
                                        <table class="store-button">
                                            <tr>
                                                <th>
                                                    <a href="{{ config('app.frontend_url') }}">
                                                        <strong>Go Shopping</strong>
                                                    </a>
                                                </th>
                                                <th class="expander"></th>
                                            </tr>
                                        </table>
                                    </th>
                                </tr>
                            </table>

                            <table class="spacer spacer--40">
                                <tr>
                                    <td>&nbsp;</td>
                                </tr>
                            </table>

                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table class="unsubscribe">
                                            <tr>
                                                <th>
                                                    <div>
                                                        <p style="text-align: center; color: #777; font-size: 12px;">
                                                            You're receiving this email because you have items in your cart at
                                                            <a href="{{ config('app.frontend_url') }}">{{ config('app.name') }}</a>.
                                                        </p>
                                                    </div>
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