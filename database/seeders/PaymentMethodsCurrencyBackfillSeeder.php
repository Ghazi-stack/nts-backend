<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentMethodsCurrencyBackfillSeeder extends Seeder
{
    public function run(?int $storeId = null): void
    {
        if (!$storeId) {
            throw new \RuntimeException(
                'Tenant store ID is required for currency backfill.'
            );
        }

        $methodCodes = [
            'bank_deposit',
            'cash_on_delivery',
            'check',
            'money_order',
            'pay_in_store',
            'apple_pay',
            'google_pay',
            'stripe',
            'paypal',
        ];

        DB::connection('tenant')
            ->table('store_payment_methods')
            ->where('store_id', $storeId)
            ->whereIn('method_code', $methodCodes)
            ->update([
                'available_currencies' => json_encode(['*']),
                'updated_at' => now(),
            ]);
    }
}