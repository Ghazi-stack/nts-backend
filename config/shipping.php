<?php

/**
 * ═══════════════════════════════════════════════════════════════
 * SHIPPING CONFIGURATION
 * ═══════════════════════════════════════════════════════════════
 *
 * Add this file as config/shipping.php
 *
 * Then add these keys to your .env file:
 *
 * SHIPPING_ORIGIN_POSTAL_CODE=33139
 * SHIPPING_ORIGIN_COUNTRY_CODE=US
 * SHIPPING_ORIGIN_STATE=FL
 * SHIPPING_ORIGIN_CITY="Miami Beach"
 *
 * ═══════════════════════════════════════════════════════════════
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Store Origin Address (Warehouse / Fulfillment Center)
    |--------------------------------------------------------------------------
    |
    | This is used as the "ship from" address for all FedEx rate calculations.
    | Set these in your .env file.
    |
    */

    // FedEx credentials
    'fedex_client_id'      => env('FEDEX_CLIENT_ID'),
    'fedex_secret_key'     => env('FEDEX_SECRET_KEY'),
    'fedex_account_number' => env('FEDEX_ACCOUNT_NUMBER'),
    'fedex_api_base_url'   => env('FEDEX_API_BASE_URL', 'https://apis-sandbox.fedex.com'),

];
