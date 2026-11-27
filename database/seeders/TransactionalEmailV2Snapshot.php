<?php

namespace Database\Seeders;

/**
 * Frozen defaults of the v2 transactional email templates (body hashes
 * and phrase values), so the upgrader can recognise untouched v2
 * templates and move them to v3. Do not edit.
 */
class TransactionalEmailV2Snapshot
{
    public const DEFAULTS = array (
  'order_email' => 
  array (
    'hash' => 'b4f19add8f46208f8bd920ee8a7dc2c2c1334f7e',
    'phrases' => 
    array (
      'subject' => 'Your {{store.name}} Order Confirmation ({{order.id}})',
      'title' => 'Thanks for your order',
      'order_title' => 'Order #{{order.id}}',
      'quantity' => 'Qty:',
      'no_image' => 'Image Coming Soon',
      'sku' => 'SKU:',
      'brand' => 'Brand:',
      'subtotal' => 'Subtotal',
      'shipping' => 'Shipping',
      'discount' => 'Discount',
      'manual_discount' => 'Manual Discount',
      'total' => 'Total',
      'shipping_method' => 'Shipping method:',
      'payment_method' => 'Payment method:',
      'billing_address_label' => 'Billing address',
      'comment_label' => 'Order comments',
      'check_label' => 'Check order status',
      'go_shopping' => 'Go shopping',
      'hello' => 'Hi {{customer.first_name}},',
      'message' => 'We have received your order and will let you know when it ships.',
    ),
  ),
  'order_status_update' => 
  array (
    'hash' => '03a8a7488de042890595d01099e276c478273d6d',
    'phrases' => 
    array (
      'subject' => 'Order #{{order.id}} Status Updated to {{status.name}} - {{store.name}}',
      'title' => 'Order status changed',
      'hello' => 'Hello {{customer.first_name}},',
      'message' => 'The status of your order #{{order.id}} has changed to:',
      'details_title' => 'Order details',
      'order_total' => 'Order total:',
      'date_placed' => 'Date placed:',
      'shipping' => 'Shipping:',
      'payment_method' => 'Payment method:',
      'tracking_number' => 'Tracking number:',
      'shipping_method' => 'Shipping method:',
      'shipped_by' => 'Shipped by:',
      'check_status' => 'Check order status',
      'go_shopping' => 'Go shopping',
    ),
  ),
  'order_ready_for_pickup' => 
  array (
    'hash' => '20e3c6b3a06f6619e0ff3257989a2432cd6747c2',
    'phrases' => 
    array (
      'subject' => '{{store.name}} - Order #{{order.id}} is ready for pickup',
      'title' => 'Ready for pick up',
      'hello' => 'Hello {{customer.first_name}},',
      'message' => 'Good news! Your order is ready and waiting for you to pick it up.',
      'order_title' => 'Order #{{order.id}}',
      'quantity' => 'Qty:',
      'no_image' => 'Image Coming Soon',
      'sku' => 'SKU:',
      'brand' => 'Brand:',
      'location_title' => 'Pickup location',
      'opening_hours_note' => 'Please visit our website to check opening hours.',
      'check_status' => 'Check order status',
      'go_shopping' => 'Go shopping',
    ),
  ),
  'guest_account_created' => 
  array (
    'hash' => '908a4efb996c2008de826cda87a644750682f2c6',
    'phrases' => 
    array (
      'subject' => 'Create Your Account - {{store.name}}',
      'title' => 'Welcome to {{store.name}}',
      'hello' => 'Hello {{customer.first_name}},',
      'message' => 'Thanks for your order! Create an account to check the status of your orders and check out faster next time.',
      'instruction' => 'Click the button below to create your account:',
      'create_account' => 'Create your account',
      'email_label' => 'Email address:',
      'help' => 'If you have any questions, just reply to this email and we\'ll be happy to help.',
      'go_shopping' => 'Go shopping',
    ),
  ),
  'account_created' => 
  array (
    'hash' => 'cd1057180f9719b075964a80ecef14d62fc4c274',
    'phrases' => 
    array (
      'subject' => '{{#if links.set_password}}Your Login Details - Set Your Password{{else}}Thanks for registering at {{store.name}}{{/if}}',
      'title' => 'Welcome to {{store.name}}',
      'hello' => 'Hello {{customer.first_name}},',
      'message' => 'Thank you for creating your account at {{store.name}}.',
      'details_title' => 'Account details',
      'email_label' => 'Email address:',
      'set_password_message' => 'An account has been created for you. Please set your password to sign in:',
      'set_password' => 'Set your password',
      'sign_in' => 'Sign in to account',
      'help' => 'If you have any questions regarding your account, just reply to this email and we\'ll be happy to help.',
      'go_shopping' => 'Go shopping',
    ),
  ),
  'sign_in_link_request' => 
  array (
    'hash' => 'f28fca9950dd2a676f4e274923cc8799df19c97e',
    'phrases' => 
    array (
      'subject' => 'Your sign-in link for {{store.name}}',
      'title' => 'Sign in to {{store.name}}',
      'message' => 'You requested a sign-in link. Click the button below to sign in. The link expires in 15 minutes.',
      'sign_in' => 'Sign in',
      'alternatively' => 'Or paste this link into your browser:',
      'login_request' => 'This link is for {{store.name}}. If you did not request it, you can ignore this email.',
      'secure' => 'Your account is still secure.',
      'go_shopping' => 'Go shopping',
    ),
  ),
  'password_reset' => 
  array (
    'hash' => '666201376e4955e7899ddb3c107aed5b60bf3b61',
    'phrases' => 
    array (
      'subject' => 'Your Password Was Successfully Reset',
      'title' => 'Your password has been changed',
      'hello' => 'Hello {{customer.first_name}},',
      'message' => 'The password for your {{store.name}} account was successfully reset.',
      'warning' => 'If you did not make this change, please contact us immediately.',
      'sign_in' => 'Sign in to account',
      'go_shopping' => 'Go shopping',
    ),
  ),
  'order_notification' => 
  array (
    'hash' => '9472ca3339921441122488867b53067e7aa03098',
    'phrases' => 
    array (
      'subject' => 'Re: {{message.subject}} - {{store.name}}',
      'title' => 'New reply to your order message',
      'hello' => 'Hello {{customer.first_name}},',
      'message' => '{{store.name}} replied to your message regarding order #{{order.id}}:',
      'reply' => 'Visit store',
      'go_shopping' => 'Go shopping',
    ),
  ),
);
}
