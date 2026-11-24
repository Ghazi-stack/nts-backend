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

        $templates = $this->getTemplates();

        foreach ($templates as $item) {
            $template = TransactionalEmailTemplate::updateOrCreate(
                ['store_id' => $storeId, 'key' => $item['key']],
                [
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'enabled' => true,
                    'subject' => $item['subject'] ?? $item['name'],
                    'body_html' => $item['body_html'],
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

    private function getTemplates(): array
    {
        return [
            [
                'key' => 'account_settings_edited',
                'name' => 'Account Settings Edited',
                'description' => 'Sent when a customer or store admin edits account settings',
                'subject' => 'Your {{store}} account details have changed',
                'body_html' => $this->getAccountSettingsHtml(),
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
            [
                'key' => 'password_reset',
                'name' => 'Password Reset',
                'description' => 'Sent when a customer account password is reset',
                'subject' => 'Password change request for {{store}}',
                'body_html' => $this->getPasswordResetHtml(),
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Password change request for {{store}}', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'Change your password for {{store}}', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => 'To change your customer account password at {{store}} please click this link or copy and paste it into your browser:', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],
            [
                'key' => 'account_created',
                'name' => 'Account Created',
                'description' => 'Sent when a customer or store admin creates their account',
                'subject' => 'Thanks for registering at {{store}}',
                'body_html' => $this->getAccountCreatedHtml(),
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
            [
                'key' => 'guest_account_created',
                'name' => 'Guest Account Created',
                'description' => 'Sent when a customer creates account as guest',
                'subject' => 'Welcome to {{store}}',
                'body_html' => $this->getGuestAccountHtml(),
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Welcome to {{store}}', 'is_system' => true],
                    ['phrase_name' => 'hello', 'phrase_value' => 'Hello {{name}},', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => "For your convenience, we've created you an account on {{store}} so you can check the status of your order and checkout quicker in the future.", 'is_system' => true],
                    ['phrase_name' => 'instruction', 'phrase_value' => 'To login you will need to follow the link below to nominate your password:', 'is_system' => true],
                    ['phrase_name' => 'help', 'phrase_value' => "If you have any questions regarding your account, click 'Reply' in your email client and we'll be only too happy to help.", 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],
            [
                'key' => 'sign_in_link_request',
                'name' => 'Sign-in Link Request',
                'description' => 'Sent when customer requests passwordless login',
                'subject' => '{{store}} - Log in to your account',
                'body_html' => $this->getSignInLinkHtml(),
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
            [
                'key' => 'gift_certificate_recipient',
                'name' => 'Gift Certificate Recipient',
                'description' => 'Sent to the recipient of a gift certificate',
                'subject' => '{{from}} has sent you a gift certificate for {{store}}',
                'body_html' => $this->getGiftCertificateHtml(),
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => '{{from}} has sent you a gift certificate for {{store}}', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'You have received a Gift Certificate for {{store}}', 'is_system' => true],
                    ['phrase_name' => 'hello', 'phrase_value' => 'Dear {{name}},', 'is_system' => true],
                    ['phrase_name' => 'attached', 'phrase_value' => 'Your gift certificate is attached to this email.', 'is_system' => true],
                    ['phrase_name' => 'warning', 'phrase_value' => 'Please download or print a copy of your gift certificate for safe keeping as gift certificates are non-transferable.', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],
            [
                'key' => 'order_notification',
                'name' => 'Order Notification',
                'description' => 'Sent when store admin sends order message',
                'subject' => 'New Reply to your Order Message',
                'body_html' => $this->getOrderNotificationHtml(),
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'New Reply to your Order Message', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'New Reply to your Order Message', 'is_system' => true],
                    ['phrase_name' => 'hello', 'phrase_value' => 'Hello {{name}},', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => '{{store}} has sent you a message about your order.', 'is_system' => true],
                    ['phrase_name' => 'reply', 'phrase_value' => 'Reply', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],
            [
                'key' => 'product_review_request',
                'name' => 'Product Review Request',
                'description' => 'Sent to request product review',
                'subject' => "Invitation to Review Products You've Recently Purchased",
                'body_html' => $this->getProductReviewHtml(),
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
            [
                'key' => 'guest_order_access',
                'name' => 'Guest Order Access',
                'description' => 'Sent when guest requests order access',
                'subject' => 'Your Order #{{order_id}} - Secure Access Link',
                'body_html' => $this->getGuestOrderAccessHtml(),
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
            [
                'key' => 'order_status_update',
                'name' => 'Order Status Update',
                'description' => 'Sent when the status of an order is changed',
                'subject' => 'Your {{store}} Order Has Been Updated (#{{id}})',
                'body_html' => $this->getOrderStatusUpdateHtml(),
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Your {{store}} Order Has Been Updated (#{{id}})', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'Order status changed', 'is_system' => true],
                    ['phrase_name' => 'hello', 'phrase_value' => 'Hi {{name}},', 'is_system' => true],
                    ['phrase_name' => 'message', 'phrase_value' => 'The status of your order #{{id}} has changed to "{{status}}".', 'is_system' => true],
                    ['phrase_name' => 'details_title', 'phrase_value' => 'Order details', 'is_system' => true],
                    ['phrase_name' => 'order_total', 'phrase_value' => 'Order total:', 'is_system' => true],
                    ['phrase_name' => 'date_placed', 'phrase_value' => 'Date placed:', 'is_system' => true],
                    ['phrase_name' => 'payment_method', 'phrase_value' => 'Payment method:', 'is_system' => true],
                    ['phrase_name' => 'check_status', 'phrase_value' => 'Check order status', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],
            [
                'key' => 'order_email',
                'name' => 'Order Email',
                'description' => 'Sent when an order is created',
                'subject' => 'Your {{store}} Order Confirmation (#{{id}})',
                'body_html' => $this->getOrderEmailHtml(),
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => 'Your {{store}} Order Confirmation (#{{id}})', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'Thanks for your order', 'is_system' => true],
                    ['phrase_name' => 'product_title', 'phrase_value' => 'Order #{{id}}', 'is_system' => true],
                    ['phrase_name' => 'quantity', 'phrase_value' => 'Qty:', 'is_system' => true],
                    ['phrase_name' => 'shipping_method', 'phrase_value' => 'Shipping method:', 'is_system' => true],
                    ['phrase_name' => 'payment_method', 'phrase_value' => 'Payment method:', 'is_system' => true],
                    ['phrase_name' => 'shipping_address_label', 'phrase_value' => 'Shipping address', 'is_system' => true],
                    ['phrase_name' => 'billing_address_label', 'phrase_value' => 'Billing Address', 'is_system' => true],
                    ['phrase_name' => 'comment_label', 'phrase_value' => 'Order comments', 'is_system' => true],
                    ['phrase_name' => 'check_label', 'phrase_value' => 'Check order status', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],
            [
                'key' => 'return_requested',
                'name' => 'Return Requested',
                'description' => 'Sent after return has been requested',
                'subject' => 'Your Return Request on {{store}}',
                'body_html' => $this->getReturnRequestedHtml(),
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
                    ['phrase_name' => 'quantity', 'phrase_value' => 'Qty', 'is_system' => true],
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],
            [
                'key' => 'return_status_change',
                'name' => 'Return Status Change',
                'description' => 'Sent after return status has changed',
                'subject' => 'Return Status Update on {{store}}',
                'body_html' => $this->getReturnStatusChangeHtml(),
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
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],
            [
                'key' => 'order_ready_for_pickup',
                'name' => 'Order ready for pickup',
                'description' => 'Order ready for pickup',
                'subject' => '{{store}} - Order is ready for pickup',
                'body_html' => $this->getOrderReadyForPickupHtml(),
                'phrases' => [
                    ['phrase_name' => 'subject', 'phrase_value' => '{{store}} - Order is ready for pickup', 'is_system' => true],
                    ['phrase_name' => 'title', 'phrase_value' => 'Ready for pick up', 'is_system' => true],
                    ['phrase_name' => 'product_title', 'phrase_value' => 'Order #{{id}}', 'is_system' => true],
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
                    ['phrase_name' => 'go_shopping', 'phrase_value' => 'Go shopping', 'is_system' => true],
                ],
            ],
        ];
    }

    private function getAccountSettingsHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 12px}a{color:#2199e8;text-decoration:none}strong{font-weight:600}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{subject}}</h1><p>{{hello}}</p><p>{{message}}</p><p><strong>Email, Password</strong></p><p>{{warning}}</p><p style="margin-top:20px">{{thanks}}<br>{{security}}</p></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getPasswordResetHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 16px}a{color:#2199e8;text-decoration:none}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{title}}</h1><p>{{message}}</p><p><a href="https://dashboard.advertsedge.com/manage/marketing/transactional-emails/password_reset">#reset-password-link</a></p></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getAccountCreatedHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}h2{font-size:18px;font-weight:500;color:#333;margin:24px 0 12px}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 8px}a{color:#2199e8;text-decoration:none}strong{font-weight:600}.details{border-top:1px solid #e5e5e5;padding-top:12px;margin-top:8px}.details-row{margin-bottom:8px}.details-label{color:#666;display:inline-block;width:120px}.sign-in-btn{display:inline-block;border:1px solid #999;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;text-decoration:none;margin-top:12px}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{subject}}</h1><p>{{hello}}</p><p>{{message}}</p><h2>{{details_title}}</h2><div class="details"><div class="details-row"><span class="details-label">{{email_label}}</span> <a href="mailto:customer@example.com"><strong>customer@example.com</strong></a></div><div class="details-row"><span class="details-label">{{password_label}}</span> <strong>{{password_placeholder}}</strong></div></div><a href="{{store_url}}" class="sign-in-btn">{{sign_in}}</a><p style="margin-top:24px">{{help}}</p></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getGuestAccountHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 16px}a{color:#2199e8;text-decoration:none}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{subject}}</h1><p>{{hello}}</p><p>{{message}}</p><p>{{instruction}}</p><p><a href="https://dashboard.advertsedge.com/manage/marketing/transactional-emails/guest_account_created">#reset-password-link</a></p><p>{{help}}</p></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getSignInLinkHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 16px}a{color:#2199e8;text-decoration:none}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{title}}</h1><p>You have requested a sign-in link. Please <a href="https://dashboard.advertsedge.com/manage/marketing/transactional-emails/sign_in_link_request">click here to sign in</a> and continue.</p><p>{{alternatively}}</p><p><a href="https://dashboard.advertsedge.com/manage/marketing/transactional-emails/sign_in_link_request">#sign-in-link</a></p><p>{{login_request}}</p><p>{{secure}}</p></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getGiftCertificateHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 16px}a{color:#2199e8;text-decoration:none}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{title}}</h1><p>{{hello}}</p><p>Mr. Stewart Hartmann (vbarrows@friesen.com) has sent you a $12.340 gift certificate for <a href="{{store_url}}">{{store}}</a>.</p><p>You have until 1st Dec 2026 to use this gift certificate before it expires. For instructions on how to redeem your gift certificate please <a href="#">click here</a>.</p><p>{{attached}} {{warning}}</p></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getOrderNotificationHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 12px}a{color:#2199e8;text-decoration:none}strong{font-weight:600}.reply-box{border-top:1px solid #e5e5e5;padding-top:20px;margin-top:20px}.reply-btn{display:inline-block;background:#555;color:#fff;border-radius:4px;padding:8px 20px;font-weight:600;text-decoration:none;margin-top:16px}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{title}}</h1><p>{{hello}}</p><p>{{message}}</p><div class="reply-box"><p><strong>{{store}}</strong></p><p>Demo message from the merchant</p><a href="#" class="reply-btn">{{reply}}</a></div></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getProductReviewHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 12px}a{color:#2199e8;text-decoration:none}strong{font-weight:600}.product{border-top:1px solid #e5e5e5;border-bottom:1px solid #e5e5e5;padding:16px 0;margin:20px 0;overflow:hidden}.product-image{width:70px;height:70px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;float:left;margin-right:16px;text-align:center;line-height:70px;font-size:10px;color:#999}.product-info{float:left}.product-sku{font-size:12px;color:#666;margin:0}.review-btn{display:inline-block;background:#555;color:#fff;border-radius:4px;padding:6px 16px;font-weight:600;text-decoration:none;float:right;margin-top:16px}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{title}}</h1><p>{{hello}}</p><p>{{message}}</p><p>{{instruction}}</p><div class="product"><div class="product-image">Image<br>Coming<br>Soon</div><div class="product-info"><p><strong>Name of Product</strong></p><p class="product-sku">FA44</p></div><a href="#" class="review-btn">{{review_text}}</a></div><p>{{thanks}}</p></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getGuestOrderAccessHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 12px}a{color:#2199e8;text-decoration:none}strong{font-weight:600}.info-box{background:#f8f9fa;border:1px solid #e5e5e5;border-radius:4px;padding:16px;margin:20px 0}.info-box p{margin:0 0 4px;font-size:13px}.view-btn{display:inline-block;background:#2199e8;color:#fff;border-radius:4px;padding:12px 32px;font-weight:600;font-size:16px;text-decoration:none}.security{color:#777;font-size:13px;margin-top:16px}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{title}}</h1><p>{{intro}}</p><div class="info-box"><p><strong>{{order_summary_title}}</strong></p><p>{{order_id_label}} <strong>#100</strong></p><p>{{order_total_label}} <strong>$123.450</strong></p></div><div style="text-align:center"><a href="#" class="view-btn">{{view_order_button}}</a></div><p class="security">{{security_notice}}</p></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getOrderStatusUpdateHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}h2{font-size:18px;font-weight:500;color:#333;margin:24px 0 12px}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 8px}a{color:#2199e8;text-decoration:none}strong{font-weight:600}.details{border-top:1px solid #e5e5e5;padding-top:12px;margin-top:8px}.details-row{margin-bottom:8px}.details-label{color:#666;display:inline-block;width:140px}.check-btn{display:inline-block;border:1px solid #999;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;text-decoration:none;margin-top:12px}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{title}}</h1><p>{{hello}}</p><p>The status of your order #1 has changed to "Shipped".</p><h2>{{details_title}}</h2><div class="details"><div class="details-row"><span class="details-label">{{order_total}}</span> <strong>$250.100</strong></div><div class="details-row"><span class="details-label">{{date_placed}}</span> <strong>15th Nov 2026</strong></div><div class="details-row"><span class="details-label">{{payment_method}}</span> <strong>Credit Card</strong></div></div><a href="#" class="check-btn">{{check_status}}</a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getOrderEmailHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}h2{font-size:18px;font-weight:500;color:#333;margin:24px 0 12px}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 8px}a{color:#2199e8;text-decoration:none}strong{font-weight:600}.product{border-top:1px solid #e5e5e5;border-bottom:1px solid #e5e5e5;padding:16px 0;margin:16px 0;overflow:hidden}.product-image{width:60px;height:60px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;float:left;margin-right:16px;text-align:center;line-height:60px;font-size:9px;color:#999}.product-info{float:left}.product-sku{font-size:12px;color:#666;margin:0}.product-qty{float:right}.address-box{background:#f9f9f9;border:1px solid #e5e5e5;border-radius:4px;padding:12px;margin:12px 0}.check-btn{display:inline-block;border:1px solid #999;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;text-decoration:none;margin-top:12px}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{title}}</h1><h2>Order #1</h2><div class="product"><div class="product-image">Image<br>Coming<br>Soon</div><div class="product-info"><p><strong>Demo Product</strong></p><p class="product-sku">DEMO-123</p></div><span class="product-qty">{{quantity}} 2</span></div><p><strong>Total: $250.100</strong></p><p>{{shipping_method}} Standard Shipping</p><p>{{payment_method}} Credit Card</p><h2>{{shipping_address_label}}</h2><div class="address-box"><p>John Doe</p><p>123 Main Street</p><p>New York, NY 10001</p><p>United States</p></div><h2>{{billing_address_label}}</h2><div class="address-box"><p>John Doe</p><p>123 Main Street</p><p>New York, NY 10001</p><p>United States</p></div><a href="#" class="check-btn">{{check_label}}</a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getReturnRequestedHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}h2{font-size:18px;font-weight:500;color:#333;margin:24px 0 12px}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 8px}a{color:#2199e8;text-decoration:none}strong{font-weight:600}.details{border-top:1px solid #e5e5e5;padding-top:12px;margin-top:8px}.details-row{margin-bottom:8px}.details-label{color:#666;display:inline-block;width:120px}.product{border-top:1px solid #e5e5e5;border-bottom:1px solid #e5e5e5;padding:16px 0;margin:16px 0;overflow:hidden}.product-image{width:60px;height:60px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;float:left;margin-right:16px;text-align:center;line-height:60px;font-size:9px;color:#999}.product-info{float:left}.product-sku{font-size:12px;color:#666;margin:0}.product-qty{float:right}.check-btn{display:inline-block;border:1px solid #999;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;text-decoration:none;margin-top:12px}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{title}}</h1><p>You can see the summary of your return request for order #321 below.</p><h2>{{details_title}}</h2><div class="details"><div class="details-row"><span class="details-label">{{return_reason}}</span> <strong>Reason of return</strong></div><div class="details-row"><span class="details-label">{{return_action}}</span> <strong>Action</strong></div><div class="details-row"><span class="details-label">{{return_comments}}</span> <strong>Demo comment</strong></div></div><a href="#" class="check-btn">{{check_status}}</a><h2>{{products_title}}</h2><div class="product"><div class="product-image">Image<br>Coming<br>Soon</div><div class="product-info"><p><strong>Demo product name</strong></p><p class="product-sku">DEMO-123</p></div><span class="product-qty">{{quantity}} 3</span></div></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getReturnStatusChangeHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}h2{font-size:18px;font-weight:500;color:#333;margin:24px 0 12px}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 8px}a{color:#2199e8;text-decoration:none}strong{font-weight:600}.details{border-top:1px solid #e5e5e5;padding-top:12px;margin-top:8px}.details-row{margin-bottom:8px}.details-label{color:#666;display:inline-block;width:120px}.product{border-top:1px solid #e5e5e5;border-bottom:1px solid #e5e5e5;padding:16px 0;margin:16px 0;overflow:hidden}.product-image{width:60px;height:60px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;float:left;margin-right:16px;text-align:center;line-height:60px;font-size:9px;color:#999}.product-info{float:left}.product-sku{font-size:12px;color:#666;margin:0}.product-qty{float:right}.check-btn{display:inline-block;border:1px solid #999;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;text-decoration:none;margin-top:12px}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{title}}</h1><p>{{hello}}</p><p>The status of your return request for order #1 has changed to "Pending".</p><h2>{{details_title}}</h2><div class="details"><div class="details-row"><span class="details-label">{{return_reason}}</span> <strong>Reason of return</strong></div><div class="details-row"><span class="details-label">{{return_action}}</span> <strong>Action</strong></div><div class="details-row"><span class="details-label">{{return_comments}}</span> <strong>Demo comment</strong></div></div><a href="#" class="check-btn">{{check_status}}</a><h2>{{products_title}}</h2><div class="product"><div class="product-image">Image<br>Coming<br>Soon</div><div class="product-info"><p><strong>Demo product name</strong></p><p class="product-sku">FF33</p></div><span class="product-qty">{{quantity}} 2</span></div></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }

    private function getOrderReadyForPickupHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><style type="text/css">body{margin:0;padding:0;background:#ffffff;font-family:Montserrat,Arial,sans-serif}.container{max-width:544px;margin:0 auto;padding:0 20px}.logo{text-align:center;padding:24px 0}.logo span{font-size:28px;font-weight:600;color:#333}.divider{padding:8px 0}.divider hr{border:none;border-top:1px solid #e5e5e5;margin:0}.icon{width:40px;height:40px;background:#f5f5f5;border-radius:50%;display:inline-block;text-align:center;line-height:40px}.content{padding:24px 0}h1{font-size:26px;font-weight:600;color:#333;margin:0 0 16px;line-height:1.3}h2{font-size:18px;font-weight:500;color:#333;margin:24px 0 12px}p{font-size:14px;color:#333;line-height:1.5;margin:0 0 8px}a{color:#2199e8;text-decoration:none}strong{font-weight:600}.product{border-top:1px solid #e5e5e5;border-bottom:1px solid #e5e5e5;padding:16px 0;margin:16px 0;overflow:hidden}.product-image{width:60px;height:60px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;float:left;margin-right:16px;text-align:center;line-height:60px;font-size:9px;color:#999}.product-info{float:left}.product-sku{font-size:12px;color:#666;margin:0}.product-qty{float:right}.pickup{border-top:1px solid #e5e5e5;padding-top:16px;margin-top:8px;overflow:hidden}.pickup-address{float:left;width:50%}.pickup-hours{float:right;width:45%}.hours-table{width:100%}.hours-table td{padding:2px 0;font-size:13px}.hours-note{font-size:12px;color:#777;margin-top:8px}.instructions{margin-top:16px}.footer{padding:16px 0 24px}.store-name{font-size:14px;font-weight:600;margin:0 0 2px;color:#333}.store-url{font-size:12px;color:#777}.btn{display:inline-block;border:1px solid #ccc;border-radius:4px;padding:6px 14px;color:#555;font-weight:600;font-size:13px}</style></head>
<body><div class="container"><div class="logo"><a href="{{store_url}}" style="text-decoration:none;color:#333"><span>{{store}}</span></a></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="content"><h1>{{title}}</h1><h2>Order #100</h2><div class="product"><div class="product-image">Image<br>Coming<br>Soon</div><div class="product-info"><p><strong>Name of Product</strong></p><p class="product-sku">FA44</p></div><span class="product-qty">{{quantity}} 3</span></div><h2>{{location_title}}</h2><div class="pickup"><div class="pickup-address"><p><strong>Stehr Inc</strong></p><p>16154 Padberg Burg Apt. 086 New Dane,</p><p>WI 72071-6417</p><p>Apt. 889</p><p>Emersonshire Tennessee 23899-2758</p><p>Peru</p><p style="margin-top:12px">{{phone_label}} 947.268.5023</p><p>{{email_label}} janis91@yahoo.com</p></div><div class="pickup-hours"><p><strong>{{opening_hours}}</strong></p><table class="hours-table"><tr><td>{{monday}}</td><td>09:00 - 19:00</td></tr><tr><td>{{tuesday}}</td><td>09:00 - 19:00</td></tr><tr><td>{{wednesday}}</td><td>09:00 - 19:00</td></tr><tr><td>{{thursday}}</td><td>09:00 - 19:00</td></tr><tr><td>{{friday}}</td><td>09:00 - 19:00</td></tr><tr><td>{{saturday}}</td><td>{{closed}}</td></tr><tr><td>{{sunday}}</td><td>{{closed}}</td></tr></table><p class="hours-note">{{opening_hours_note}}</p></div></div><div class="instructions"><p><strong>In-store pick up instructions</strong></p><p>Ask a manager.</p></div></div><div class="divider"><table width="100%"><tr><td width="45%" style="vertical-align:middle"><hr></td><td width="10%" style="text-align:center"><span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="#999" style="vertical-align:middle"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg></span></td><td width="45%" style="vertical-align:middle"><hr></td></tr></table></div><div class="footer"><table width="100%"><tr><td style="vertical-align:middle"><p class="store-name">{{store}}</p><a href="{{store_url}}" class="store-url">{{store_url}}</a></td><td style="text-align:right;vertical-align:middle"><a href="{{store_url}}" class="btn">{{go_shopping}}</a></td></tr></table></div></div></body>
</html>
HTML;
    }
}
