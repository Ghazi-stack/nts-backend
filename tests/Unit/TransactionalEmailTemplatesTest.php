<?php

namespace Tests\Unit;

use App\Models\TransactionalEmailPhrase;
use App\Models\TransactionalEmailTemplate;
use App\Services\TransactionalEmailContext;
use App\Services\TransactionalEmailService;
use Database\Seeders\TransactionalEmailLiveTemplates;
use Database\Seeders\TransactionalEmailTemplateSeeder;
use Tests\TestCase;

/**
 * Renders every v2 default template with realistic data. No database:
 * templates and phrases are built in memory.
 */
class TransactionalEmailTemplatesTest extends TestCase
{
    private function template(string $key, ?string $html = null): TransactionalEmailTemplate
    {
        $def = TransactionalEmailLiveTemplates::all()[$key];

        $tpl = new TransactionalEmailTemplate([
            'key'       => $key,
            'subject'   => $def['subject'],
            'body_html' => $html ?? $def['body_html'],
        ]);

        $tpl->setRelation('phrases', collect($def['phrases'])->map(
            fn($p) => new TransactionalEmailPhrase($p)
        ));

        return $tpl;
    }

    private function store(): object
    {
        return (object) ['name' => 'New Town Spares', 'base_url' => 'https://nts.example.com/'];
    }

    private function orderModelLike(): array
    {
        return [
            'order_number'  => '5001',
            'status'        => 'Awaiting Fulfillment',
            'created_at'    => '2026-11-27 10:00:00',
            'shipping_cost' => 7.5,
            'discountAmount' => 2,
            'manual_discount' => 0,
            'total_amount'  => 55.48,
            'comments'      => 'Ring the bell',
            'billingInformation' => [
                'first_name' => 'Ali', 'last_name' => '<Khan>', 'email' => 'ali@example.com',
                'address_line_1' => '1 Road', 'city' => 'Lahore', 'state' => 'PB', 'zip' => '54000',
                'country' => 'Pakistan', 'payment_method' => 'cash_on_delivery',
                'shipping_data' => json_encode(['display_name' => 'Express']),
            ],
            'items' => [
                ['price' => 25, 'quantity' => 2, 'product' => [
                    'name' => 'Brake Pad', 'sku' => 'BP-1', 'brand' => ['name' => 'Bosch'],
                    'images' => [['is_primary' => 1, 'path' => 'products/bp.jpg']],
                ]],
            ],
        ];
    }

    private function render(string $key, array $data): array
    {
        return app(TransactionalEmailService::class)->render(
            $this->template($key),
            TransactionalEmailContext::withLegacyAliases($data)
        );
    }

    private function assertClean(array $out): void
    {
        $this->assertStringNotContainsString('{{', $out['html'], 'unrendered tag in HTML');
        $this->assertStringNotContainsString('{{', $out['subject'], 'unrendered tag in subject');
        $this->assertStringNotContainsString('Demo Product', $out['html']);
        $this->assertStringNotContainsString('John Doe', $out['html']);
        $this->assertStringNotContainsString('email-engine:v2', $out['html'], 'marker must not appear in the email');
    }

    public function test_every_v2_template_is_live_and_renders_sample_data_cleanly(): void
    {
        foreach (TransactionalEmailContext::SENDING_KEYS as $key) {
            $tpl = TransactionalEmailLiveTemplates::all()[$key] ?? null;
            $this->assertNotNull($tpl, "missing v2 template for $key");
            $this->assertTrue(TransactionalEmailService::isLiveTemplate($tpl['body_html']), "$key lacks marker");

            $out = $this->render($key, TransactionalEmailContext::sample($key, $this->store()));

            $this->assertClean($out);
            $this->assertNotSame('', $out['subject'], "$key has empty subject");
            $this->assertStringContainsString('New Town Spares', $out['html'], "$key lacks store name");
        }
    }

    public function test_order_email_prints_real_order_data_and_escapes_customer_input(): void
    {
        $data = TransactionalEmailContext::base($this->store()) + [
            'order'    => TransactionalEmailContext::order($this->orderModelLike()),
            'customer' => TransactionalEmailContext::customer('Ali', 'ali@example.com', 'Ali <Khan>'),
            'links'    => ['order_status' => 'https://nts.example.com'],
        ];

        $out = $this->render('order_email', $data);

        $this->assertClean($out);
        $this->assertSame('Your New Town Spares Order Confirmation (#5001)', $out['subject']);
        $this->assertStringContainsString('Brake Pad', $out['html']);
        $this->assertStringContainsString('BP-1', $out['html']);
        $this->assertStringContainsString('Bosch', $out['html']);
        $this->assertStringContainsString('https://aws-backend-assets.s3.us-east-2.amazonaws.com/products/bp.jpg', $out['html']);
        $this->assertStringContainsString('$25.00', $out['html']);   // unit price
        $this->assertStringContainsString('Qty: 2', $out['html']);
        $this->assertStringContainsString('$50.00', $out['html']);   // line total + subtotal
        $this->assertStringContainsString('$7.50', $out['html']);    // shipping
        $this->assertStringContainsString('-$2.00', $out['html']);   // discount
        $this->assertStringNotContainsString('Manual Discount', $out['html']); // zero -> hidden
        $this->assertStringContainsString('$55.48', $out['html']);   // total
        $this->assertStringContainsString('Express', $out['html']);
        $this->assertStringContainsString('Cash on delivery', $out['html']);
        $this->assertStringContainsString('Ali &lt;Khan&gt;', $out['html']); // escaped
        $this->assertStringNotContainsString('<Khan>', $out['html']);
        $this->assertStringContainsString('Ring the bell', $out['html']);
    }

    public function test_status_update_shows_status_and_awaiting_pickup_uses_pickup_template(): void
    {
        $data = TransactionalEmailContext::base($this->store()) + [
            'order'    => TransactionalEmailContext::order($this->orderModelLike()),
            'status'   => TransactionalEmailContext::status('Shipped'),
            'shipment' => TransactionalEmailContext::shipment(['shipping_method' => 'Ground', 'shipped_by' => 'FedEx']),
            'customer' => TransactionalEmailContext::customer('Ali', 'ali@example.com'),
            'links'    => ['order_status' => 'https://nts.example.com'],
        ];

        $out = $this->render('order_status_update', $data);

        $this->assertClean($out);
        $this->assertSame('Your New Town Spares Order Has Been Updated (#5001)', $out['subject']);
        $this->assertStringContainsString('Great news! Your order has been shipped.', $out['html']);
        $this->assertStringContainsString('Ground', $out['html']);        // shipment method
        $this->assertStringContainsString('Brake Pad', $out['html']);     // products in this order

        $this->assertSame('order_ready_for_pickup', \App\Mail\OrderStatusUpdatedMail::templateKeyFor('Awaiting Pickup'));
        $this->assertSame('order_status_update', \App\Mail\OrderStatusUpdatedMail::templateKeyFor('Shipped'));
    }

    public function test_account_created_subject_and_button_depend_on_set_password_link(): void
    {
        $base = TransactionalEmailContext::base($this->store()) + [
            'customer' => TransactionalEmailContext::customer(null, 'a@b.com', 'Sara Ahmed'),
        ];

        $welcome = $this->render('account_created', $base + ['links' => ['set_password' => 'https://x/reset?t=1', 'account' => 'https://x']]);
        $this->assertSame('Thanks for registering at New Town Spares', $welcome['subject']);
        $this->assertStringContainsString('https://x/reset?t=1', $welcome['html']);
        $this->assertStringContainsString('Hello Sara,', $welcome['html']);

        $registered = $this->render('account_created', $base + ['links' => ['set_password' => '', 'account' => 'https://x']]);
        $this->assertSame('Thanks for registering at New Town Spares', $registered['subject']);
        $this->assertStringContainsString('Sign in to account', $registered['html']);
        $this->assertStringNotContainsString('Set your password', $registered['html']);
        $this->assertStringContainsString('Set your password', $welcome['html']);
    }

    public function test_preview_overrides_from_old_dashboard_do_not_wipe_store_data(): void
    {
        $sample = TransactionalEmailContext::sample('order_email', $this->store());

        // Older dashboard builds post data.store as a plain string (or empty).
        $merged = TransactionalEmailContext::mergeOverrides($sample, [
            'store' => '', 'store_url' => null, 'order' => ['id' => '777'], 'extra' => 'x',
        ]);

        $out = $this->render('order_email', $merged);

        $this->assertSame('Your New Town Spares Order Confirmation (#777)', $out['subject']);
        $this->assertStringContainsString('New Town Spares', $out['html']);
        $this->assertSame('x', $merged['extra']);
    }

    public function test_items_without_image_show_placeholder(): void
    {
        $out = $this->render('order_email', TransactionalEmailContext::sample('order_email', $this->store()));

        $this->assertStringContainsString('Image<br>Coming<br>Soon', $out['html']);
    }

    public function test_old_demo_templates_are_not_live(): void
    {
        foreach ((new TransactionalEmailTemplateSeeder())->legacyTemplates() as $item) {
            $this->assertFalse(
                TransactionalEmailService::isLiveTemplate($item['body_html']),
                "legacy {$item['key']} must not be used for real emails"
            );
        }
    }

    public function test_shipping_method_shows_the_storefront_label(): void
    {
        // Checkout saves the storefront label as a plain string (array cast decodes it to a string).
        $this->assertSame('FedEx (FedEx Ground®)', TransactionalEmailContext::shippingMethodLabel(
            ['shipping_data' => 'FedEx (FedEx Ground®)', 'shipping_method' => 'FEDEX_GROUND']
        ));
        // ...or JSON text / an array with display_name.
        $this->assertSame('Flat Rate', TransactionalEmailContext::shippingMethodLabel(
            ['shipping_data' => '{"display_name":"Flat Rate"}', 'shipping_method' => 'flat_rate']
        ));
        $this->assertSame('Express', TransactionalEmailContext::shippingMethodLabel(
            ['shipping_data' => ['display_name' => 'Express']]
        ));
        // Only a FedEx service code saved: readable FedEx name, never "FEDEX_GROUND".
        $this->assertSame('FedEx (FedEx Ground)', TransactionalEmailContext::shippingMethodLabel(
            ['shipping_data' => null, 'shipping_method' => 'FEDEX_GROUND']
        ));
        $this->assertSame('Free shipping', TransactionalEmailContext::shippingMethodLabel(
            ['shipping_method' => 'free_shipping']
        ));
    }

    public function test_amounts_use_the_order_currency(): void
    {
        $order = $this->orderModelLike() + ['currency_code' => 'GBP', 'currency_symbol' => '£'];

        $ctx = TransactionalEmailContext::order($order);

        $this->assertSame('£55.48', $ctx['total']);
        $this->assertSame('£25.00', $ctx['items'][0]['price']);
        $this->assertSame(['code' => 'GBP', 'symbol' => '£'], $ctx['currency']);

        // Store currency settings: symbol on the right, comma decimals.
        $this->assertSame('1.234,50 €', TransactionalEmailContext::money(1234.5, [
            'symbol' => ' €', 'location' => 'right', 'decimals' => 2, 'decimal_token' => ',', 'thousands_token' => '.',
        ]));
        // No currency on the order: same "$" format as before.
        $this->assertSame('$55.48', TransactionalEmailContext::order($this->orderModelLike())['total']);
    }

    public function test_product_image_prefers_the_full_image_url(): void
    {
        $ctx = TransactionalEmailContext::order([
            'items' => [['price' => 1, 'quantity' => 1, 'product' => [
                'name' => 'X',
                'images' => [['is_primary' => 1, 'path' => 'products/x.jpg', 'url' => 'https://cdn.example.com/x.jpg']],
            ]]],
        ]);

        $this->assertSame('https://cdn.example.com/x.jpg', $ctx['items'][0]['image_url']);
    }

    public function test_v3_templates_keep_the_original_design_and_phrases(): void
    {
        $legacy = collect((new TransactionalEmailTemplateSeeder())->legacyTemplates())->keyBy('key');

        foreach (TransactionalEmailLiveTemplates::all() as $key => $tpl) {
            $old = $legacy[$key];

            // Same <head> (all CSS) as the original template.
            preg_match('#<head>.*?</head>#s', $old['body_html'], $oldHead);
            $this->assertStringContainsString($oldHead[0], $tpl['body_html'], "$key lost its original styles");

            // Every original phrase is still there with the same wording.
            $phrases = collect($tpl['phrases'])->pluck('phrase_value', 'phrase_name');
            foreach ($old['phrases'] as $p) {
                $this->assertSame($p['phrase_value'], $phrases[$p['phrase_name']] ?? null, "$key phrase {$p['phrase_name']} changed");
            }

            // No demo data left.
            foreach (['Demo Product', 'Demo product name', 'John Doe', '$250.100', 'Stehr Inc', 'Name of Product'] as $demo) {
                $this->assertStringNotContainsString($demo, $tpl['body_html'], "$key still has demo data: $demo");
            }
        }
    }

    public function test_every_template_renders_sample_data_cleanly(): void
    {
        foreach (TransactionalEmailContext::ALL_KEYS as $key) {
            $out = $this->render($key, TransactionalEmailContext::sample($key, $this->store()));

            $this->assertClean($out);
            $this->assertStringContainsString('New Town Spares', $out['html'], "$key lacks store name");
            $this->assertStringNotContainsString('Qty: Qty:', $out['html'], "$key repeats the Qty label");
        }
    }

    public function test_reset_to_default_list_has_live_templates_for_all_keys(): void
    {
        $seeder = new TransactionalEmailTemplateSeeder();
        $method = (new \ReflectionClass($seeder))->getMethod('getTemplates');
        $method->setAccessible(true);
        $all = collect($method->invoke($seeder))->keyBy('key');

        $this->assertCount(14, $all);

        foreach ($all as $key => $item) {
            $this->assertTrue(
                TransactionalEmailService::isLiveTemplate($item['body_html']),
                "default for $key should be live"
            );
            $this->assertNotEmpty($item['name']);
            $this->assertNotEmpty($item['description']);
        }
    }

    public function test_legacy_phrases_edited_by_owner_still_render(): void
    {
        $tpl = $this->template('order_status_update');
        // Owner had customised the old "hello" phrase using legacy {{name}}.
        $tpl->phrases->firstWhere('phrase_name', 'hello')->phrase_value = 'Hey {{name}}! Order {{id}} is {{status}}.';

        $out = app(TransactionalEmailService::class)->render($tpl, TransactionalEmailContext::withLegacyAliases(
            TransactionalEmailContext::base($this->store()) + [
                'order'    => TransactionalEmailContext::order($this->orderModelLike()),
                'status'   => TransactionalEmailContext::status('Completed'),
                'customer' => TransactionalEmailContext::customer('Ali', 'ali@example.com'),
                'links'    => ['order_status' => 'https://nts.example.com'],
            ]
        ));

        $this->assertStringContainsString('Hey Ali! Order 5001 is Completed.', $out['html']);
    }
}
