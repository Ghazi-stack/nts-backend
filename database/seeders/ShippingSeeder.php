<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ShippingZone;
use App\Models\ShippingMethod;
use App\Models\FedexConfiguration;

class ShippingSeeder extends Seeder
{
    /**
     * Seed shipping zones and methods exactly like BigCommerce screenshots
     */
    public function run(): void
    {
        // ═══════════════════════════════════════════
        // Zone 1: United States
        // ═══════════════════════════════════════════
        $usZone = ShippingZone::create([
            'name'       => 'United States',
            'zone_type'  => 'country',
            'countries'  => ['US'],
            'is_active'  => true,
            'sort_order' => 1,
        ]);

        // Method 1: Custom Label / Ship on own account ($0.00)
        ShippingMethod::create([
            'shipping_zone_id' => $usZone->id,
            'method_type'      => 'custom_label',
            'display_name'     => 'Ship on my own/company shipping account (mention shipping account on order comments)',
            'is_active'        => true,
            'sort_order'       => 1,
            'custom_description' => 'I will provide the shipping label/others (Mentions the details on below comments box)',
        ]);

        // Method 2: Flat Rate for under 10 LBS ($10.00)
        ShippingMethod::create([
            'shipping_zone_id'   => $usZone->id,
            'method_type'        => 'flat_rate_weight',
            'display_name'       => 'Flat Rate for under 10 LBS',
            'is_active'          => true,
            'sort_order'         => 2,
            'weight_threshold'   => 10.00,
            'weight_rate_amount' => 10.00,
        ]);

        // Method 3: FedEx Real-time Rates
        $usFedexMethod = ShippingMethod::create([
            'shipping_zone_id' => $usZone->id,
            'method_type'      => 'fedex_realtime',
            'display_name'     => 'FedEx',
            'is_active'        => true,
            'sort_order'       => 3,
        ]);

        // FedEx Configuration for US zone
        FedexConfiguration::create([
            'shipping_method_id'  => $usFedexMethod->id,
            'client_id'           => 'l7a19309358e7b4b44aff3d164473392ff',
            'client_secret'       => '9e226d83d051430387fa960eeb996fcd',
            'account_number'      => '740561073',
            'api_base_url'        => 'https://apis-sandbox.fedex.com',
            'display_name'        => 'FedEx',
            'dropoff_type'        => 'DROP_BOX',
            'packaging_type'      => 'YOUR_PACKAGING',
            'packing_method'      => 'ALL_IN_ONE',
            'rate_type'           => 'LIST',
            'include_package_value' => true,
            'destination_type'    => 'COMMERCIAL',
            'enabled_services'    => [
                'FEDEX_2_DAY',
                'FEDEX_EXPRESS_SAVER',
                'FEDEX_GROUND',
                'FIRST_OVERNIGHT',
                'STANDARD_OVERNIGHT',
                'PRIORITY_OVERNIGHT',
            ],
            'is_connected'        => true,
        ]);

        // ═══════════════════════════════════════════
        // Zone 2: Rest of the World
        // ═══════════════════════════════════════════
        $rowZone = ShippingZone::create([
            'name'       => 'Rest of the World',
            'zone_type'  => 'country',
            'countries'  => ['*'],  // wildcard = all countries
            'is_active'  => true,
            'sort_order' => 2,
        ]);

        // Method: Custom Label (for international — customer provides label)
        ShippingMethod::create([
            'shipping_zone_id'   => $rowZone->id,
            'method_type'        => 'custom_label',
            'display_name'       => 'FedEx - I will provide the shipping label/others',
            'is_active'          => true,
            'sort_order'         => 1,
            'custom_description' => 'I will provide the shipping label/others (Mentions the details on below comments box)',
        ]);

        $this->command->info('✅ Shipping zones, methods, and FedEx configuration seeded successfully!');
    }
}
