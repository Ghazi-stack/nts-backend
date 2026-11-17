<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Method Categories
    |--------------------------------------------------------------------------
    */

    'categories' => [

        'online' => [
            'label' => 'Online payment methods',
            'description' => 'Accept payments through online payment providers.',
            'sortOrder' => 1,
        ],

        'wallet' => [
            'label' => 'Digital wallets',
            'description' => 'Accept payments through supported digital wallets.',
            'sortOrder' => 2,
        ],

        'offline' => [
            'label' => 'Offline payment methods',
            'description' => 'Accept payments outside online payment gateways.',
            'sortOrder' => 3,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Payment Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [

        'stripe' => [
            'code' => 'stripe',
            'displayName' => 'Stripe',
            'connectionType' => 'oauth',
        ],

        'paypal' => [
            'code' => 'paypal',
            'displayName' => 'PayPal',
            'connectionType' => 'credentials',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Methods
    |--------------------------------------------------------------------------
    */

    'methods' => [

        /*
        |--------------------------------------------------------------------------
        | OFFLINE PAYMENT METHODS
        |--------------------------------------------------------------------------
        */

        'bank_deposit' => [

            'methodCode' => 'bank_deposit',
            'category' => 'offline',
            'displayName' => 'Bank deposit',

            'description' => 'Allow customers to pay through a bank deposit.',

            'setupType' => 'manual',

            'icon' => 'bank',

            'configurationFields' => [

                'accountInformation' => [
                    'type' => 'textarea',
                    'label' => 'Account Information',
                    'description' => 'Provide bank name, branch, account name, account number and payment instructions.',
                    'placeholder' => 'Bank Name: ABC Bank
Bank Branch: New York
Account Name: John Smith
Account Number: XXXXXXXXXXXX

Type any special instructions here.',
                    'required' => true,
                    'maxLength' => 5000,
                ],

            ],

            'supportsCountries' => true,
            'supportsEnableDisable' => true,

            'supportedProviders' => [],

        ],

        'cash_on_delivery' => [

            'methodCode' => 'cash_on_delivery',
            'category' => 'offline',
            'displayName' => 'Cash on delivery',

            'description' => 'Allow customers to pay when their order is delivered.',

            'setupType' => 'manual',

            'icon' => 'banknote',

            'configurationFields' => [

                'deliveryInformation' => [
                    'type' => 'textarea',
                    'label' => 'Delivery Information',
                    'description' => 'Instructions displayed to customers after placing their order.',
                    'placeholder' => 'Type your cash on delivery instructions here.',
                    'required' => true,
                    'maxLength' => 5000,
                ],

            ],

            'supportsCountries' => true,
            'supportsEnableDisable' => true,

            'supportedProviders' => [],

        ],

        'check' => [

            'methodCode' => 'check',
            'category' => 'offline',
            'displayName' => 'Check',

            'description' => 'Allow customers to pay by check.',

            'setupType' => 'manual',

            'icon' => 'file-check',

            'configurationFields' => [

                'paymentInformation' => [
                    'type' => 'textarea',
                    'label' => 'Payment Information',
                    'description' => 'Tell customers where to send the check and how to complete payment.',
                    'placeholder' => 'Type instructions to pay by check here.',
                    'required' => true,
                    'maxLength' => 5000,
                ],

            ],

            'supportsCountries' => true,
            'supportsEnableDisable' => true,

            'supportedProviders' => [],

        ],

        'money_order' => [

            'methodCode' => 'money_order',
            'category' => 'offline',
            'displayName' => 'Money order',

            'description' => 'Allow customers to pay through a money order.',

            'setupType' => 'manual',

            'icon' => 'receipt',

            'configurationFields' => [

                'paymentInformation' => [
                    'type' => 'textarea',
                    'label' => 'Payment Information',
                    'description' => 'Tell customers where to send the money order.',
                    'placeholder' => 'Type instructions to pay by money order here.',
                    'required' => true,
                    'maxLength' => 5000,
                ],

            ],

            'supportsCountries' => true,
            'supportsEnableDisable' => true,

            'supportedProviders' => [],

        ],

        'pay_in_store' => [

            'methodCode' => 'pay_in_store',
            'category' => 'offline',
            'displayName' => 'Pay in-store',

            'description' => 'Allow customers to pay by visiting your physical store.',

            'setupType' => 'manual',

            'icon' => 'store',

            'configurationFields' => [

                'paymentInformation' => [
                    'type' => 'textarea',
                    'label' => 'Payment Information',
                    'description' => 'Provide instructions for customers visiting your store.',
                    'placeholder' => 'Type instructions to pay by visiting your retail store here.',
                    'required' => true,
                    'maxLength' => 5000,
                ],

            ],

            'supportsCountries' => true,
            'supportsEnableDisable' => true,

            'supportedProviders' => [],

        ],

        /*
        |--------------------------------------------------------------------------
        | ONLINE PAYMENT METHODS
        |--------------------------------------------------------------------------
        */

        'stripe' => [

            'methodCode' => 'stripe',
            'category' => 'online',
            'displayName' => 'Stripe',

            'description' => 'Accept credit cards and supported payment methods through Stripe.',

            'setupType' => 'oauth',

            'icon' => 'stripe',

            'configurationFields' => [],

            'supportsCountries' => false,
            'supportsEnableDisable' => true,

            'supportedProviders' => [],

        ],

        'paypal' => [

            'methodCode' => 'paypal',
            'category' => 'online',
            'displayName' => 'PayPal',

            'description' => 'Accept payments through PayPal.',

            'setupType' => 'credentials',

            'icon' => 'paypal',

            'configurationFields' => [

                'environment' => [
                    'type' => 'select',
                    'label' => 'Environment',
                    'options' => [
                        [
                            'label' => 'Sandbox',
                            'value' => 'sandbox',
                        ],
                        [
                            'label' => 'Live',
                            'value' => 'live',
                        ],
                    ],
                    'required' => true,
                ],

                'clientId' => [
                    'type' => 'text',
                    'label' => 'Client ID',
                    'description' => 'Your PayPal application client ID.',
                    'required' => true,
                    'maxLength' => 500,
                ],

                'clientSecret' => [
                    'type' => 'password',
                    'label' => 'Client Secret',
                    'description' => 'Your PayPal application client secret.',
                    'required' => true,
                    'maxLength' => 10000,
                    'sensitive' => true,
                ],

            ],

            'supportsCountries' => false,
            'supportsEnableDisable' => true,

            'supportedProviders' => [],

        ],

        /*
        |--------------------------------------------------------------------------
        | DIGITAL WALLETS
        |--------------------------------------------------------------------------
        */

        'apple_pay' => [

            'methodCode' => 'apple_pay',
            'category' => 'wallet',
            'displayName' => 'Apple Pay',

            'description' => 'Allow customers to pay using Apple Pay through a supported payment provider.',

            'setupType' => 'provider_managed',

            'icon' => 'apple',

            'configurationFields' => [],

            'supportsCountries' => true,
            'supportsEnableDisable' => true,

            'supportedProviders' => [

                'stripe',
                'paypal',

            ],

        ],

        'google_pay' => [

            'methodCode' => 'google_pay',
            'category' => 'wallet',
            'displayName' => 'Google Pay',

            'description' => 'Allow customers to pay using Google Pay through a supported payment provider.',

            'setupType' => 'provider_managed',

            'icon' => 'google',

            'configurationFields' => [],

            'supportsCountries' => true,
            'supportsEnableDisable' => true,

            'supportedProviders' => [

                'stripe',
                'paypal',

            ],

        ],

    ],

];