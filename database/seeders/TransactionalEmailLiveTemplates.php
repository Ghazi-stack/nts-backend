<?php

namespace Database\Seeders;

use App\Services\TransactionalEmailService;

/**
 * Default templates (v3) for all 14 transactional emails.
 *
 * Each template keeps the original seeded design exactly: the <head> styles,
 * logo header, divider icons and footer are taken unchanged from
 * TransactionalEmailTemplateSeeder::legacyTemplates(). Only the content block
 * is replaced, swapping the demo values ("Demo Product", "#1", "$250.100",
 * "John Doe") for real data placeholders. Phrases are the original phrases
 * (same names and wording), plus a few new ones for values that were
 * hard-coded text before.
 *
 * Bodies start with TransactionalEmailService::LIVE_MARKER; only templates
 * carrying it are used for real emails.
 */
class TransactionalEmailLiveTemplates
{
    /**
     * @return array<string, array{subject: string, body_html: string, phrases: array}>
     */
    public static function all(): array
    {
        $legacy = collect((new TransactionalEmailTemplateSeeder())->legacyTemplates())->keyBy('key');
        $out = [];

        foreach (self::contents() as $key => [$content, $extraPhrases]) {
            $old = $legacy->get($key);

            $phrases = collect($old['phrases'] ?? [])
                ->pluck('phrase_value', 'phrase_name')
                ->merge($extraPhrases)
                ->all();

            // Phrases are always printed with {{lang 'name'}} so a phrase can
            // never be shadowed by a data value of the same name
            // (e.g. phrase "order_total" = "Order total:" vs the order total).
            foreach (array_keys($phrases) as $name) {
                $content = str_replace('{{' . $name . '}}', "{{lang '" . $name . "'}}", $content);
            }

            $out[$key] = [
                // Subject comes from the "subject" phrase, so it can be edited on the Phrases tab.
                'subject'   => "{{lang 'subject'}}",
                'body_html' => self::wrap($old['body_html'], $content),
                'phrases'   => self::phrases($phrases),
            ];
        }

        return $out;
    }

    /**
     * Content blocks (what sits inside <div class="content">) and the
     * phrases each one adds to the original phrase set.
     *
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    private static function contents(): array
    {
        return [
            'order_email' => [
                '<h1>{{title}}</h1>'
                . '<h2>{{lang \'product_title\'}}</h2>'
                . self::itemsLoop('order.items', true)
                . self::orderTotals()
                . '{{#if order.shipping_address}}<h2>{{shipping_address_label}}</h2>' . self::address('order.shipping_address') . '{{/if}}'
                . '{{#if order.billing_address}}<h2>{{billing_address_label}}</h2>' . self::address('order.billing_address') . '{{/if}}'
                . '{{#if order.comments}}<h2>{{comment_label}}</h2><p style="white-space:pre-line">{{order.comments}}</p>{{/if}}'
                . '<a href="{{links.order_status}}" class="check-btn">{{check_label}}</a>',
                [
                    'product_title'  => 'Order #{{id}}',
                    'brand_label'    => 'Brand:',
                    'subtotal_label' => 'Subtotal:',
                    'shipping_label' => 'Shipping:',
                    'discount_label' => 'Discount:',
                    'total_label'    => 'Total:',
                ],
            ],

            'order_status_update' => [
                '<h1>{{title}}</h1>'
                . '<p>{{hello}}</p>'
                . '<p>{{message}}</p>'
                . '{{#if status.message}}<p>{{status.message}}</p>{{/if}}'
                . '<h2>{{details_title}}</h2>'
                . '<div class="details">'
                . self::detailsRow('{{order_total}}', '{{order.total}}')
                . self::detailsRow('{{date_placed}}', '{{order.date}}')
                . '{{#if order.payment_method}}' . self::detailsRow('{{payment_method}}', '{{order.payment_method}}') . '{{/if}}'
                . '{{#if shipment.method}}' . self::detailsRow('{{lang \'shipping_method\'}}', '{{shipment.method}}') . '{{/if}}'
                . '</div>'
                . '{{#if order.tracking_number}}<h2>{{lang \'tracking_title\'}}</h2><ul style="margin:0 0 16px;padding-left:20px;font-size:14px"><li>{{order.tracking_number}}{{#if shipment.shipped_by}} ({{shipment.shipped_by}}){{/if}}</li></ul>{{/if}}'
                . '{{#if order.items}}<h2>{{lang \'products_title\'}}</h2>' . self::itemsLoop('order.items', false) . '{{/if}}'
                . '<a href="{{links.order_status}}" class="check-btn">{{check_status}}</a>',
                [
                    'shipping_method' => 'Shipping method:',
                    'tracking_title'  => 'Tracking information',
                    'products_title'  => 'Products in this order',
                    'brand_label'     => 'Brand:',
                    'quantity'        => 'Qty:',
                ],
            ],

            'order_ready_for_pickup' => [
                '<h1>{{title}}</h1>'
                . '<h2>{{product_title}}</h2>'
                . self::itemsLoop('order.items', false)
                . '<h2>{{location_title}}</h2>'
                . '<div class="pickup"><div class="pickup-address">'
                . '<p><strong>{{store.name}}</strong></p>'
                . '<p><a href="{{store.url}}">{{store.domain}}</a></p>'
                . '</div></div>'
                . '<p class="hours-note">{{opening_hours_note}}</p>'
                . '<a href="{{links.order_status}}" style="display:inline-block;border:1px solid #999;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;text-decoration:none;margin-top:12px">{{lang \'check_status\'}}</a>',
                [
                    'brand_label'  => 'Brand:',
                    'check_status' => 'Check order status',
                ],
            ],

            'account_settings_edited' => [
                '<h1>{{subject}}</h1>'
                . '<p>{{hello}}</p>'
                . '<p>{{message}}</p>'
                . '<p><strong>{{account.changes}}</strong></p>'
                . '<p>{{warning}}</p>'
                . '<p style="margin-top:20px">{{thanks}}<br>{{security}}</p>',
                [],
            ],

            'password_reset' => [
                '<h1>{{title}}</h1>'
                . '<p>{{message}}</p>'
                . '<p><a href="{{links.reset_password}}">{{links.reset_password}}</a></p>',
                [],
            ],

            'account_created' => [
                '<h1>{{subject}}</h1>'
                . '<p>{{hello}}</p>'
                . '<p>{{message}}</p>'
                . '<h2>{{details_title}}</h2>'
                . '<div class="details">'
                . '<div class="details-row"><span class="details-label">{{email_label}}</span> <a href="mailto:{{customer.email}}"><strong>{{customer.email}}</strong></a></div>'
                . '{{#unless links.set_password}}<div class="details-row"><span class="details-label">{{password_label}}</span> <strong>{{password_placeholder}}</strong></div>{{/unless}}'
                . '</div>'
                . '{{#if links.set_password}}<p>{{lang \'set_password_message\'}}</p><a href="{{links.set_password}}" class="sign-in-btn">{{lang \'set_password\'}}</a>'
                . '{{else}}<a href="{{links.account}}" class="sign-in-btn">{{sign_in}}</a>{{/if}}'
                . '<p style="margin-top:24px">{{help}}</p>',
                [
                    'set_password_message' => 'An account has been created for you. Please set your password to sign in:',
                    'set_password'         => 'Set your password',
                ],
            ],

            'guest_account_created' => [
                '<h1>{{subject}}</h1>'
                . '<p>{{hello}}</p>'
                . '<p>{{message}}</p>'
                . '<p>{{instruction}}</p>'
                . '<p><a href="{{links.register}}">{{links.register}}</a></p>'
                . '<p>{{help}}</p>',
                [],
            ],

            'sign_in_link_request' => [
                '<h1>{{title}}</h1>'
                . '<p>{{message}}</p>'
                . '<p>{{alternatively}}</p>'
                . '<p style="word-break:break-all"><a href="{{links.sign_in}}">{{links.sign_in}}</a></p>'
                . '<p>{{login_request}}</p>'
                . '<p>{{secure}}</p>',
                [],
            ],

            'gift_certificate_recipient' => [
                '<h1>{{title}}</h1>'
                . '<p>{{hello}}</p>'
                . '<p>{{lang \'sent_message\'}}</p>'
                . '{{#if gift.expiry_date}}<p>{{lang \'expiry_message\'}}</p>{{/if}}'
                . '<p>{{attached}} {{warning}}</p>',
                [
                    'sent_message'   => '{{gift.from_name}} ({{gift.from_email}}) has sent you a {{gift.amount}} gift certificate for <a href="{{store.url}}">{{store.name}}</a>.',
                    'expiry_message' => 'You have until {{gift.expiry_date}} to use this gift certificate before it expires. For instructions on how to redeem your gift certificate please <a href="{{links.redeem}}">click here</a>.',
                ],
            ],

            'order_notification' => [
                '<h1>{{title}}</h1>'
                . '<p>{{hello}}</p>'
                . '<p>{{message}}</p>'
                . '<div class="reply-box">'
                . '<p><strong>{{store.name}}</strong></p>'
                . '{{#if message_data.subject}}<p><strong>{{message_data.subject}}</strong></p>{{/if}}'
                . '<p style="white-space:pre-line">{{message_data.body}}</p>'
                . '<a href="{{links.account}}" class="reply-btn">{{reply}}</a>'
                . '</div>',
                [],
            ],

            'product_review_request' => [
                '<h1>{{title}}</h1>'
                . '<p>{{hello}}</p>'
                . '<p>{{message}}</p>'
                . '<p>{{instruction}}</p>'
                . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border-top:1px solid #e5e5e5;margin:12px 0 16px">'
                . '{{#each order.items}}<tr>'
                . '<td width="76" style="padding:16px 0;border-bottom:1px solid #e5e5e5;vertical-align:top">' . self::itemImage() . '</td>'
                . '<td style="padding:16px 0;border-bottom:1px solid #e5e5e5;vertical-align:top">'
                . '<p style="margin:0 0 4px;font-size:14px;font-weight:600;color:#333">{{this.name}}</p>'
                . '{{#if this.sku}}<p style="margin:0;font-size:12px;color:#666">{{this.sku}}</p>{{/if}}</td>'
                . '<td align="right" style="padding:16px 0;border-bottom:1px solid #e5e5e5;vertical-align:middle">'
                . '<a href="{{this.review_url}}" class="review-btn">{{lang \'review_text\'}}</a></td>'
                . '</tr>{{/each}}</table>'
                . '<p>{{thanks}}</p>',
                [],
            ],

            'guest_order_access' => [
                '<h1>{{title}}</h1>'
                . '<p>{{intro}}</p>'
                . '<div class="info-box">'
                . '<p><strong>{{order_summary_title}}</strong></p>'
                . '<p>{{order_id_label}} <strong>#{{order.id}}</strong></p>'
                . '<p>{{order_total_label}} <strong>{{order.total}}</strong></p>'
                . '</div>'
                . '<div style="text-align:center"><a href="{{links.view_order}}" class="view-btn">{{view_order_button}}</a></div>'
                . '<p class="security">{{security_notice}}</p>',
                [],
            ],

            'return_requested' => [
                '<h1>{{title}}</h1>'
                . '<p>{{message}}</p>'
                . self::returnDetails()
                . '<a href="{{links.return_status}}" class="check-btn">{{check_status}}</a>'
                . '{{#if return.items}}<h2>{{products_title}}</h2>' . self::itemsLoop('return.items', false) . '{{/if}}',
                [
                    'brand_label' => 'Brand:',
                ],
            ],

            'return_status_change' => [
                '<h1>{{title}}</h1>'
                . '<p>{{hello}}</p>'
                . '<p>{{message}}</p>'
                . self::returnDetails()
                . '<a href="{{links.return_status}}" class="check-btn">{{check_status}}</a>'
                . '{{#if return.items}}<h2>{{products_title}}</h2>' . self::itemsLoop('return.items', false) . '{{/if}}',
                [
                    'brand_label' => 'Brand:',
                ],
            ],
        ];
    }

    // ------------------------------------------------------------ helpers

    /**
     * Keeps the original template's <head>, logo header, top divider, bottom
     * divider and footer, and swaps only the content block.
     */
    private static function wrap(string $legacyHtml, string $content): string
    {
        $start = strpos($legacyHtml, '<div class="content">');
        $end = strrpos($legacyHtml, '<div class="divider">');

        $before = substr($legacyHtml, 0, $start);
        $after = substr($legacyHtml, $end);

        // Store logo when the store has one, otherwise the store name (as before).
        $before = preg_replace(
            '#<span>\{\{store\}\}</span>#',
            '{{#if store.logo_url}}<img src="{{store.logo_url}}" alt="{{store.name}}" style="max-height:64px;max-width:240px;border:0">{{else}}<span>{{store}}</span>{{/if}}',
            $before,
            1
        );

        // Footer shows the domain (without https://) as link text.
        $after = str_replace('>{{store_url}}</a>', '>{{store.domain}}</a>', $after);

        return TransactionalEmailService::LIVE_MARKER . "\n"
            . $before
            . '<div class="content">' . $content . '</div>'
            . $after;
    }

    private static function phrases(array $map): array
    {
        $out = [];

        foreach ($map as $name => $value) {
            $out[] = ['phrase_name' => $name, 'phrase_value' => $value, 'is_system' => true];
        }

        return $out;
    }

    /**
     * 60x60 product image, or the original grey "Image Coming Soon" box.
     * Inline styles so it looks the same in every template and email client.
     */
    private static function itemImage(): string
    {
        return '{{#if this.image_url}}<img src="{{this.image_url}}" alt="{{this.name}}" width="60" height="60" style="display:block;width:60px;height:60px;object-fit:cover;border:1px solid #ddd;border-radius:4px">'
            . '{{else}}<div style="width:60px;height:60px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;text-align:center;font-size:9px;line-height:1.25;color:#999;padding-top:13px;box-sizing:border-box">Image<br>Coming<br>Soon</div>{{/if}}';
    }

    /**
     * Product rows in the original layout (image | name, SKU, brand | Qty | total),
     * with inline styles. Item fields use "this." so they can never be
     * confused with a phrase of the same name (e.g. "quantity").
     */
    private static function itemsLoop(string $path, bool $withPrices): string
    {
        $cell = 'padding:16px 0;border-bottom:1px solid #e5e5e5;vertical-align:top;';

        return '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border-top:1px solid #e5e5e5;margin:12px 0 16px">'
            . '{{#each ' . $path . '}}<tr>'
            . '<td width="76" style="' . $cell . '">' . self::itemImage() . '</td>'
            . '<td style="' . $cell . 'font-size:13px;color:#666">'
            . '<p style="margin:0 0 4px;font-size:14px;font-weight:600;color:#333">{{this.name}}</p>'
            . '{{#if this.sku}}<p style="margin:0;font-size:12px;color:#666">{{this.sku}}</p>{{/if}}'
            . '{{#if this.brand}}<p style="margin:0;font-size:12px;color:#666">{{lang \'brand_label\'}} {{this.brand}}</p>{{/if}}'
            . ($withPrices ? '{{#if this.price}}<p style="margin:4px 0 0;font-size:12px;color:#666">{{this.price}}</p>{{/if}}' : '')
            . '</td>'
            . '<td width="70" style="' . $cell . 'font-size:13px;color:#555;white-space:nowrap">{{lang \'quantity\'}} {{this.quantity}}</td>'
            . ($withPrices ? '<td width="80" align="right" style="' . $cell . 'font-size:14px;font-weight:600;color:#333;white-space:nowrap">{{this.total}}</td>' : '')
            . '</tr>{{/each}}'
            . '</table>';
    }

    private static function totalRow(string $label, string $value, bool $strong = false): string
    {
        $labelStyle = $strong ? 'color:#333;font-weight:600' : 'color:#777';

        return '<tr><td align="right" style="padding:2px 12px 2px 0;font-size:13px;' . $labelStyle . '">' . $label . '</td>'
            . '<td align="right" width="170" style="padding:2px 0;font-size:13px;font-weight:600;color:#333">' . $value . '</td></tr>';
    }

    /** Right-aligned totals, payment and shipping method. */
    private static function orderTotals(): string
    {
        return '<table width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 16px">'
            . self::totalRow("{{lang 'subtotal_label'}}", '{{order.subtotal}}')
            . '{{#if order.shipping_value}}' . self::totalRow("{{lang 'shipping_label'}}", '{{order.shipping}}') . '{{/if}}'
            . '{{#if order.discount}}' . self::totalRow("{{lang 'discount_label'}}", '-{{order.discount}}') . '{{/if}}'
            . '{{#if order.manual_discount}}' . self::totalRow("{{lang 'discount_label'}}", '-{{order.manual_discount}}') . '{{/if}}'
            . self::totalRow("{{lang 'total_label'}}", '{{order.total}}', true)
            . '{{#if order.payment_method}}' . self::totalRow('{{payment_method}}', '{{order.payment_method}}') . '{{/if}}'
            . '{{#if order.shipping_method}}' . self::totalRow('{{shipping_method}}', '{{order.shipping_method}}') . '{{/if}}'
            . '</table>';
    }

    private static function address(string $a): string
    {
        return '<div class="address-box">'
            . '<p><strong>{{' . $a . '.full_name}}</strong></p>'
            . '{{#if ' . $a . '.company}}<p>{{' . $a . '.company}}</p>{{/if}}'
            . '<p>{{' . $a . '.address_line_1}}</p>'
            . '{{#if ' . $a . '.address_line_2}}<p>{{' . $a . '.address_line_2}}</p>{{/if}}'
            . '<p>{{' . $a . '.city_line}}</p>'
            . '<p>{{' . $a . '.country}}</p>'
            . '{{#if ' . $a . '.phone}}<p>{{' . $a . '.phone}}</p>{{/if}}'
            . '</div>';
    }

    private static function detailsRow(string $label, string $value): string
    {
        return '<div class="details-row"><span class="details-label">' . $label . '</span> <strong>' . $value . '</strong></div>';
    }

    private static function returnDetails(): string
    {
        return '<h2>{{details_title}}</h2>'
            . '<div class="details">'
            . self::detailsRow('{{return_reason}}', '{{return.reason}}')
            . self::detailsRow('{{return_action}}', '{{return.action}}')
            . '{{#if return.comments}}' . self::detailsRow('{{return_comments}}', '{{return.comments}}') . '{{/if}}'
            . '</div>';
    }
}
