<?php

return [

    'defaults' => [

        'transaction_type' => 'authorize_capture',

        'test_mode' => true,

        'statement_descriptor' => null,

        'create_customer_on_payment_attempt' => false,

        'send_receipt_email' => false,

        'sort_payment_methods_first' => false,

        'stripe_link_enabled' => false,

        'adaptive_pricing_enabled' => false,

        'saved_cards_enabled' => false,

        'payment_methods' => [
            'card' => true,
            'apple_pay' => false,
            'google_pay' => false,
            'link' => false,
        ],

    ],

    'transaction_types' => [
        'authorize_capture',
        'authorize_only',
    ],

    'environments' => [
        'test',
        'live',
    ],

    'initial_webhook_events' => [
        'payment_intent.amount_capturable_updated',
        'payment_intent.succeeded',
        'payment_intent.canceled',
        'payment_intent.payment_failed',
    ],

];
