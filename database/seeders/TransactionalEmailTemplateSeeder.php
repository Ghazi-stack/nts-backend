<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TransactionalEmailTemplate;
use App\Models\TransactionalEmailPhrase;
use App\Models\Store;

class TransactionalEmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $dbName = config('database.connections.tenant.database');

        $store = Store::on('central')
            ->where('tenant_database', $dbName)
            ->first();

        if (!$store) {
            $this->command->error("Store not found for database: {$dbName}");
            return;
        }

        // legacy_store_id use karo (ye current_store_id() return karta hai)
        $storeId = $store->legacy_store_id ?? $store->id;

        $this->command->info("Seeding for Store ID: {$storeId} | DB: {$dbName}");

        $templates = [
            [
                'key'         => 'account_settings_edited',
                'name'        => 'Account Settings Edited',
                'description' => 'Sent when a customer or store admin edits account settings',
                'subject'     => 'Your {{store}} account details have changed',
                'phrases'     => [
                    ['phrase_name' => 'subject',     'phrase_value' => 'Your {{store}} account details have changed', 'is_system' => true],
                    ['phrase_name' => 'hello',       'phrase_value' => 'Hello {{name}},', 'is_system' => true],
                    ['phrase_name' => 'message',     'phrase_value' => 'We wanted to let you know that the following details have been changed on your {{store}} account:', 'is_system' => true],
                    ['phrase_name' => 'warning',     'phrase_value' => 'If you made the changes yourself, please disregard this email. If not, please contact the store immediately.', 'is_system' => true],
                    ['phrase_name' => 'thanks',      'phrase_value' => 'Thanks,', 'is_system' => true],
                    ['phrase_name' => 'security',    'phrase_value' => 'The Security Team', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],
            [
                'key'         => 'password_reset',
                'name'        => 'Password Reset',
                'description' => 'Sent when a customer account password is reset on the customer details page or by the customer',
                'subject'     => 'Password Reset Request',
            ],
            [
                'key'         => 'order_status_update',
                'name'        => 'Order Status Update',
                'description' => 'Sent when the status of an order is changed',
                'subject'     => 'Your order status has been updated',
            ],
            [
                'key'         => 'order_email',
                'name'        => 'Order Email',
                'description' => 'Sent when an order is created',
                'subject'     => 'Thank you for your order',
            ],
            [
                'key'         => 'guest_account_created',
                'name'        => 'Guest Account Created',
                'description' => 'Sent when a customer or store admin creates their account as a guest',
                'subject'     => 'Your guest account has been created',
            ],
            [
                'key'         => 'gift_certificate_recipient',
                'name'        => 'Gift Certificate Recipient',
                'description' => 'Sent to the recipient of a gift certificate',
                'subject'     => 'You have received a gift certificate',
            ],
            [
                'key'         => 'sign_in_link_request',
                'name'        => 'Sign-in Link Request',
                'description' => 'Sent when an existing customer requests to use passwordless login while checking out',
                'subject'     => 'Your sign-in link',
            ],
            [
                'key'         => 'order_notification',
                'name'        => 'Order Notification',
                'description' => 'The customer receives this when the store admin sends them an order message',
                'subject'     => 'New message about your order',
            ],
            [
                'key'         => 'product_review_request',
                'name'        => 'Product Review Request',
                'description' => 'Sent to customer after purchasing a product to request a review',
                'subject'     => 'How was your experience?',
            ],
            [
                'key'         => 'return_requested',
                'name'        => 'Return Requested',
                'description' => 'Sent to customer after return has been requested',
                'subject'     => 'Your return request has been received',
            ],
            [
                'key'         => 'account_created',
                'name'        => 'Account Created',
                'description' => 'Sent when a customer or store admin creates their account',
                'subject'     => 'Welcome! Your account has been created',
            ],
            [
                'key'         => 'return_status_change',
                'name'        => 'Return Status Change',
                'description' => 'Sent to customer after return\'s status has changed',
                'subject'     => 'Your return status has been updated',
            ],
            [
                'key'         => 'order_ready_for_pickup',
                'name'        => 'Order ready for pickup',
                'description' => 'Order ready for pickup',
                'subject'     => 'Your order is ready for pickup',
            ],
            [
                'key'         => 'guest_order_access',
                'name'        => 'Guest Order Access',
                'description' => 'Sent when a guest customer requests access to view their order',
                'subject'     => 'Access your order',
            ],
        ];

        foreach ($templates as $item) {
            $template = TransactionalEmailTemplate::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'key'      => $item['key'],
                ],
                [
                    'name'        => $item['name'],
                    'description' => $item['description'],
                    'enabled'     => true,
                    'subject'     => $item['subject'] ?? $item['name'],
                    'body_html'   => '<html><body><p>Hello {{name}},</p><p>{{message}}</p><p>Thanks,<br>{{store}}</p></body></html>',
                ]
            );

            if (!empty($item['phrases'])) {
                foreach ($item['phrases'] as $phrase) {
                    TransactionalEmailPhrase::updateOrCreate(
                        [
                            'template_id' => $template->id,
                            'phrase_name' => $phrase['phrase_name'],
                        ],
                        [
                            'phrase_value' => $phrase['phrase_value'],
                            'is_system'    => $phrase['is_system'] ?? false,
                        ]
                    );
                }
            }
        }

        $this->command->info('Transactional emails seeded successfully for store ' . $storeId);
    }
}
