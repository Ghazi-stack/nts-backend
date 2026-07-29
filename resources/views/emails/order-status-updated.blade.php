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

        .details__content th:first-child {
            padding-right: 30px;
            width: 120px;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 16px;
            color: #fff;
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
                                                    <h1>Order Status Updated</h1>

                                                    <p>
                                                        Hello {{ $order->billingInformation->first_name ?? 'Customer' }},
                                                        <br /><br />
                                                        Your order <strong>#{{ $order->order_number }}</strong> status has been updated.
                                                    </p>
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

                            <!-- Order Details -->
                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table>
                                            <tr>
                                                <th>
                                                    <h2>Order Details</h2>

                                                    <table class="details">
                                                        <tr>
                                                            <th>
                                                                <table class="details__content">
                                                                    <tr>
                                                                        <th style="width: 120px; padding-right: 30px;">
                                                                            <p>Order Number:</p>
                                                                        </th>
                                                                        <th>
                                                                            <p><strong>#{{ $order->order_number }}</strong></p>
                                                                        </th>
                                                                    </tr>

                                                                    <tr>
                                                                        <th style="width: 120px; padding-right: 30px;">
                                                                            <p>Current Status:</p>
                                                                        </th>
                                                                        <th>
                                                                            <p>
                                                                                @php
                                                                                $statusColors = [
                                                                                'Awaiting Payment' => '#f0ad4e',
                                                                                'Awaiting Fulfillment' => '#5bc0de',
                                                                                'Awaiting Shipment' => '#5bc0de',
                                                                                'Shipped' => '#337ab7',
                                                                                'Completed' => '#5cb85c',
                                                                                'Cancelled' => '#d9534f',
                                                                                'Refunded' => '#d9534f',
                                                                                'Declined' => '#d9534f',
                                                                                'Pending' => '#777',
                                                                                ];
                                                                                $bgColor = $statusColors[$newStatus] ?? '#555';
                                                                                @endphp
                                                                                <span class="status-badge" style="background-color: {{ $bgColor }};">{{ $newStatus }}</span>
                                                                            </p>
                                                                        </th>
                                                                    </tr>

                                                                    <tr>
                                                                        <th style="width: 120px; padding-right: 30px;">
                                                                            <p>Order Total:</p>
                                                                        </th>
                                                                        <th>
                                                                            <p><strong>${{ number_format($order->total_amount, 2) }}</strong></p>
                                                                        </th>
                                                                    </tr>

                                                                    <tr>
                                                                        <th style="width: 120px; padding-right: 30px;">
                                                                            <p>Order Date:</p>
                                                                        </th>
                                                                        <th>
                                                                            <p><strong>{{ $order->created_at?->format('M d, Y h:i A') }}</strong></p>
                                                                        </th>
                                                                    </tr>

                                                                    @if($order->shipping_cost > 0)
                                                                    <tr>
                                                                        <th style="width: 120px; padding-right: 30px;">
                                                                            <p>Shipping Cost:</p>
                                                                        </th>
                                                                        <th>
                                                                            <p><strong>${{ number_format($order->shipping_cost, 2) }}</strong></p>
                                                                        </th>
                                                                    </tr>
                                                                    @endif

                                                                    {{-- Sirf Shipped status pe yeh fields dikhengi --}}
                                                                    @if($newStatus === 'Shipped')

                                                                    @if($order->tracking_number)
                                                                    <tr>
                                                                        <th style="width: 120px; padding-right: 30px;">
                                                                            <p>Tracking #:</p>
                                                                        </th>
                                                                        <th>
                                                                            <p><strong>{{ $order->tracking_number }}</strong></p>
                                                                        </th>
                                                                    </tr>
                                                                    @endif

                                                                    @if($shipment)
                                                                    <tr>
                                                                        <th style="width: 120px; padding-right: 30px;">
                                                                            <p>Shipping Method:</p>
                                                                        </th>
                                                                        <th>
                                                                            <p><strong>{{ $shipment->shipping_method ?? 'N/A' }}</strong></p>
                                                                        </th>
                                                                    </tr>

                                                                    <tr>
                                                                        <th style="width: 120px; padding-right: 30px;">
                                                                            <p>Shipped By:</p>
                                                                        </th>
                                                                        <th>
                                                                            <p><strong>{{ $shipment->shipped_by ?? 'N/A' }}</strong></p>
                                                                        </th>
                                                                    </tr>
                                                                    @endif

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

                            <table class="spacer spacer--32">
                                <tr>
                                    <td>&nbsp;</td>
                                </tr>
                            </table>

                            <!-- Billing Address -->
                            <!-- @if($order->billingInformation)
                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table>
                                            <tr>
                                                <th>
                                                    <h2>Billing Address</h2>

                                                    <table class="details">
                                                        <tr>
                                                            <th>
                                                                <table class="details__content">
                                                                    <tr>
                                                                        <th>
                                                                            <p>
                                                                                <strong>{{ $order->billingInformation->first_name }} {{ $order->billingInformation->last_name }}</strong><br>
                                                                                @if($order->billingInformation->company_name){{ $order->billingInformation->company_name }}<br>@endif
                                                                                {{ $order->billingInformation->address_line_1 }}<br>
                                                                                @if($order->billingInformation->address_line_2){{ $order->billingInformation->address_line_2 }}<br>@endif
                                                                                {{ $order->billingInformation->city }}, {{ $order->billingInformation->state }} {{ $order->billingInformation->zip }}<br>
                                                                                {{ $order->billingInformation->country }}<br>
                                                                                {{ $order->billingInformation->email }}<br>
                                                                                @if($order->billingInformation->phone){{ $order->billingInformation->phone }}@endif
                                                                            </p>
                                                                        </th>
                                                                    </tr>
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
                            @endif -->

                            <table class="spacer spacer--32">
                                <tr>
                                    <td>&nbsp;</td>
                                </tr>
                            </table>

                            <!-- Status Message -->
                            <table class="row">
                                <tr>
                                    <th class="column">
                                        <table>
                                            <tr>
                                                <th>
                                                    @if($newStatus === 'Shipped')
                                                    <p>Great news! Your order has been shipped. You will receive your items soon.</p>
                                                    @elseif($newStatus === 'Completed')
                                                    <p>Your order has been completed. Thank you for shopping with us!</p>
                                                    @elseif($newStatus === 'Cancelled')
                                                    <p>Your order has been cancelled. If you have any questions, please contact us.</p>
                                                    @elseif($newStatus === 'Refunded')
                                                    <p>Your order has been refunded. The amount will be credited back to your payment method.</p>
                                                    @elseif($newStatus === 'Awaiting Fulfillment')
                                                    <p>Your payment has been received. We are now preparing your order for shipment.</p>
                                                    @elseif($newStatus === 'Awaiting Shipment')
                                                    <p>Your order is packed and ready to be shipped.</p>
                                                    @else
                                                    <p>Your order status has been updated to <strong>{{ $newStatus }}</strong>. If you have any questions, please contact us.</p>
                                                    @endif
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