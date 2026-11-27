<?php

namespace Database\Seeders;

use App\Services\TransactionalEmailService;

/**
 * Default templates (v2) for transactional emails that the application
 * actually sends. They use EmailTemplateEngine (Handlebars-style) syntax
 * and real order/customer data instead of demo content.
 *
 * Every body starts with TransactionalEmailService::LIVE_MARKER; only
 * templates carrying it are used for live emails.
 *
 * Subjects render the "subject" phrase, so editing either the subject
 * field or the "subject" phrase in the dashboard works.
 */
class TransactionalEmailLiveTemplates
{
    /**
     * @return array<string, array{subject: string, body_html: string, phrases: array}>
     */
    public static function all(): array
    {
        return [
            'order_email' => [
                'subject' => "{{lang 'subject'}}",
                'body_html' => self::layout(
                    self::h1("{{lang 'title'}}")
                    . self::h2("{{lang 'order_title'}}")
                    . self::orderItems()
                    . self::orderTotals()
                    . self::billingAddress()
                    . '{{#if order.comments}}' . self::h2("{{lang 'comment_label'}}")
                    . '<p style="margin:0 0 8px;font-size:14px;color:#333333;white-space:pre-line;">{{order.comments}}</p>{{/if}}'
                    . self::button('{{links.order_status}}', "{{lang 'check_label'}}"),
                    'cart'
                ),
                'phrases' => self::phrases([
                    'subject'               => 'Your {{store.name}} Order Confirmation ({{order.id}})',
                    'title'                 => 'Thanks for your order',
                    'order_title'           => 'Order #{{order.id}}',
                    'quantity'              => 'Qty:',
                    'no_image'              => 'Image Coming Soon',
                    'sku'                   => 'SKU:',
                    'brand'                 => 'Brand:',
                    'subtotal'              => 'Subtotal',
                    'shipping'              => 'Shipping',
                    'discount'              => 'Discount',
                    'manual_discount'       => 'Manual Discount',
                    'total'                 => 'Total',
                    'shipping_method'       => 'Shipping method:',
                    'payment_method'        => 'Payment method:',
                    'billing_address_label' => 'Billing address',
                    'comment_label'         => 'Order comments',
                    'check_label'           => 'Check order status',
                    'go_shopping'           => 'Go shopping',
                ]),
            ],

            'order_status_update' => [
                'subject' => "{{lang 'subject'}}",
                'body_html' => self::layout(
                    self::h1("{{lang 'title'}}")
                    . self::p("{{lang 'hello'}}")
                    . self::p("{{lang 'message'}}")
                    . self::statusBadge()
                    . self::p('{{status.message}}')
                    . self::h3("{{lang 'details_title'}}")
                    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;margin:0 0 16px;">'
                    . self::row("{{lang 'order_total'}}", '{{order.total}}')
                    . self::row("{{lang 'date_placed'}}", '{{order.date}}')
                    . '{{#if order.shipping_value}}' . self::row("{{lang 'shipping'}}", '{{order.shipping}}') . '{{/if}}'
                    . '{{#if order.payment_method}}' . self::row("{{lang 'payment_method'}}", '{{order.payment_method}}') . '{{/if}}'
                    . '{{#if order.tracking_number}}' . self::row("{{lang 'tracking_number'}}", '{{order.tracking_number}}') . '{{/if}}'
                    . '{{#if shipment.method}}' . self::row("{{lang 'shipping_method'}}", '{{shipment.method}}') . '{{/if}}'
                    . '{{#if shipment.shipped_by}}' . self::row("{{lang 'shipped_by'}}", '{{shipment.shipped_by}}') . '{{/if}}'
                    . '</table>'
                    . self::button('{{links.order_status}}', "{{lang 'check_status'}}"),
                    'box'
                ),
                'phrases' => self::phrases([
                    'subject'         => 'Order #{{order.id}} Status Updated to {{status.name}} - {{store.name}}',
                    'title'           => 'Order status changed',
                    'hello'           => 'Hello {{customer.first_name}},',
                    'message'         => 'The status of your order #{{order.id}} has changed to:',
                    'details_title'   => 'Order details',
                    'order_total'     => 'Order total:',
                    'date_placed'     => 'Date placed:',
                    'shipping'        => 'Shipping:',
                    'payment_method'  => 'Payment method:',
                    'tracking_number' => 'Tracking number:',
                    'shipping_method' => 'Shipping method:',
                    'shipped_by'      => 'Shipped by:',
                    'check_status'    => 'Check order status',
                    'go_shopping'     => 'Go shopping',
                ]),
            ],

            'order_ready_for_pickup' => [
                'subject' => "{{lang 'subject'}}",
                'body_html' => self::layout(
                    self::h1("{{lang 'title'}}")
                    . self::p("{{lang 'hello'}}")
                    . self::p("{{lang 'message'}}")
                    . self::h2("{{lang 'order_title'}}")
                    . self::orderItems()
                    . self::h3("{{lang 'location_title'}}")
                    . self::p('<strong>{{store.name}}</strong><br><a href="{{store.url}}" style="color:#333;">{{store.domain}}</a>')
                    . self::p("{{lang 'opening_hours_note'}}")
                    . self::button('{{links.order_status}}', "{{lang 'check_status'}}"),
                    'store'
                ),
                'phrases' => self::phrases([
                    'subject'            => '{{store.name}} - Order #{{order.id}} is ready for pickup',
                    'title'              => 'Ready for pick up',
                    'hello'              => 'Hello {{customer.first_name}},',
                    'message'            => 'Good news! Your order is ready and waiting for you to pick it up.',
                    'order_title'        => 'Order #{{order.id}}',
                    'quantity'           => 'Qty:',
                    'no_image'           => 'Image Coming Soon',
                    'sku'                => 'SKU:',
                    'brand'              => 'Brand:',
                    'location_title'     => 'Pickup location',
                    'opening_hours_note' => 'Please visit our website to check opening hours.',
                    'check_status'       => 'Check order status',
                    'go_shopping'        => 'Go shopping',
                ]),
            ],

            'guest_account_created' => [
                'subject' => "{{lang 'subject'}}",
                'body_html' => self::layout(
                    self::h1("{{lang 'title'}}")
                    . self::p("{{lang 'hello'}}")
                    . self::p("{{lang 'message'}}")
                    . self::p("{{lang 'instruction'}}")
                    . self::button('{{links.register}}', "{{lang 'create_account'}}")
                    . self::p('<span style="color:#777;font-size:13px;">{{lang \'email_label\'}} {{customer.email}}</span>')
                    . self::p("{{lang 'help'}}"),
                    'user'
                ),
                'phrases' => self::phrases([
                    'subject'        => 'Create Your Account - {{store.name}}',
                    'title'          => 'Welcome to {{store.name}}',
                    'hello'          => 'Hello {{customer.first_name}},',
                    'message'        => 'Thanks for your order! Create an account to check the status of your orders and check out faster next time.',
                    'instruction'    => 'Click the button below to create your account:',
                    'create_account' => 'Create your account',
                    'email_label'    => 'Email address:',
                    'help'           => "If you have any questions, just reply to this email and we'll be happy to help.",
                    'go_shopping'    => 'Go shopping',
                ]),
            ],

            'account_created' => [
                'subject' => "{{lang 'subject'}}",
                'body_html' => self::layout(
                    self::h1("{{lang 'title'}}")
                    . self::p("{{lang 'hello'}}")
                    . self::p("{{lang 'message'}}")
                    . self::h3("{{lang 'details_title'}}")
                    . self::p("{{lang 'email_label'}} <a href=\"mailto:{{customer.email}}\" style=\"color:#333;\">{{customer.email}}</a>")
                    . '{{#if links.set_password}}'
                    . self::p("{{lang 'set_password_message'}}")
                    . self::button('{{links.set_password}}', "{{lang 'set_password'}}")
                    . '{{else}}'
                    . self::button('{{links.account}}', "{{lang 'sign_in'}}")
                    . '{{/if}}'
                    . self::p("{{lang 'help'}}"),
                    'user'
                ),
                'phrases' => self::phrases([
                    'subject'              => '{{#if links.set_password}}Your Login Details - Set Your Password{{else}}Thanks for registering at {{store.name}}{{/if}}',
                    'title'                => 'Welcome to {{store.name}}',
                    'hello'                => 'Hello {{customer.first_name}},',
                    'message'              => 'Thank you for creating your account at {{store.name}}.',
                    'details_title'        => 'Account details',
                    'email_label'          => 'Email address:',
                    'set_password_message' => 'An account has been created for you. Please set your password to sign in:',
                    'set_password'         => 'Set your password',
                    'sign_in'              => 'Sign in to account',
                    'help'                 => "If you have any questions regarding your account, just reply to this email and we'll be happy to help.",
                    'go_shopping'          => 'Go shopping',
                ]),
            ],

            'sign_in_link_request' => [
                'subject' => "{{lang 'subject'}}",
                'body_html' => self::layout(
                    self::h1("{{lang 'title'}}")
                    . self::p("{{lang 'message'}}")
                    . self::button('{{links.sign_in}}', "{{lang 'sign_in'}}")
                    . self::p("{{lang 'alternatively'}}")
                    . '<p style="margin:0 0 16px;word-break:break-all;font-size:13px;"><a href="{{links.sign_in}}" style="color:#333;">{{links.sign_in}}</a></p>'
                    . self::p("{{lang 'login_request'}}")
                    . self::p("{{lang 'secure'}}"),
                    'lock'
                ),
                'phrases' => self::phrases([
                    'subject'       => 'Your sign-in link for {{store.name}}',
                    'title'         => 'Sign in to {{store.name}}',
                    'message'       => 'You requested a sign-in link. Click the button below to sign in. The link expires in 15 minutes.',
                    'sign_in'       => 'Sign in',
                    'alternatively' => 'Or paste this link into your browser:',
                    'login_request' => 'This link is for {{store.name}}. If you did not request it, you can ignore this email.',
                    'secure'        => 'Your account is still secure.',
                    'go_shopping'   => 'Go shopping',
                ]),
            ],

            'password_reset' => [
                'subject' => "{{lang 'subject'}}",
                'body_html' => self::layout(
                    self::h1("{{lang 'title'}}")
                    . self::p("{{lang 'hello'}}")
                    . self::p("{{lang 'message'}}")
                    . self::p("{{lang 'warning'}}")
                    . self::button('{{links.account}}', "{{lang 'sign_in'}}"),
                    'lock'
                ),
                'phrases' => self::phrases([
                    'subject'     => 'Your Password Was Successfully Reset',
                    'title'       => 'Your password has been changed',
                    'hello'       => 'Hello {{customer.first_name}},',
                    'message'     => 'The password for your {{store.name}} account was successfully reset.',
                    'warning'     => 'If you did not make this change, please contact us immediately.',
                    'sign_in'     => 'Sign in to account',
                    'go_shopping' => 'Go shopping',
                ]),
            ],

            'order_notification' => [
                'subject' => "{{lang 'subject'}}",
                'body_html' => self::layout(
                    self::h1("{{lang 'title'}}")
                    . self::p("{{lang 'hello'}}")
                    . self::p("{{lang 'message'}}")
                    . '<div style="margin:0 0 20px;padding:16px;background:#f7f7f7;border-left:4px solid #333;border-radius:4px;">'
                    . '<p style="margin:0 0 8px;"><strong>{{message.subject}}</strong></p>'
                    . '<p style="margin:0;white-space:pre-line;">{{message.body}}</p>'
                    . '</div>'
                    . self::button('{{links.account}}', "{{lang 'reply'}}"),
                    'mail'
                ),
                'phrases' => self::phrases([
                    'subject'     => 'Re: {{message.subject}} - {{store.name}}',
                    'title'       => 'New reply to your order message',
                    'hello'       => 'Hello {{customer.first_name}},',
                    'message'     => '{{store.name}} replied to your message regarding order #{{order.id}}:',
                    'reply'       => 'Visit store',
                    'go_shopping' => 'Go shopping',
                ]),
            ],
        ];
    }

    // ------------------------------------------------------------ helpers

    private static function phrases(array $map): array
    {
        $out = [];

        foreach ($map as $name => $value) {
            $out[] = ['phrase_name' => $name, 'phrase_value' => $value, 'is_system' => true];
        }

        return $out;
    }

    /**
     * Shared layout, styled like the original seeded templates: centered
     * store name/logo, divider with an icon, outlined buttons, footer with
     * store name, URL and "Go shopping". Inline styles for email clients.
     */
    private static function layout(string $content, string $icon = 'mail'): string
    {
        $marker = TransactionalEmailService::LIVE_MARKER;
        $top = self::divider($icon);
        $bottom = self::divider('store');

        return <<<HTML
{$marker}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{store.name}}</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,Helvetica,sans-serif;color:#333333;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;">
<tr><td align="center" style="padding:0 16px;">
<table role="presentation" width="544" cellpadding="0" cellspacing="0" style="max-width:544px;width:100%;">
<tr><td align="center" style="padding:24px 0;">
<a href="{{store.url}}" style="text-decoration:none;color:#333333;">{{#if store.logo_url}}<img src="{{store.logo_url}}" alt="{{store.name}}" style="max-height:64px;max-width:240px;border:0;">{{else}}<span style="font-size:28px;font-weight:600;color:#333333;">{{store.name}}</span>{{/if}}</a>
</td></tr>
<tr><td>{$top}</td></tr>
<tr><td style="padding:24px 0;font-size:14px;line-height:1.5;color:#333333;">
{$content}
</td></tr>
<tr><td>{$bottom}</td></tr>
<tr><td style="padding:16px 0 24px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
<td style="vertical-align:middle;"><p style="margin:0 0 2px;font-size:14px;font-weight:600;color:#333333;">{{store.name}}</p><a href="{{store.url}}" style="font-size:12px;color:#777777;text-decoration:none;">{{store.domain}}</a></td>
<td align="right" style="vertical-align:middle;"><a href="{{store.url}}" style="display:inline-block;border:1px solid #cccccc;border-radius:4px;padding:6px 14px;color:#555555;font-weight:600;font-size:13px;text-decoration:none;">{{lang 'go_shopping'}}</a></td>
</tr></table>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    /**
     * Horizontal rule with a round icon in the middle. Emoji are used
     * because inline SVG is stripped by Gmail and Outlook.
     */
    private static function divider(string $icon): string
    {
        $glyph = [
            'cart'  => '&#128722;',
            'store' => '&#127978;',
            'box'   => '&#128230;',
            'user'  => '&#128100;',
            'lock'  => '&#128274;',
            'mail'  => '&#9993;',
        ][$icon] ?? '&#9993;';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:8px 0;"><tr>'
            . '<td width="45%" style="vertical-align:middle;"><div style="border-top:1px solid #e5e5e5;font-size:0;line-height:0;">&nbsp;</div></td>'
            . '<td width="10%" align="center" style="vertical-align:middle;"><span style="display:inline-block;width:40px;height:40px;line-height:40px;border-radius:50%;background:#f5f5f5;text-align:center;font-size:18px;color:#999999;">' . $glyph . '</span></td>'
            . '<td width="45%" style="vertical-align:middle;"><div style="border-top:1px solid #e5e5e5;font-size:0;line-height:0;">&nbsp;</div></td>'
            . '</tr></table>';
    }

    private static function h1(string $text): string
    {
        return '<h1 style="margin:0 0 16px;font-size:26px;font-weight:600;line-height:1.3;color:#333333;">' . $text . '</h1>';
    }

    private static function h2(string $text): string
    {
        return '<h2 style="margin:24px 0 12px;padding-bottom:8px;border-bottom:1px solid #e5e5e5;font-size:18px;font-weight:500;color:#333333;">' . $text . '</h2>';
    }

    private static function h3(string $text): string
    {
        return '<h3 style="margin:20px 0 8px;font-size:16px;font-weight:600;color:#333333;">' . $text . '</h3>';
    }

    private static function p(string $html): string
    {
        return '<p style="margin:0 0 12px;font-size:14px;line-height:1.5;color:#333333;">' . $html . '</p>';
    }

    /** Outlined button, as in the original templates. */
    private static function button(string $href, string $label): string
    {
        return '<p style="margin:20px 0 0;"><a href="' . $href . '" style="display:inline-block;border:1px solid #999999;border-radius:4px;padding:6px 14px;color:#555555;font-weight:600;font-size:14px;text-decoration:none;">' . $label . '</a></p>';
    }

    /** Right-aligned label/value row. */
    private static function row(string $label, string $value, bool $bold = false): string
    {
        $weight = $bold ? 'font-weight:600;color:#333333;' : 'color:#777777;';

        return '<tr><td align="right" style="padding:3px 12px 3px 0;font-size:13px;' . $weight . '">' . $label . '</td>'
            . '<td align="right" width="170" style="padding:3px 0;font-size:13px;font-weight:600;color:#333333;">' . $value . '</td></tr>';
    }

    private static function statusBadge(): string
    {
        return '<p style="margin:0 0 16px;"><span style="display:inline-block;padding:6px 14px;border-radius:14px;background:{{status.color}};color:#ffffff;font-weight:600;font-size:13px;">{{status.name}}</span></p>';
    }

    /**
     * Item rows: image (or "Image Coming Soon" box) | name, SKU, brand,
     * unit price | Qty | line total.
     */
    private static function orderItems(): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 8px;">'
            . '{{#each order.items}}'
            . '<tr>'
            . '<td width="76" style="padding:16px 0;border-bottom:1px solid #e5e5e5;vertical-align:top;">'
            . '{{#if image_url}}<img src="{{image_url}}" alt="{{name}}" width="60" height="60" style="display:block;width:60px;height:60px;object-fit:cover;border:1px solid #dddddd;border-radius:4px;">'
            . '{{else}}<div style="width:60px;height:60px;background:#f5f5f5;border:1px solid #dddddd;border-radius:4px;text-align:center;font-size:9px;line-height:1.3;color:#999999;padding-top:13px;box-sizing:border-box;">{{lang \'no_image\'}}</div>{{/if}}'
            . '</td>'
            . '<td style="padding:16px 8px;border-bottom:1px solid #e5e5e5;vertical-align:top;font-size:13px;">'
            . '<p style="margin:0 0 4px;font-size:14px;font-weight:600;color:#333333;">{{name}}</p>'
            . '{{#if sku}}<p style="margin:0;font-size:12px;color:#666666;">{{lang \'sku\'}} {{sku}}</p>{{/if}}'
            . '{{#if brand}}<p style="margin:0;font-size:12px;color:#666666;">{{lang \'brand\'}} {{brand}}</p>{{/if}}'
            . '<p style="margin:4px 0 0;font-size:12px;color:#666666;">{{price}}</p>'
            . '</td>'
            . '<td width="60" style="padding:16px 8px;border-bottom:1px solid #e5e5e5;vertical-align:top;font-size:13px;color:#555555;white-space:nowrap;">{{lang \'quantity\'}} {{quantity}}</td>'
            . '<td width="80" align="right" style="padding:16px 0;border-bottom:1px solid #e5e5e5;vertical-align:top;font-size:14px;font-weight:600;color:#333333;white-space:nowrap;">{{total}}</td>'
            . '</tr>'
            . '{{/each}}'
            . '</table>';
    }

    /** Right-aligned summary: totals, then payment and shipping method. */
    private static function orderTotals(): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 8px;">'
            . self::row("{{lang 'subtotal'}}", '{{order.subtotal}}')
            . '{{#if order.shipping_value}}' . self::row("{{lang 'shipping'}}", '{{order.shipping}}') . '{{/if}}'
            . '{{#if order.discount}}' . self::row("{{lang 'discount'}}", '-{{order.discount}}') . '{{/if}}'
            . '{{#if order.manual_discount}}' . self::row("{{lang 'manual_discount'}}", '-{{order.manual_discount}}') . '{{/if}}'
            . self::row("{{lang 'total'}}", '{{order.total}}', true)
            . '{{#if order.payment_method}}' . self::row("{{lang 'payment_method'}}", '{{order.payment_method}}') . '{{/if}}'
            . '{{#if order.shipping_method}}' . self::row("{{lang 'shipping_method'}}", '{{order.shipping_method}}') . '{{/if}}'
            . '</table>';
    }

    private static function billingAddress(): string
    {
        $a = 'order.billing_address';

        return '{{#if ' . $a . '}}'
            . self::h2("{{lang 'billing_address_label'}}")
            . '<p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#555555;">'
            . '<strong style="color:#333333;">{{' . $a . '.full_name}}</strong><br>'
            . '{{#if ' . $a . '.company}}{{' . $a . '.company}}<br>{{/if}}'
            . '{{' . $a . '.address_line_1}}<br>'
            . '{{#if ' . $a . '.address_line_2}}{{' . $a . '.address_line_2}}<br>{{/if}}'
            . '{{' . $a . '.city_line}}<br>'
            . '{{' . $a . '.country}}'
            . '{{#if ' . $a . '.phone}}<br>{{' . $a . '.phone}}{{/if}}'
            . '</p>'
            . '{{/if}}';
    }
}
