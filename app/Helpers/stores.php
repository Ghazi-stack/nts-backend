<?php
function current_store_id()
{
    $tokenName = auth()->user()?->currentAccessToken()?->name;

    if ($tokenName && str_starts_with($tokenName, 'StoreToken-')) {
        return (int) str_replace('StoreToken-', '', $tokenName);
    }

    return null;
}