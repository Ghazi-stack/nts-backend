<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentMethodsSeeder extends Seeder
{
    public function run(?int $storeId = null): void
    {
        if (!$storeId) {
            throw new \RuntimeException(
                'Tenant store ID is required for payment method seeding.'
            );
        }

        $methods = [
            // Offline payment methods
            [
                'method_code' => 'bank_deposit',
                'category' => 'offline',
                'display_name' => 'Bank deposit',
            ],
            [
                'method_code' => 'cash_on_delivery',
                'category' => 'offline',
                'display_name' => 'Cash on delivery',
            ],
            [
                'method_code' => 'check',
                'category' => 'offline',
                'display_name' => 'Check',
            ],
            [
                'method_code' => 'money_order',
                'category' => 'offline',
                'display_name' => 'Money order',
            ],
            [
                'method_code' => 'pay_in_store',
                'category' => 'offline',
                'display_name' => 'Pay in-store',
            ],

            // Digital wallets
            [
                'method_code' => 'apple_pay',
                'category' => 'wallet',
                'display_name' => 'Apple Pay',
            ],
            [
                'method_code' => 'google_pay',
                'category' => 'wallet',
                'display_name' => 'Google Pay',
            ],

            // Online payment gateways
            [
                'method_code' => 'stripe',
                'category' => 'online',
                'display_name' => 'Stripe',
            ],
            [
                'method_code' => 'paypal',
                'category' => 'online',
                'display_name' => 'PayPal',
            ],
        ];

        $now = now();

        $records = [];

        foreach ($methods as $method) {
            $records[] = array_merge($method, [
                'store_id' => $storeId,
                'is_enabled' => false,
                'is_configured' => false,
                'available_countries' => null,
                'configuration' => null,
                'credentials' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::connection('tenant')
            ->table('store_payment_methods')
            ->insertOrIgnore($records);
    }
}