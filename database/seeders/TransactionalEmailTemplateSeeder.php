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

        $storeId = $store->legacy_store_id ?? $store->id;
        $this->command->info("Seeding for Store ID: {$storeId} | DB: {$dbName}");

        $templates = [
            // Account Settings Edited
            [
                'key' => 'account_settings_edited',
                'name' => 'Account Settings Edited',
                'description' => 'Sent when a customer or store admin edits account settings',
                'subject' => 'Your {{store}} account details have changed',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Your {{store}} account details have changed', 'is_system' => true],
                    ['phrase_name' => 'hello', 'phrase_value' => 'Hello {{name}},', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => 'We wanted to let you know that the following details have been changed on your <a href="{{store_url}}">{{store}}</a> account:', 'is_system' => true],
                    ['phrase_name' => 'warning', 'phrase_value' => 'If you made the changes yourself, please disregard this email. If not, please contact the store immediately through their website: <a href="{{store_url}}">{{store_url}}</a>.', 'is_system' => true],
                    ['phrase_name' => 'thanks', 'phrase_value' => 'Thanks,', 'is_system' => true],
                    ['phrase_name' => 'security', 'phrase_value' => 'The Security Team', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],

            // Password Reset
            [
                'key' => 'password_reset',
                'name' => 'Password Reset',
                'description' => 'Sent when a customer account password is reset',
                'subject' => 'Password change request for {{store}}',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Password change request for {{store}}', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'Change your password for {{store}}', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => 'To change your customer account password at {{store}} please click this link or copy and paste it into your browser:', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],

            // Order Status Update
            [
                'key' => 'order_status_update',
                'name' => 'Order Status Update',
                'description' => 'Sent when the status of an order is changed',
                'subject' => 'Your {{store}} Order Has Been Updated (#{{id}})',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Your {{store}} Order Has Been Updated (#{{id}})', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'Order status changed', 'is_system' => true],
                    ['phrase_name' => 'hello', 'phrase_value' => 'Hi {{name}},', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => 'The status of your order #{{id}} has changed to "{{status}}".', 'is_system' => true],
                    ['phrase_name' => 'details_title', 'phrase_value' => 'Order details', 'is_system' => true],
                    ['phrase_name' => 'order_total', 'phrase_value' => 'Order total:', 'is_system' => true],
                    ['phrase_name' => 'date_placed', 'phrase_value' => 'Date placed:', 'is_system' => true],
                    ['phrase_name' => 'payment_method', 'phrase_value' => 'Payment method:', 'is_system' => true],
                    ['phrase_name' => 'total_refund', 'phrase_value' => 'Total refunded:', 'is_system' => true],
                    ['phrase_name' => 'products_shipped', 'phrase_value' => 'Products shipped', 'is_system' => true],
                    ['phrase_name' => 'products_to_be_shipped', 'phrase_value' => 'Products to be shipped', 'is_system' => true],
                    ['phrase_name' => 'tracking_title', 'phrase_value' => 'Tracking Information', 'is_system' => true],
                    ['phrase_name' => 'downloadable_items_title', 'phrase_value' => 'Downloadable Items', 'is_system' => true],
                    ['phrase_name' => 'quantity', 'phrase_value' => 'Qty:', 'is_system' => true],
                    ['phrase_name' => 'quantity_on_hand', 'phrase_value' => '{{purchase_quantity_on_hand}} ready to ship', 'is_system' => true],
                    ['phrase_name' => 'quantity_on_backorder', 'phrase_value' => '{{purchase_quantity_on_backorder}} will be backordered', 'is_system' => true],
                    ['phrase_name' => 'download', 'phrase_value' => 'Download file', 'is_system' => true],
                    ['phrase_name' => 'tracking_label', 'phrase_value' => 'Tracking Link', 'is_system' => true],
                    ['phrase_name' => 'no_tracking_numbers', 'phrase_value' => 'No tracking numbers are assigned to your order yet', 'is_system' => true],
                    ['phrase_name' => 'check_status', 'phrase_value' => 'Check order status', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                    ['phrase_name' => 'brand', 'phrase_value' => 'Brand:', 'is_system' => true],
                    ['phrase_name' => 'pickup_method', 'phrase_value' => 'Pick up method:', 'is_system' => true],
                    ['phrase_name' => 'ready_for_pickup', 'phrase_value' => 'Ready for pick up', 'is_system' => true],
                    ['phrase_name' => 'picked_up', 'phrase_value' => 'Picked up', 'is_system' => true],
                ],
            ],

            // Order Email
            [
                'key' => 'order_email',
                'name' => 'Order Email',
                'description' => 'Sent when an order is created',
                'subject' => 'Your {{store}} Order Confirmation (#{{id}})',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Your {{store}} Order Confirmation (#{{id}})', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'Thanks for your order', 'is_system' => true],
                    ['phrase_name' => 'product_title', 'phrase_value' => 'Order #{{id}}', 'is_system' => true],
                    ['phrase_name' => 'download_title', 'phrase_value' => 'Items available for immediate download after completion of payment', 'is_system' => true],
                    ['phrase_name' => 'download_available_after_payment', 'phrase_value' => 'This product is available for preview only', 'is_system' => true],
                    ['phrase_name' => 'preorder', 'phrase_value' => 'Preorder', 'is_system' => true],
                    ['phrase_name' => 'download_files', 'phrase_value' => 'Download Files', 'is_system' => true],
                    ['phrase_name' => 'quantity', 'phrase_value' => 'Qty:', 'is_system' => true],
                    ['phrase_name' => 'shipping_discount', 'phrase_value' => '{{price}} off using {{code}} Coupon Code', 'is_system' => true],
                    ['phrase_name' => 'shipping_discount_without_code', 'phrase_value' => '{{price}} off from automatic promotion', 'is_system' => true],
                    ['phrase_name' => 'free_shipping_discount', 'phrase_value' => 'Free shipping using {{code}} Coupon Code', 'is_system' => true],
                    ['phrase_name' => 'free_shipping_discount_without_code', 'phrase_value' => 'Free shipping from automatic promotion', 'is_system' => true],
                    ['phrase_name' => 'shipping_method', 'phrase_value' => 'Shipping method:', 'is_system' => true],
                    ['phrase_name' => 'payment_method', 'phrase_value' => 'Payment method:', 'is_system' => true],
                    ['phrase_name' => 'payment_message', 'phrase_value' => 'Your order requires payment before it can be completed.', 'is_system' => true],
                    ['phrase_name' => 'order_total', 'phrase_value' => '{{amount}} Order Total', 'is_system' => true],
                    ['phrase_name' => 'before_label', 'phrase_value' => 'Before Domain Token', 'is_system' => true],
                    ['phrase_name' => 'after_label', 'phrase_value' => 'After Domain Token', 'is_system' => true],
                    ['phrase_name' => 'sepa', 'phrase_value' => 'SEPA Direct Debit Mandate', 'is_system' => true],
                    ['phrase_name' => 'ach_based_verification_url', 'phrase_value' => 'US Bank Instant or Manual Deposit Verification', 'is_system' => true],
                    ['phrase_name' => 'shipping_address_label', 'phrase_value' => 'Shipping address', 'is_system' => true],
                    ['phrase_name' => 'billing_address_label', 'phrase_value' => 'Billing Address', 'is_system' => true],
                    ['phrase_name' => 'shipping_immediate_download', 'phrase_value' => 'Immediate download after completion of payment.', 'is_system' => true],
                    ['phrase_name' => 'shipped_to_multiple_addresses', 'phrase_value' => 'Order will be shipped to multiple addresses', 'is_system' => true],
                    ['phrase_name' => 'billing_address_managed_by_amazon', 'phrase_value' => 'Managed by Amazon', 'is_system' => true],
                    ['phrase_name' => 'comment_label', 'phrase_value' => 'Order comments', 'is_system' => true],
                    ['phrase_name' => 'no_payment_taken_in_test_mode', 'phrase_value' => 'No payment has been taken for this order because the payment provider is in test mode', 'is_system' => true],
                    ['phrase_name' => 'check_label', 'phrase_value' => 'Check order status', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                    ['phrase_name' => 'brand', 'phrase_value' => 'Brand:', 'is_system' => true],
                    ['phrase_name' => 'shipped_title', 'phrase_value' => 'Ship to', 'is_system' => true],
                    ['phrase_name' => 'pickup_method', 'phrase_value' => 'Pickup method:', 'is_system' => true],
                    ['phrase_name' => 'mandate_reference_label', 'phrase_value' => 'Reference', 'is_system' => true],
                    ['phrase_name' => 'mandate_entity_label', 'phrase_value' => 'Entity', 'is_system' => true],
                    ['phrase_name' => 'ready_to_ship', 'phrase_value' => '{{count}} ready to ship', 'is_system' => true],
                    ['phrase_name' => 'backordered', 'phrase_value' => '{{count}} backordered', 'is_system' => true],
                ],
            ],

            // Guest Account Created
            [
                'key' => 'guest_account_created',
                'name' => 'Guest Account Created',
                'description' => 'Sent when a customer creates account as guest',
                'subject' => 'Welcome to {{store}}',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Welcome to {{store}}', 'is_system' => true],
                    ['phrase_name' => 'hello', 'phrase_value' => 'Hello {{name}},', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => "For your convenience, we've created you an account on {{store}} so you can check the status of your order and checkout quicker in the future.", 'is_system' => true],
                    ['phrase_name' => 'instruction', 'phrase_value' => 'To login you will need to follow the link below to nominate your password:', 'is_system' => true],
                    ['phrase_name' => 'help', 'phrase_value' => "If you have any questions regarding your account, click 'Reply' in your email client and we'll be only too happy to help.", 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],

            // Gift Certificate Recipient
            [
                'key' => 'gift_certificate_recipient',
                'name' => 'Gift Certificate Recipient',
                'description' => 'Sent to the recipient of a gift certificate',
                'subject' => '{{from}} has sent you a gift certificate for {{store}}',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => '{{from}} has sent you a gift certificate for {{store}}', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'You have received a Gift Certificate for {{store}}', 'is_system' => true],
                    ['phrase_name' => 'hello', 'phrase_value' => 'Dear {{name}},', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => '{{from_name}} ({{from_email}}) has sent you a {{amount}} gift certificate for <a href="{{path}}">{{store}}</a>.', 'is_system' => true],
                    ['phrase_name' => 'expiry_info', 'phrase_value' => 'You have until {{date}} to use this gift certificate before it expires.', 'is_system' => true],
                    ['phrase_name' => 'instruction', 'phrase_value' => 'For instructions on how to redeem your gift certificate please <a href="{{link}}">click here</a>.', 'is_system' => true],
                    ['phrase_name' => 'attached', 'phrase_value' => 'Your gift certificate is attached to this email.', 'is_system' => true],
                    ['phrase_name' => 'warning', 'phrase_value' => 'Please download or print a copy of your gift certificate for safe keeping as gift certificates are non-transferable.', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],

            // Sign-in Link Request
            [
                'key' => 'sign_in_link_request',
                'name' => 'Sign-in Link Request',
                'description' => 'Sent when customer requests passwordless login',
                'subject' => '{{store}} - Log in to your account',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => '{{store}} - Log in to your account', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'Please click the link below to sign in to your account with {{store}}', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => 'You have requested a sign-in link. Please <a href="{{link}}">click here to sign in</a> and continue.', 'is_system' => true],
                    ['phrase_name' => 'alternatively', 'phrase_value' => 'Alternatively, paste the following link in your browser:', 'is_system' => true],
                    ['phrase_name' => 'login_request', 'phrase_value' => 'This login is for {{store}} if you did not request this link, please ignore this email.', 'is_system' => true],
                    ['phrase_name' => 'secure', 'phrase_value' => 'Your account is still secure.', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],

            // Order Notification
            [
                'key' => 'order_notification',
                'name' => 'Order Notification',
                'description' => 'Sent when store admin sends order message',
                'subject' => 'New Reply to your Order Message',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'New Reply to your Order Message', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'New Reply to your Order Message', 'is_system' => true],
                    ['phrase_name' => 'hello', 'phrase_value' => 'Hello {{name}},', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => '{{store}} has sent you a message about your order.', 'is_system' => true],
                    ['phrase_name' => 'reply', 'phrase_value' => 'Reply', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],

            // Product Review Request
            [
                'key' => 'product_review_request',
                'name' => 'Product Review Request',
                'description' => 'Sent to request product review',
                'subject' => "Invitation to Review Products You've Recently Purchased",
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => "Invitation to Review Products You've Recently Purchased", 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => "Review products you've recently purchased", 'is_system' => true],
                    ['phrase_name' => 'hello', 'phrase_value' => 'Hello {{name}},', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => 'Thanks for your recent order with us!', 'is_system' => true],
                    ['phrase_name' => 'instruction', 'phrase_value' => "If you have a few minutes, we'd like to invite you to review the products you ordered. Just click on the link below to write a review and share your opinion with other shoppers.", 'is_system' => true],
                    ['phrase_name' => 'review_text', 'phrase_value' => 'Review product', 'is_system' => true],
                    ['phrase_name' => 'thanks', 'phrase_value' => 'Thanks in advance for taking the time to review the products you purchased!', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],

            // Return Requested
            [
                'key' => 'return_requested',
                'name' => 'Return Requested',
                'description' => 'Sent after return has been requested',
                'subject' => 'Your Return Request on {{store}}',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Your Return Request on {{store}}', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'Return request confirmation', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => 'You can see the summary of your return request for order #{{id}} below.', 'is_system' => true],
                    ['phrase_name' => 'details_title', 'phrase_value' => 'Return details', 'is_system' => true],
                    ['phrase_name' => 'return_reason', 'phrase_value' => 'Return reason:', 'is_system' => true],
                    ['phrase_name' => 'return_action', 'phrase_value' => 'Return action:', 'is_system' => true],
                    ['phrase_name' => 'return_comments', 'phrase_value' => 'Your comments:', 'is_system' => true],
                    ['phrase_name' => 'check_status', 'phrase_value' => 'Check return status', 'is_system' => true],
                    ['phrase_name' => 'products_title', 'phrase_value' => 'Return items', 'is_system' => true],
                    ['phrase_name' => 'product_sku', 'phrase_value' => 'SKU', 'is_system' => true],
                    ['phrase_name' => 'quantity', 'phrase_value' => 'Qty', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],

            // Account Created
            [
                'key' => 'account_created',
                'name' => 'Account Created',
                'description' => 'Sent when a customer or store admin creates their account',
                'subject' => 'Thanks for registering at {{store}}',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Thanks for registering at {{store}}', 'is_system' => true],
                    ['phrase_name' => 'hello', 'phrase_value' => 'Hello {{name}},', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => 'Thank you for creating your account at {{store}}.', 'is_system' => true],
                    ['phrase_name' => 'details_title', 'phrase_value' => 'Account details', 'is_system' => true],
                    ['phrase_name' => 'email_label', 'phrase_value' => 'Email Address:', 'is_system' => true],
                    ['phrase_name' => 'password_label', 'phrase_value' => 'Password:', 'is_system' => true],
                    ['phrase_name' => 'password_placeholder', 'phrase_value' => '[The password you specified]', 'is_system' => true],
                    ['phrase_name' => 'sign_in', 'phrase_value' => 'Sign in to account', 'is_system' => true],
                    ['phrase_name' => 'help', 'phrase_value' => "If you have any questions regarding your account, click 'Reply' in your email client and we'll be only too happy to help.", 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],

            // Return Status Change
            [
                'key' => 'return_status_change',
                'name' => 'Return Status Change',
                'description' => 'Sent after return status has changed',
                'subject' => 'Return Status Update on {{store}}',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Return Status Update on {{store}}', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'Return request status changed', 'is_system' => true],
                    ['phrase_name' => 'hello', 'phrase_value' => 'Hello {{name}},', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => 'The status of your return request for order #{{id}} has changed to "{{status}}".', 'is_system' => true],
                    ['phrase_name' => 'details_title', 'phrase_value' => 'Return details', 'is_system' => true],
                    ['phrase_name' => 'return_reason', 'phrase_value' => 'Return reason:', 'is_system' => true],
                    ['phrase_name' => 'return_action', 'phrase_value' => 'Return action:', 'is_system' => true],
                    ['phrase_name' => 'return_comments', 'phrase_value' => 'Your comments:', 'is_system' => true],
                    ['phrase_name' => 'check_status', 'phrase_value' => 'Check return status', 'is_system' => true],
                    ['phrase_name' => 'products_title', 'phrase_value' => 'Return items', 'is_system' => true],
                    ['phrase_name' => 'quantity', 'phrase_value' => 'Qty:', 'is_system' => true],
                    ['phrase_name' => 'instructions_title', 'phrase_value' => 'Return Instructions:', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],

            // Order Ready for Pickup
            [
                'key' => 'order_ready_for_pickup',
                'name' => 'Order ready for pickup',
                'description' => 'Order ready for pickup',
                'subject' => '{{store}} - Order is ready for pickup',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => '{{store}} - Order is ready for pickup', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'Ready for pick up', 'is_system' => true],
                    ['phrase_name' => 'product_title', 'phrase_value' => 'Order #{{id}}', 'is_system' => true],
                    ['phrase_name' => 'instructions_title', 'phrase_value' => '{{method}} Instructions', 'is_system' => true],
                    ['phrase_name' => 'location_title', 'phrase_value' => 'Pickup location', 'is_system' => true],
                    ['phrase_name' => 'phone_label', 'phrase_value' => 'Phone:', 'is_system' => true],
                    ['phrase_name' => 'email_label', 'phrase_value' => 'Email:', 'is_system' => true],
                    ['phrase_name' => 'opening_hours', 'phrase_value' => 'Opening hours *', 'is_system' => true],
                    ['phrase_name' => 'monday', 'phrase_value' => 'Monday', 'is_system' => true],
                    ['phrase_name' => 'tuesday', 'phrase_value' => 'Tuesday', 'is_system' => true],
                    ['phrase_name' => 'wednesday', 'phrase_value' => 'Wednesday', 'is_system' => true],
                    ['phrase_name' => 'thursday', 'phrase_value' => 'Thursday', 'is_system' => true],
                    ['phrase_name' => 'friday', 'phrase_value' => 'Friday', 'is_system' => true],
                    ['phrase_name' => 'saturday', 'phrase_value' => 'Saturday', 'is_system' => true],
                    ['phrase_name' => 'sunday', 'phrase_value' => 'Sunday', 'is_system' => true],
                    ['phrase_name' => 'closed', 'phrase_value' => 'Closed', 'is_system' => true],
                    ['phrase_name' => 'opening_hours_note', 'phrase_value' => 'Please, visit website to check special hours', 'is_system' => true],
                    ['phrase_name' => 'quantity', 'phrase_value' => 'Qty:', 'is_system' => true],
                    ['phrase_name' => 'check_label', 'phrase_value' => 'Check order status', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],

            // Guest Order Access
            [
                'key' => 'guest_order_access',
                'name' => 'Guest Order Access',
                'description' => 'Sent when guest requests order access',
                'subject' => 'Your Order #{{order_id}} - Secure Access Link',
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Your Order #{{order_id}} - Secure Access Link', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'Your Order Details', 'is_system' => true],
                    ['phrase_name' => 'intro', 'phrase_value' => 'You requested a secure link to view your order details.', 'is_system' => true],
                    ['phrase_name' => 'view_order_button', 'phrase_value' => 'View Your Order', 'is_system' => true],
                    ['phrase_name' => 'order_summary_title', 'phrase_value' => 'Order Summary', 'is_system' => true],
                    ['phrase_name' => 'order_id_label', 'phrase_value' => 'Order ID:', 'is_system' => true],
                    ['phrase_name' => 'order_total_label', 'phrase_value' => 'Order Total:', 'is_system' => true],
                    ['phrase_name' => 'security_notice', 'phrase_value' => 'For your privacy, please do not share this link with others.', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],
        ];

        foreach ($templates as $item) {
            $template = TransactionalEmailTemplate::updateOrCreate(
                ['store_id' => $storeId, 'key' => $item['key']],
                [
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'enabled' => true,
                    'subject' => $item['subject'] ?? $item['name'],
                    'body_html' => '<html><body><h1>{{title}}</h1><p>{{hello}}</p><p>{{message}}</p><p><a href="{{store_url}}">{{go_shopping}}</a></p></body></html>',
                ]
            );

            if (!empty($item['phrases'])) {
                foreach ($item['phrases'] as $phrase) {
                    TransactionalEmailPhrase::updateOrCreate(
                        ['template_id' => $template->id, 'phrase_name' => $phrase['phrase_name']],
                        ['phrase_value' => $phrase['phrase_value'], 'is_system' => $phrase['is_system'] ?? false]
                    );
                }
            }
        }

        $this->command->info('Transactional emails seeded successfully for store ' . $storeId);
    }
}
