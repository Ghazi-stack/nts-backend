<?php

if (!function_exists('current_store_id')) {
    function current_store_id() {
        return session('current_store_id');
    }
}
