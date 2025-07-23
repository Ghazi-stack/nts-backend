<?php
function current_store_id()
{
    return request()->header('storeId');
}