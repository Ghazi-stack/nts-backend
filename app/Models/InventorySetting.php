<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventorySetting extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'store_id',
        'product_no_longer_sold',
        'option_no_longer_sold',
        'update_stock_levels',
        'auto_adjust_stock_on_refund_cancel',
        'stock_level_display',
        'out_of_stock_message',
        'show_oos_on_listing',
        'show_quantity_on_backorder',
        'show_backorder_message',
        'backorder_messages',
        'show_quantity_on_hand',
        'show_backorder_availability_prompt',
        'more_available_for_backorder',
        'show_shipping_expectation',
        'shipping_expectation_message',
        'low_stock_email',
        'out_of_stock_email',
    ];

    protected $casts = [
        'auto_adjust_stock_on_refund_cancel'   => 'boolean',
        'show_oos_on_listing'                  => 'boolean',
        'show_quantity_on_backorder'           => 'boolean',
        'show_backorder_message'               => 'boolean',
        'show_quantity_on_hand'                => 'boolean',
        'show_backorder_availability_prompt'   => 'boolean',
        'show_shipping_expectation'            => 'boolean',
        'backorder_messages'                   => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
