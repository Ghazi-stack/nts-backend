<?php
if (!function_exists('current_store_id')) {
    function current_store_id()
    {
        $store = current_store_registry();

        // Legacy stores: preserve existing store_id filtering.
        if ($store && !empty($store->legacy_store_id)) {
            return $store->legacy_store_id;
        }

        // New stores: use the tenant's own store ID, if available.
        if ($store && isset($store->id)) {
            return $store->id;
        }

        return request()->header('storeId');
    }
}

if (!function_exists('current_store_hash')) {
    function current_store_hash(): ?string
    {
        $storeHash = request()->attributes->get('store_hash');

        return is_string($storeHash) ? $storeHash : null;
    }
}

if (!function_exists('current_store_registry')) {
    function current_store_registry(): ?object
    {
        $store = request()->attributes->get('resolved_store');

        return is_object($store) ? $store : null;
    }
}