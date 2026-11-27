<?php

namespace App\Services;

/**
 * Builds the data ("context") that store-editable transactional email
 * templates are rendered with. Values mirror what the old Blade emails
 * printed, so switching a store to templates does not lose information.
 *
 * Template variable names are snake_case (e.g. {{order.total}}, {{customer.first_name}}).
 * Money values are pre-formatted strings; "*_value" holds the raw number.
 */
class TransactionalEmailContext
{
    /**
     * Template keys whose emails the application actually sends (and that
     * have v2 templates). The other seeded keys have no sending feature yet.
     */
    public const SENDING_KEYS = [
        'order_email',
        'order_status_update',
        'order_ready_for_pickup',
        'guest_account_created',
        'account_created',
        'sign_in_link_request',
        'password_reset',
        'order_notification',
        'return_requested',
        'account_settings_edited',
    ];

    /**
     * All 14 template keys (every one has a v3 template and preview data).
     * Keys not in SENDING_KEYS have no sending feature yet.
     */
    public const ALL_KEYS = [
        'order_email', 'order_status_update', 'order_ready_for_pickup',
        'guest_account_created', 'account_created', 'sign_in_link_request',
        'password_reset', 'order_notification', 'return_requested',
        'account_settings_edited', 'return_status_change',
        'gift_certificate_recipient', 'product_review_request', 'guest_order_access',
    ];

    /** Same asset base the Blade order email used for relative image paths. */
    private const ASSET_BASE = 'https://aws-backend-assets.s3.us-east-2.amazonaws.com/';

    /**
     * Status texts copied from the Blade order-status email.
     */
    private const STATUS_MESSAGES = [
        'Shipped'              => 'Great news! Your order has been shipped. You will receive your items soon.',
        'Completed'            => 'Your order has been completed. Thank you for shopping with us!',
        'Cancelled'            => 'Your order has been cancelled. If you have any questions, please contact us.',
        'Refunded'             => 'Your order has been refunded. The amount will be credited back to your payment method.',
        'Awaiting Fulfillment' => 'Your payment has been received. We are now preparing your order for shipment.',
        'Awaiting Shipment'    => 'Your order is packed and ready to be shipped.',
    ];

    private const STATUS_COLORS = [
        'Awaiting Payment'     => '#f0ad4e',
        'Awaiting Fulfillment' => '#5bc0de',
        'Awaiting Shipment'    => '#5bc0de',
        'Shipped'              => '#337ab7',
        'Completed'            => '#5cb85c',
        'Cancelled'            => '#d9534f',
        'Refunded'             => '#d9534f',
        'Declined'             => '#d9534f',
        'Pending'              => '#777',
    ];

    // ------------------------------------------------------------- pieces

    /**
     * @param object|null $store central Store registry record
     */
    public static function store($store, ?string $logoUrl = null): array
    {
        $url = rtrim((string) ($store->base_url ?? ''), '/');
        $name = (string) ($store->name ?? 'Our Store');

        return [
            // "{{store}}" in legacy templates prints the store name.
            '__toString' => $name,
            'name'       => $name,
            'url'        => $url,
            'domain'     => preg_replace('#^https?://#', '', $url),
            'logo_url'   => (string) ($logoUrl ?? ''),
        ];
    }

    /**
     * Base context every template receives.
     */
    public static function base($store, ?string $logoUrl = null): array
    {
        $storeCtx = self::store($store, $logoUrl);

        return [
            'store'     => $storeCtx,
            // Legacy flat names used by the original seeded templates.
            'store_url' => $storeCtx['url'],
            'logo_url'  => $storeCtx['logo_url'],
            'year'      => date('Y'),
        ];
    }

    public static function money($amount, string $symbol = '$'): string
    {
        return $symbol . number_format((float) $amount, 2);
    }

    /**
     * @param object|array|null $billing BillingInformation
     */
    public static function address($billing): array
    {
        $get = fn(string $k) => trim((string) data_get($billing, $k, ''));

        $first = $get('first_name');
        $last = $get('last_name');
        $cityLine = trim(implode(', ', array_filter([$get('city'), trim($get('state') . ' ' . $get('zip'))])));

        return [
            'first_name'     => $first,
            'last_name'      => $last,
            'full_name'      => trim($first . ' ' . $last),
            'company'        => $get('company_name'),
            'email'          => $get('email'),
            'phone'          => $get('phone'),
            'address_line_1' => $get('address_line_1'),
            'address_line_2' => $get('address_line_2'),
            'city'           => $get('city'),
            'state'          => $get('state'),
            'zip'            => $get('zip'),
            'country'        => $get('country'),
            'city_line'      => $cityLine,
        ];
    }

    /**
     * @param object|array $order Order with items.product.images/brand and billingInformation
     */
    public static function order($order): array
    {
        $billing = data_get($order, 'billingInformation') ?? data_get($order, 'billing_information');

        $items = [];
        $subtotal = 0.0;

        foreach (data_get($order, 'items') ?? [] as $item) {
            $product = data_get($item, 'product');
            $price = (float) data_get($item, 'price', 0);
            $qty = (int) data_get($item, 'quantity', 0);
            $line = $price * $qty;
            $subtotal += $line;

            $items[] = [
                'name'       => (string) (data_get($product, 'name') ?? 'Product'),
                'sku'        => (string) (data_get($product, 'sku') ?? ''),
                'brand'      => (string) (data_get($product, 'brand.name') ?? ''),
                'image_url'  => self::productImage($product),
                'quantity'   => $qty,
                'price'      => self::money($price),
                'price_value' => $price,
                'total'      => self::money($line),
                'total_value' => $line,
            ];
        }

        $shipping = (float) (data_get($order, 'shipping_cost') ?? data_get($order, 'shippingCost') ?? 0);
        $discount = (float) (data_get($order, 'discountAmount') ?? 0);
        $manual = (float) (data_get($order, 'manual_discount') ?? data_get($order, 'manualDiscount') ?? 0);
        $total = (float) (data_get($order, 'total_amount') ?? data_get($order, 'totalAmount') ?? 0);

        $shippingData = data_get($billing, 'shipping_data');
        if (is_string($shippingData)) {
            $shippingData = json_decode($shippingData, true);
        }

        $paymentMethod = (string) (data_get($billing, 'payment_method') ?? '');
        $status = (string) (data_get($order, 'status') ?? '');
        $createdAt = data_get($order, 'created_at');

        return [
            'id'                => (string) (data_get($order, 'order_number') ?? data_get($order, 'orderNumber') ?? 'N/A'),
            'status'            => $status,
            'date'              => $createdAt ? \Illuminate\Support\Carbon::parse($createdAt)->format('M d, Y h:i A') : '',
            'items'             => $items,
            'item_count'        => count($items),
            'subtotal'          => self::money($subtotal),
            'subtotal_value'    => $subtotal,
            'shipping'          => self::money($shipping),
            'shipping_value'    => $shipping,
            'discount'          => $discount > 0 ? self::money($discount) : '',
            'discount_value'    => $discount,
            'manual_discount'   => $manual > 0 ? self::money($manual) : '',
            'manual_discount_value' => $manual,
            'coupon_code'       => (string) (data_get($order, 'coupon_code') ?? ''),
            'total'             => self::money($total),
            'total_value'       => $total,
            'payment_method'    => $paymentMethod !== '' ? ucfirst(str_replace('_', ' ', $paymentMethod)) : '',
            'shipping_method'   => (string) (data_get($shippingData, 'display_name') ?? data_get($billing, 'shipping_method') ?? ''),
            'comments'          => (string) (data_get($order, 'comments') ?? ''),
            'tracking_number'   => (string) (data_get($order, 'tracking_number') ?? ''),
            'tracking_carrier'  => (string) (data_get($order, 'tracking_carrier_name') ?? ''),
            'billing_address'   => $billing ? self::address($billing) : null,
            // Orders store one address (billing); no separate shipping address yet.
            'shipping_address'  => null,
        ];
    }

    private static function productImage($product): string
    {
        $images = data_get($product, 'images');

        if (!$images) {
            return '';
        }

        $images = collect($images);
        $image = $images->firstWhere('is_primary', 1) ?? $images->first();
        $path = (string) (data_get($image, 'path') ?? data_get($image, 'url') ?? '');

        if ($path === '') {
            return '';
        }

        return str_starts_with($path, 'http') ? $path : self::ASSET_BASE . ltrim($path, '/');
    }

    public static function status(string $status): array
    {
        return [
            'name'    => $status,
            'message' => self::STATUS_MESSAGES[$status]
                ?? 'Your order status has been updated to ' . $status . '. If you have any questions, please contact us.',
            'color'   => self::STATUS_COLORS[$status] ?? '#555',
        ];
    }

    /**
     * @param object|array|null $shipment
     */
    public static function shipment($shipment): ?array
    {
        if (!$shipment) {
            return null;
        }

        return [
            'method'          => (string) (data_get($shipment, 'shipping_method') ?? ''),
            'shipped_by'      => (string) (data_get($shipment, 'shipped_by') ?? ''),
            'tracking_number' => (string) (data_get($shipment, 'tracking_number') ?? ''),
        ];
    }

    public static function customer(?string $firstName, ?string $email, ?string $fullName = null): array
    {
        $fullName = trim((string) ($fullName ?? $firstName ?? ''));
        $first = trim((string) ($firstName ?? ''));

        if ($first === '' && $fullName !== '') {
            $first = explode(' ', $fullName)[0];
        }

        return [
            // Same fallback the Blade emails used.
            'first_name' => $first !== '' ? $first : 'Customer',
            'name'       => $fullName,
            'email'      => (string) ($email ?? ''),
        ];
    }

    /**
     * Adds the flat names used by the original seeded phrases ({{name}},
     * {{id}}, {{link}}, {{order_id}}, {{order_total}}, {{email}}), so phrases a
     * store owner edited before the upgrade still render. Existing keys win.
     */
    public static function withLegacyAliases(array $data): array
    {
        $aliases = [
            'name'        => data_get($data, 'customer.first_name'),
            'email'       => data_get($data, 'customer.email'),
            'id'          => data_get($data, 'order.id'),
            'order_id'    => data_get($data, 'order.id'),
            'order_total' => data_get($data, 'order.total'),
            'link'        => data_get($data, 'links.sign_in') ?: data_get($data, 'links.set_password') ?: data_get($data, 'links.register'),
            'from'        => data_get($data, 'gift.from_name'),
        ];

        if (isset($data['status']) && is_array($data['status'])) {
            // {{status}} prints the status name; {{status.color}} still works.
            $data['status']['__toString'] = $data['status']['name'] ?? '';
        }

        return $data + array_filter($aliases, fn($v) => $v !== null && $v !== '');
    }

    /**
     * Applies preview values sent by the dashboard on top of sample data.
     *
     * Older dashboard builds send flat values such as data.store = "" (the
     * previous preview expected a store-name string). Those must never wipe
     * out structured values (store, order, ...), so a scalar never replaces
     * an array, and empty/null values are ignored.
     */
    public static function mergeOverrides(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (array_key_exists($key, $base) && is_array($base[$key])) {
                if (is_array($value)) {
                    $base[$key] = self::mergeOverrides($base[$key], $value);
                }
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    // ------------------------------------------------- preview sample data

    /**
     * Realistic sample data for dashboard preview / test sends.
     */
    public static function sample(string $key, $store, ?string $logoUrl = null): array
    {
        $base = self::base($store, $logoUrl);
        $url = $base['store']['url'] ?: 'https://example.com';

        $order = [
            'order_number' => '1001',
            'status'       => 'Shipped',
            'created_at'   => now(),
            'shipping_cost' => 10,
            'discountAmount' => 5,
            'total_amount' => 64.98,
            'comments'     => 'Please leave at the front door.',
            'tracking_number' => '1Z999AA10123456784',
            'billingInformation' => [
                'first_name' => 'Jane', 'last_name' => 'Doe', 'company_name' => '',
                'email' => 'jane@example.com', 'phone' => '+1 555 0100',
                'address_line_1' => '123 Main Street', 'address_line_2' => '',
                'city' => 'New York', 'state' => 'NY', 'zip' => '10001', 'country' => 'United States',
                'payment_method' => 'credit_card', 'shipping_data' => ['display_name' => 'Standard Shipping'],
            ],
            'items' => [
                ['price' => 24.99, 'quantity' => 2, 'product' => ['name' => 'Sample Product', 'sku' => 'SKU-001', 'brand' => ['name' => 'Brand'], 'images' => []]],
                ['price' => 10.00, 'quantity' => 1, 'product' => ['name' => 'Another Product', 'sku' => 'SKU-002', 'images' => []]],
            ],
        ];

        $customer = self::customer('Jane', 'jane@example.com', 'Jane Doe');
        $orderCtx = self::order($order);

        // Product review request: one review link per item.
        foreach ($orderCtx['items'] as $i => $item) {
            $orderCtx['items'][$i]['review_url'] = $url;
        }

        $status = match (true) {
            $key === 'order_ready_for_pickup' => self::status('Awaiting Pickup'),
            // Return emails: {{status}} is the return request status.
            str_starts_with($key, 'return_')  => ['name' => 'Pending', 'message' => '', 'color' => '#777'],
            default                           => self::status('Shipped'),
        };

        return array_merge($base, [
            'customer' => $customer,
            'order'    => $orderCtx,
            'status'   => $status,
            'shipment' => self::shipment(['shipping_method' => 'Standard Shipping', 'shipped_by' => 'FedEx', 'tracking_number' => '1Z999AA10123456784']),
            'links'    => [
                'register'       => $url . '/auth/signup',
                'set_password'   => '',
                'sign_in'        => $url . '/?actiontoken=sample',
                'reset_password' => $url . '/auth/reset-password?token=sample&email=jane%40example.com',
                'account'        => $url,
                'order_status'   => $url,
                'view_order'     => $url,
                'return_status'  => $url,
                'redeem'         => $url,
            ],
            'message_data' => [
                'subject' => 'About your order',
                'body'    => 'Hi Jane, your order will ship tomorrow.',
            ],
            'return'   => self::returnRequest([
                'reason'       => 'Received wrong item',
                'returnAction' => 'Refund',
                'comments'     => 'The size does not fit.',
                'status'       => 'Pending',
            ], $order),
            'account'  => ['changes' => 'Email, Password'],
            'gift'     => [
                'from_name'   => 'John Smith',
                'from_email'  => 'john@example.com',
                'amount'      => '$50.00',
                'expiry_date' => now()->addYear()->format('jS M Y'),
            ],
        ]);
    }

    /**
     * Return request data for return emails.
     *
     * @param object|array $return ReturnRequest (reason, returnAction, comments, status)
     * @param object|array $order  Order with items.product
     */
    public static function returnRequest($return, $order): array
    {
        $orderCtx = self::order($order);

        return [
            'reason'   => (string) (data_get($return, 'reason') ?? ''),
            'action'   => (string) (data_get($return, 'returnAction') ?? ''),
            'comments' => (string) (data_get($return, 'comments') ?? ''),
            'status'   => (string) (data_get($return, 'status') ?? ''),
            'items'    => $orderCtx['items'],
        ];
    }

    /**
     * Variables each template can use, for the dashboard editor.
     *
     * @return array<string, string> variable => description
     */
    public static function variables(string $key): array
    {
        $common = [
            'store.name'     => 'Store name',
            'store.url'      => 'Storefront URL',
            'store.domain'   => 'Storefront URL without https://',
            'store.logo_url' => 'Store logo image URL (empty if no logo)',
            'year'           => 'Current year',
            "lang 'phrase_name'" => 'A phrase from this template\'s Phrases tab',
        ];

        $customer = [
            'customer.first_name' => 'Customer first name',
            'customer.name'       => 'Customer full name',
            'customer.email'      => 'Customer email',
        ];

        $order = [
            'order.id'              => 'Order number',
            'order.status'          => 'Order status',
            'order.date'            => 'Order date',
            '#each order.items'     => 'Loop over items: name, sku, brand, image_url, quantity, price, total',
            'order.item_count'      => 'Number of line items',
            'order.subtotal'        => 'Items subtotal',
            'order.shipping'        => 'Shipping cost',
            'order.discount'        => 'Coupon/automatic discount (empty if none)',
            'order.manual_discount' => 'Manual discount (empty if none)',
            'order.coupon_code'     => 'Coupon code',
            'order.total'           => 'Order total',
            'order.payment_method'  => 'Payment method',
            'order.shipping_method' => 'Shipping method',
            'order.comments'        => 'Customer order comments',
            'order.billing_address.*' => 'full_name, company, email, phone, address_line_1, address_line_2, city, state, zip, country, city_line',
            'links.order_status'    => 'Link for the customer to check the order',
        ];

        $return = [
            'order.id'              => 'Order number',
            'return.reason'         => 'Return reason',
            'return.action'         => 'Requested action (refund, exchange, ...)',
            'return.comments'       => 'Customer comments',
            'return.status'         => 'Return status',
            '#each return.items'    => 'Loop over items: name, sku, brand, image_url, quantity',
            'links.return_status'   => 'Link to check the return',
        ];

        $map = [
            'order_email'            => $customer + $order,
            'order_status_update'    => $customer + $order + [
                'status.name'    => 'New status',
                'status.message' => 'Default message for the new status',
                'status.color'   => 'Badge colour for the new status',
                'order.tracking_number' => 'Tracking number',
                'shipment.method'      => 'Shipment method (Shipped only)',
                'shipment.shipped_by'  => 'Carrier (Shipped only)',
            ],
            'order_ready_for_pickup' => $customer + $order + [
                'status.name'    => 'New status',
                'status.message' => 'Default message for the new status',
            ],
            'guest_account_created'  => $customer + ['links.register' => 'Link to create an account'],
            'account_created'        => $customer + [
                'links.set_password' => 'Set-password link (only for accounts created by the store)',
                'links.account'      => 'Sign-in / account link',
            ],
            'sign_in_link_request'   => $customer + ['links.sign_in' => 'One-time sign-in link (valid 15 minutes)'],
            'password_reset'         => $customer + ['links.reset_password' => 'Password reset link'],
            'order_notification'     => $customer + [
                'order.id'        => 'Order number',
                'message_data.subject' => 'Message subject',
                'message_data.body'    => 'Message text',
                'links.account'        => 'Storefront link',
            ],
            'return_requested'       => $customer + $return,
            'return_status_change'   => $customer + $return + ['status.name' => 'Return status'],
            'account_settings_edited' => $customer + ['account.changes' => 'What changed, e.g. "Email, Password"'],
            'gift_certificate_recipient' => $customer + [
                'gift.from_name'   => 'Sender name',
                'gift.from_email'  => 'Sender email',
                'gift.amount'      => 'Gift certificate amount',
                'gift.expiry_date' => 'Expiry date',
                'links.redeem'     => 'How to redeem link',
            ],
            'product_review_request' => $customer + ['#each order.items' => 'Loop over purchased items: name, sku, image_url, review_url'],
            'guest_order_access'     => $customer + ['order.id' => 'Order number', 'order.total' => 'Order total', 'links.view_order' => 'Secure link to view the order'],
        ];

        return $common + ($map[$key] ?? []);
    }
}
