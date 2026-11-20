<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TransactionalEmailTemplate;

class TransactionalEmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $storeId = 1; // Yahan apna store_id daal dena ya loop se multiple stores

        $templates = [
            [
                'key' => 'account_settings_edited',
                'name' => 'Account Settings Edited',
                'description' => 'Sent when a customer or store admin edits account settings',
            ],
            [
                'key' => 'password_reset',
                'name' => 'Password Reset',
                'description' => 'Sent when a customer account password is reset on the customer details page or by the customer',
            ],
            [
                'key' => 'order_status_update',
                'name' => 'Order Status Update',
                'description' => 'Sent when the status of an order is changed',
            ],
            [
                'key' => 'order_email',
                'name' => 'Order Email',
                'description' => 'Sent when an order is created',
            ],
            [
                'key' => 'guest_account_created',
                'name' => 'Guest Account Created',
                'description' => 'Sent when a customer or store admin creates their account as a guest',
            ],
            [
                'key' => 'gift_certificate_recipient',
                'name' => 'Gift Certificate Recipient',
                'description' => 'Sent to the recipient of a gift certificate',
            ],
            [
                'key' => 'sign_in_link_request',
                'name' => 'Sign-in Link Request',
                'description' => 'Sent when an existing customer requests to use passwordless login while checking out',
            ],
            [
                'key' => 'order_notification',
                'name' => 'Order Notification',
                'description' => 'The customer receives this when the store admin sends them an order message',
            ],
            [
                'key' => 'product_review_request',
                'name' => 'Product Review Request',
                'description' => 'Sent to customer after purchasing a product to request a review',
            ],
            [
                'key' => 'return_requested',
                'name' => 'Return Requested',
                'description' => 'Sent to customer after return has been requested',
            ],
            [
                'key' => 'account_created',
                'name' => 'Account Created',
                'description' => 'Sent when a customer or store admin creates their account',
            ],
            [
                'key' => 'return_status_change',
                'name' => 'Return Status Change',
                'description' => 'Sent to customer after return\'s status has changed',
            ],
            [
                'key' => 'order_ready_for_pickup',
                'name' => 'Order ready for pickup',
                'description' => 'Order ready for pickup',
            ],
            [
                'key' => 'guest_order_access',
                'name' => 'Guest Order Access',
                'description' => 'Sent when a guest customer requests access to view their order',
            ],
        ];

        foreach ($templates as $tpl) {
            TransactionalEmailTemplate::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'key'      => $tpl['key'],
                ],
                [
                    'name'        => $tpl['name'],
                    'description' => $tpl['description'],
                    'enabled'     => true,
                    'subject'     => $tpl['name'],
                    'body_html'   => '<html><body><p>Hello {{name}},</p><p>This is the ' . $tpl['name'] . ' email.</p></body></html>',
                ]
            );
        }
    }
}
